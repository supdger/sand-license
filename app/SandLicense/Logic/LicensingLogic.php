<?php

declare(strict_types=1);

namespace app\SandLicense\Logic;

use app\SandLicense\Security\Challenge;
use app\SandLicense\Security\InstallationProof;
use app\SandLicense\Security\LeaseToken;
use think\facade\Db;

/** The single desktop business path; all writes use the host's existing Think transaction layer. */
final class LicensingLogic
{
    public function __construct(
        private readonly ?CodeLogic $codes,
        private readonly ?LeaseToken $tokens,
        private readonly InstallationProof $proofs,
        private readonly string $configuredOrigin,
    ) {}

    public function challenge(array $input, int $now): array
    {
        $purpose = Values::text($input['purpose'] ?? null, '用途', 20);
        if (!in_array($purpose, ['redeem', 'renew', 'current', 'release', 'enroll', 'ticket'], true)) {
            Values::fail('SAND_LICENSE_INPUT_INVALID', '挑战用途不正确');
        }
        $product = ProductLogic::byCode(Values::text($input['product_code'] ?? null, '产品代码', 80), false, $purpose === 'release');
        $key = Values::json($input['installation_public_key'] ?? []);
        $thumbprint = InstallationProof::thumbprint($key);
        $issued = Challenge::issue($now);
        Db::table('sand_license_challenge')->insert([
            'product_id' => $product['id'], 'purpose' => $purpose, 'installation_key_thumbprint' => $thumbprint,
            'secret_hash' => $issued['secret_hash'], 'state' => 'issued',
            'expire_time' => Values::sqlTime($issued['expire_time']), 'create_time' => Values::sqlTime($now),
        ]);
        return ['challenge' => $issued['nonce'], 'expire_time' => Values::iso($issued['expire_time']), 'server_time' => Values::iso($now)];
    }

    public function redeem(array $input, array $request, int $now): array
    {
        $requestId = Values::requestId($input);
        $secret = Values::text($input['code'] ?? null, '卡密', 256);
        $codes = $this->codes();
        return Db::transaction(function () use ($input, $request, $now, $requestId, $secret, $codes): array {
            $product = ProductLogic::byCode(Values::text($input['product_code'] ?? null, '产品代码', 80), true);
            $key = Values::json($input['installation_public_key'] ?? []);
            $this->verifyProof($product, $key, 'redeem', $input, $request, $now);
            $digest = $codes->hashSecret($secret);
            $hint = Db::table('sand_license_redemption_code')->where('product_id', $product['id'])->where('secret_hash', $digest)->find();
            if (!is_array($hint)) Values::fail('SAND_LICENSE_CODE_INVALID', '卡密不可用');
            // Product is locked before any FK-bearing write. Claim-linked paths
            // share product -> source -> claim -> code -> entitlement with fulfillment.
            $claim = null;
            $source = null;
            if ($hint['claim_id'] !== null) {
                $claimHint = Db::table('sand_license_claim')->where('id', $hint['claim_id'])->find();
                if (!is_array($claimHint)) Values::fail('SAND_LICENSE_CODE_INVALID', '卡密不可用');
                $source = Db::table('sand_license_fulfillment')->where('id', $claimHint['fulfillment_id'])
                    ->where('product_id', $product['id'])->lock(true)->find();
                if (!is_array($source)) Values::fail('SAND_LICENSE_CODE_INVALID', '卡密不可用');
                $claim = Db::table('sand_license_claim')->where('id', $hint['claim_id'])->lock(true)->find();
                if (!is_array($claim)) Values::fail('SAND_LICENSE_CODE_INVALID', '卡密不可用');
            }
            $code = Db::table('sand_license_redemption_code')->where('id', $hint['id'])
                ->where('product_id', $product['id'])->where('secret_hash', $digest)->lock(true)->find();
            if (!is_array($code)) Values::fail('SAND_LICENSE_CODE_INVALID', '卡密不可用');
            if ($claim !== null) CodeLogic::assertClaimBinding($source, $claim, $code, (string) $product['id']);
            elseif ($code['claim_id'] !== null) Values::fail('SAND_LICENSE_CODE_INVALID', '卡密不可用');
            $business = ['code_hash' => $digest, 'installation_key_thumbprint' => InstallationProof::thumbprint($key)];
            $retry = Records::retry($product['id'], 'redemption', $requestId, $business);
            if ($retry !== null) {
                $this->assertRetryActive($retry, $product, $key, $now);
                return $retry;
            }
            CodeLogic::assertReissuable($code, $now);
            $plan = Db::table('sand_license_plan')->where('id', $code['plan_id'])->where('product_id', $product['id'])->find();
            if (!is_array($plan) || $plan['state'] !== 'published' || (int) $plan['status'] !== 1) Values::fail('SAND_LICENSE_CODE_INVALID', '卡密不可用');
            $snapshot = PlanLogic::snapshot($plan);
            if ($snapshot['kind'] !== 'desktop') Values::fail('SAND_LICENSE_CODE_INVALID', '卡密不可用');
            $expire = PlanLogic::expires($snapshot, $now);
            $entitlementId = Db::table('sand_license_entitlement')->insertGetId([
                'product_id' => $product['id'], 'plan_snapshot' => Values::encode($snapshot), 'kind' => 'desktop',
                'state' => 'active', 'start_time' => Values::sqlTime($now), 'expire_time' => Values::sqlTime($expire),
                'seat_limit' => $snapshot['seat_limit'], 'create_time' => Values::sqlTime($now), 'update_time' => Values::sqlTime($now),
            ]);
            $entitlement = Db::table('sand_license_entitlement')->where('id', $entitlementId)->lock(true)->find();
            Db::table('sand_license_grant')->insert([
                'entitlement_id' => $entitlementId, 'fulfillment_id' => $claim['fulfillment_id'] ?? null,
                'source_unit_no' => $claim['unit_no'] ?? 1, 'source_code' => 'redemption:' . $code['id'],
                'start_time' => Values::sqlTime($now), 'expire_time' => Values::sqlTime($expire), 'state' => 'active',
                'create_time' => Values::sqlTime($now), 'update_time' => Values::sqlTime($now),
            ]);
            $activation = $this->registerLocked($entitlement, $key, (string) ($input['name'] ?? ''), $now);
            $this->consumeProof($product, $key, 'redeem', $input, $request, $now);
            Db::table('sand_license_redemption_code')->where('id', $code['id'])->update([
                'state' => 'redeemed', 'entitlement_id' => $entitlementId, 'update_time' => Values::sqlTime($now),
            ]);
            if ($claim !== null) Db::table('sand_license_claim')->where('id', $claim['id'])->update(['state' => 'redeemed', 'update_time' => Values::sqlTime($now)]);
            $result = $this->leaseLocked($product, $entitlement, $activation, $requestId, $now);
            Records::remember($product['id'], 'redemption', $requestId, $business, $result, $now);
            Records::event($product['id'], 'code.redeem', 'entitlement', $entitlementId, 'installation:' . $activation['id'], $requestId, $now);
            return $result;
        });
    }

    public function renew(array $input, array $request, int $now): array
    {
        return $this->onActivation($input, $request, 'renew', $now, function (array $product, array $entitlement, array $activation, string $requestId) use ($now): array {
            return $this->leaseLocked($product, $entitlement, $activation, $requestId, $now);
        });
    }

    public function current(array $input, array $request, int $now): array
    {
        return $this->onActivation($input, $request, 'current', $now, function (array $product, array $entitlement, array $activation, string $requestId) use ($now): array {
            $snapshot = Values::json($entitlement['plan_snapshot']);
            $used = ActivationLogic::occupied(Db::table('sand_license_activation')->where('entitlement_id', $entitlement['id'])->select()->toArray(), $now);
            return $this->result($entitlement, $activation, $requestId, $now) + [
                'features' => $snapshot['features'], 'plan_name' => $snapshot['name'] ?? $snapshot['code'],
                'seat_limit' => (int) $entitlement['seat_limit'], 'used_seats' => $used,
            ];
        });
    }

    public function release(array $input, array $request, int $now): array
    {
        return $this->onActivation($input, $request, 'release', $now, function (array $product, array $entitlement, array $activation, string $requestId) use ($input, $now): array {
            return $this->releaseLocked($product, $entitlement, $activation, $requestId, $now, Values::boolean($input['issue_enrollment_ticket'] ?? false, '是否签发换机资格'), 'installation:' . $activation['id']);
        });
    }

    public function reset(array $input, array $scope, int $now): array
    {
        $requestId = Values::requestId($input);
        $reason = Values::text($input['reason'] ?? null, '重置原因');
        $issueTicket = Values::boolean($input['issue_enrollment_ticket'] ?? false, '是否签发换机资格');
        return Db::transaction(function () use ($input, $scope, $now, $requestId, $reason, $issueTicket): array {
            [$product, $entitlement, $activation] = $this->lockActivation(Values::id($input['activation_id'] ?? null), $scope);
            $business = ['activation_id' => (string) $activation['id'], 'reason' => $reason, 'issue_enrollment_ticket' => $issueTicket];
            $retry = Records::retry($product['id'], 'activation.reset', $requestId, $business);
            if ($retry !== null) return $retry;
            LeaseLogic::assertActive($entitlement, null, $now);
            if ($activation['state'] !== 'active') Values::fail('SAND_LICENSE_ACTIVATION_UNAVAILABLE', '该设备已经释放或撤销');
            $result = $this->releaseLocked($product, $entitlement, $activation, $requestId, $now, $issueTicket, (string) ($scope['actor_ref'] ?? 'admin'));
            Records::remember($product['id'], 'activation.reset', $requestId, $business, $result, $now);
            Records::event($product['id'], 'activation.reset', 'activation', $activation['id'], (string) ($scope['actor_ref'] ?? 'admin'), $requestId, $now, ['reason' => $reason]);
            return $result;
        });
    }

    public function ticket(array $input, array $request, int $now): array
    {
        return $this->onActivation($input, $request, 'ticket', $now, function (array $product, array $entitlement, array $activation, string $requestId) use ($input, $now): array {
            if (isset($input['reissue_ticket_id'])) {
                $ticket = $this->revokeUnusedTicketLocked($entitlement, Values::id($input['reissue_ticket_id']), $now, $activation);
                return $this->result($entitlement, $activation, $requestId, $now)
                    + $this->ticketLocked($entitlement, $ticket['source_activation_id'] !== null ? $activation : null, Values::time($ticket['available_time']), $now);
            }
            ActivationLogic::assertSeat(Db::table('sand_license_activation')->where('entitlement_id', $entitlement['id'])->select()->toArray(), (int) $entitlement['seat_limit'], $now);
            return $this->result($entitlement, $activation, $requestId, $now) + $this->ticketLocked($entitlement, null, $now, $now);
        });
    }

    public function adminTicket(array $input, array $scope, int $now): array
    {
        $requestId = Values::requestId($input);
        $reason = Values::text($input['reason'] ?? null, '签发原因');
        return Db::transaction(function () use ($input, $scope, $now, $requestId, $reason): array {
            $hint = Db::table('sand_license_entitlement')->where('id', Values::id($input['entitlement_id'] ?? null))->find();
            if (!is_array($hint)) Values::fail('SAND_LICENSE_RESOURCE_UNAVAILABLE', '权益不存在或不可访问');
            $product = ProductLogic::scoped((string) $hint['product_id'], $scope, true);
            ProductLogic::assertPublished($product);
            $entitlement = Db::table('sand_license_entitlement')->where('id', $hint['id'])->lock(true)->find();
            LeaseLogic::assertActive($entitlement, null, $now);
            if ($entitlement['kind'] !== 'desktop') Values::fail('SAND_LICENSE_ACTION_INVALID', '会员权益无需安装设备');
            $business = ['entitlement_id' => (string) $entitlement['id'], 'reissue_ticket_id' => $input['reissue_ticket_id'] ?? null, 'reason' => $reason];
            $retry = Records::retry($product['id'], 'ticket.admin', $requestId, $business);
            if ($retry !== null) return $retry;
            $source = null;
            $available = $now;
            if (isset($input['reissue_ticket_id'])) {
                $old = $this->revokeUnusedTicketLocked($entitlement, Values::id($input['reissue_ticket_id']), $now);
                $available = max($now, Values::time($old['available_time']));
                if ($old['source_activation_id'] !== null) $source = ['id' => $old['source_activation_id']];
            } else {
                ActivationLogic::assertSeat(Db::table('sand_license_activation')->where('entitlement_id', $entitlement['id'])->select()->toArray(), (int) $entitlement['seat_limit'], $now);
            }
            $result = ['state' => 'issued', 'entitlement_id' => (string) $entitlement['id'], 'request_id' => $requestId, 'server_time' => Values::iso($now)]
                + $this->ticketLocked($entitlement, $source, $available, $now);
            Records::remember($product['id'], 'ticket.admin', $requestId, $business, $result, $now);
            Records::event($product['id'], 'ticket.issue', 'enrollment_ticket', $result['ticket_id'], (string) ($scope['actor_ref'] ?? 'admin'), $requestId, $now, ['reason' => $reason]);
            return $result;
        });
    }

    public function enroll(array $input, array $request, int $now): array
    {
        $requestId = Values::requestId($input);
        $codes = $this->codes();
        return Db::transaction(function () use ($input, $request, $now, $requestId, $codes): array {
            $product = ProductLogic::byCode(Values::text($input['product_code'] ?? null, '产品代码', 80), true);
            $key = Values::json($input['installation_public_key'] ?? []);
            $this->verifyProof($product, $key, 'enroll', $input, $request, $now);
            $ticketHash = $codes->hashSecret(Values::text($input['enrollment_ticket'] ?? null, '设备资格', 256));
            $hint = Db::table('sand_license_enrollment_ticket')->where('secret_hash', $ticketHash)->find();
            if (!is_array($hint)) Values::fail('SAND_LICENSE_ENROLLMENT_INVALID', '设备激活资格不可用');
            $entitlement = Db::table('sand_license_entitlement')->where('id', $hint['entitlement_id'])->where('product_id', $product['id'])->lock(true)->find();
            if (!is_array($entitlement)) Values::fail('SAND_LICENSE_ENROLLMENT_INVALID', '设备激活资格不可用');
            $ticket = Db::table('sand_license_enrollment_ticket')->where('id', $hint['id'])->lock(true)->find();
            $business = ['ticket_hash' => $ticketHash, 'installation_key_thumbprint' => InstallationProof::thumbprint($key)];
            $retry = Records::retry($product['id'], 'activation.enroll', $requestId, $business);
            if ($retry !== null) {
                $this->assertRetryActive($retry, $product, $key, $now);
                return $retry;
            }
            if (!is_array($ticket)) Values::fail('SAND_LICENSE_ENROLLMENT_INVALID', '设备激活资格不可用');
            EnrollmentLogic::assertAvailable($ticket, $now);
            LeaseLogic::assertActive($entitlement, null, $now);
            $activation = $this->registerLocked($entitlement, $key, (string) ($input['name'] ?? ''), $now);
            $this->consumeProof($product, $key, 'enroll', $input, $request, $now);
            Db::table('sand_license_enrollment_ticket')->where('id', $ticket['id'])->update(['state' => 'consumed', 'update_time' => Values::sqlTime($now)]);
            $result = $this->leaseLocked($product, $entitlement, $activation, $requestId, $now);
            Records::remember($product['id'], 'activation.enroll', $requestId, $business, $result, $now);
            Records::event($product['id'], 'activation.enroll', 'activation', $activation['id'], 'installation:' . $activation['id'], $requestId, $now);
            return $result;
        });
    }

    private function onActivation(array $input, array $request, string $purpose, int $now, callable $action): array
    {
        $requestId = Values::requestId($input);
        return Db::transaction(function () use ($input, $request, $purpose, $now, $action, $requestId): array {
            $product = ProductLogic::byCode(Values::text($input['product_code'] ?? null, '产品代码', 80), true, $purpose === 'release');
            $hint = Db::table('sand_license_activation')->where('id', Values::id($input['activation_id'] ?? null))->find();
            if (!is_array($hint)) Values::fail('SAND_LICENSE_ACTIVATION_UNAVAILABLE', '设备许可不可用');
            $entitlement = Db::table('sand_license_entitlement')->where('id', $hint['entitlement_id'])->where('product_id', $product['id'])->lock(true)->find();
            if (!is_array($entitlement)) Values::fail('SAND_LICENSE_ACTIVATION_UNAVAILABLE', '设备许可不可用');
            $activation = Db::table('sand_license_activation')->where('id', $hint['id'])->lock(true)->find();
            $key = Values::json($activation['public_key']);
            $this->verifyProof($product, $key, $purpose, $input, $request, $now);
            $business = ['activation_id' => (string) $activation['id'], 'issue_enrollment_ticket' => Values::boolean($input['issue_enrollment_ticket'] ?? false, '是否签发换机资格'), 'reissue_ticket_id' => $input['reissue_ticket_id'] ?? null];
            if ($purpose === 'ticket' && isset($input['reissue_ticket_id'])) {
                LeaseLogic::assertActive($entitlement, null, $now);
                $ticket = Db::table('sand_license_enrollment_ticket')->where('id', Values::id($input['reissue_ticket_id']))->where('entitlement_id', $entitlement['id'])->lock(true)->find();
                if (!is_array($ticket)) Values::fail('SAND_LICENSE_ENROLLMENT_INVALID', '设备资格不可用');
                EnrollmentLogic::assertReissueAuthority($ticket, $activation);
            } elseif ($purpose !== 'release') LeaseLogic::assertActive($entitlement, $activation, $now);
            // Cached references never bypass current rights or installation state.
            $retry = Records::retry($product['id'], 'activation.' . $purpose, $requestId, $business);
            if ($retry !== null && $purpose !== 'current') return $retry;
            if ($purpose === 'release' && $activation['state'] !== 'active') Values::fail('SAND_LICENSE_ACTIVATION_UNAVAILABLE', '设备已经释放');
            $this->consumeProof($product, $key, $purpose, $input, $request, $now);
            $result = $action($product, $entitlement, $activation, $requestId);
            if ($retry === null) Records::remember($product['id'], 'activation.' . $purpose, $requestId, $business, $result, $now);
            return $result;
        });
    }

    private function lockActivation(string $id, array $scope): array
    {
        $hint = Db::table('sand_license_activation')->where('id', $id)->find();
        if (!is_array($hint)) Values::fail('SAND_LICENSE_RESOURCE_UNAVAILABLE', '设备不存在或不可访问');
        $entHint = Db::table('sand_license_entitlement')->where('id', $hint['entitlement_id'])->find();
        if (!is_array($entHint)) Values::fail('SAND_LICENSE_RESOURCE_UNAVAILABLE', '设备不存在或不可访问');
        $product = ProductLogic::scoped((string) $entHint['product_id'], $scope, true);
        $entitlement = Db::table('sand_license_entitlement')->where('id', $entHint['id'])->lock(true)->find();
        $activation = Db::table('sand_license_activation')->where('id', $id)->lock(true)->find();
        return [$product, $entitlement, $activation];
    }

    private function verifyProof(array $product, array $key, string $purpose, array $input, array $request, int $now): array
    {
        $nonce = Values::text($input['challenge'] ?? null, 'challenge', 100);
        $challenge = Db::table('sand_license_challenge')->where('product_id', $product['id'])->where('secret_hash', Challenge::digest($nonce))->find();
        if (!is_array($challenge) || $challenge['purpose'] !== $purpose || Values::time($challenge['expire_time']) <= $now
            || !hash_equals((string) $challenge['installation_key_thumbprint'], InstallationProof::thumbprint($key))) {
            Values::fail('SAND_LICENSE_CHALLENGE_INVALID', '挑战不可用，请重新获取');
        }
        $expectedMethod = 'POST';
        $paths = ['redeem' => '/redemptions', 'renew' => '/leases/renew', 'current' => '/entitlements/current', 'release' => '/activations/release', 'enroll' => '/activations/enroll', 'ticket' => '/enrollment-tickets'];
        $expectedUrl = rtrim($this->configuredOrigin, '/') . '/api/sand-license/v1' . $paths[$purpose];
        if (($request['method'] ?? '') !== $expectedMethod || ($request['configured_url'] ?? '') !== $expectedUrl) Values::fail('SAND_LICENSE_PROOF_INVALID', '许可请求路由不正确');
        return InstallationProof::verify(
            Values::text($request['proof'] ?? null, 'proof', 16000), $key, $expectedMethod, $expectedUrl,
            is_string($request['raw_body'] ?? null) ? $request['raw_body'] : '',
            $nonce, (string) $product['code'], $now,
        );
    }

    private function consumeProof(array $product, array $key, string $purpose, array $input, array $request, int $now): void
    {
        $claims = $this->verifyProof($product, $key, $purpose, $input, $request, $now);
        $challenge = Db::table('sand_license_challenge')->where('product_id', $product['id'])
            ->where('secret_hash', Challenge::digest($input['challenge']))->lock(true)->find();
        if (!is_array($challenge) || $challenge['state'] !== 'issued') Values::fail('SAND_LICENSE_PROOF_REPLAYED', '挑战已使用，请重新获取');
        $jtiHash = hash('sha256', $claims['jti']);
        if (Db::table('sand_license_challenge')->where('proof_jti_hash', $jtiHash)->find() !== null) Values::fail('SAND_LICENSE_PROOF_REPLAYED', '安装证明已使用');
        Db::table('sand_license_challenge')->where('id', $challenge['id'])->update([
            'state' => 'consumed', 'proof_jti_hash' => $jtiHash, 'consumed_time' => Values::sqlTime($now),
        ]);
    }

    private function registerLocked(array $entitlement, array $key, string $name, int $now): array
    {
        if (strlen($name) > 120 || preg_match('/[\x00-\x1f]/', $name)) Values::fail('SAND_LICENSE_INPUT_INVALID', '设备名称格式不正确');
        $thumbprint = InstallationProof::thumbprint($key);
        $activations = Db::table('sand_license_activation')->where('entitlement_id', $entitlement['id'])->select()->toArray();
        $existing = null;
        foreach ($activations as $row) if ($row['installation_key_thumbprint'] === $thumbprint) $existing = $row;
        if ($existing !== null && $existing['state'] === 'active') return $existing;
        ActivationLogic::assertSeat($activations, (int) $entitlement['seat_limit'], $now);
        $record = ['entitlement_id' => $entitlement['id'], 'installation_key_thumbprint' => $thumbprint,
            'public_key' => Values::encode(['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => $key['x']]), 'name' => $name,
            'state' => 'active', 'released_time' => null, 'seat_available_time' => null, 'update_time' => Values::sqlTime($now)];
        if ($existing !== null) {
            Db::table('sand_license_activation')->where('id', $existing['id'])->update($record);
            $record['id'] = $existing['id'];
        } else {
            $record['create_time'] = Values::sqlTime($now);
            $record['id'] = Db::table('sand_license_activation')->insertGetId($record);
        }
        return $record;
    }

    private function leaseLocked(array $product, array $entitlement, array $activation, string $requestId, int $now): array
    {
        LeaseLogic::assertActive($entitlement, $activation, $now);
        if ($this->tokens === null) Values::fail('SAND_LICENSE_CONFIG_REQUIRED', '请配置许可签名密钥后重试');
        $snapshot = Values::json($entitlement['plan_snapshot']);
        $jti = bin2hex(random_bytes(24));
        $claims = ['aud' => $product['audience'], 'sub' => (string) $entitlement['id'], 'entitlement_id' => (string) $entitlement['id'],
            'activation_id' => (string) $activation['id'], 'product_code' => $product['code'], 'plan_revision' => (int) $snapshot['revision'],
            'features' => $snapshot['features'], 'cnf' => ['jkt' => $activation['installation_key_thumbprint']], 'jti' => $jti];
        $token = $this->tokens->issue($claims, Values::time($entitlement['expire_time']), $now);
        $expire = LeaseLogic::expireTime($entitlement, $now);
        Db::table('sand_license_lease')->insert([
            'activation_id' => $activation['id'], 'jti_hash' => hash('sha256', $jti), 'kid' => $this->tokens->kid(),
            'state' => 'issued', 'issued_time' => Values::sqlTime($now), 'expire_time' => Values::sqlTime($expire),
        ]);
        Records::event($product['id'], 'lease.issue', 'activation', $activation['id'], 'installation:' . $activation['id'], $requestId, $now);
        return array_merge($this->result($entitlement, $activation, $requestId, $now), [
            'lease' => $token, 'lease_expire_time' => Values::iso($expire), 'renewal_after' => 300,
        ]);
    }

    private function releaseLocked(array $product, array $entitlement, array $activation, string $requestId, int $now, bool $ticket, string $actor): array
    {
        $leases = Db::table('sand_license_lease')->where('activation_id', $activation['id'])->lock(true)->select()->toArray();
        $available = ActivationLogic::releaseTime($leases, $now);
        Db::table('sand_license_activation')->where('id', $activation['id'])->update([
            'state' => 'released', 'released_time' => Values::sqlTime($now), 'seat_available_time' => Values::sqlTime($available),
            'update_time' => Values::sqlTime($now),
        ]);
        Db::table('sand_license_lease')->where('activation_id', $activation['id'])->where('state', 'issued')
            ->update(['state' => 'revoked', 'revoked_time' => Values::sqlTime($now)]);
        $result = array_merge($this->result($entitlement, $activation, $requestId, $now), ['state' => 'released', 'seat_available_time' => Values::iso($available)]);
        if ($ticket && $entitlement['state'] === 'active' && Values::time($entitlement['expire_time']) > $available) {
            $result += $this->ticketLocked($entitlement, $activation, $available, $now);
        } elseif ($ticket) {
            $result['ticket_unavailable_reason'] = '权益不可用或将在席位释放前到期，未生成换设备资格';
        }
        Records::event($product['id'], 'activation.release', 'activation', $activation['id'], $actor, $requestId, $now, ['seat_available_time' => Values::iso($available)]);
        return $result;
    }

    private function ticketLocked(array $entitlement, ?array $activation, int $available, int $now): array
    {
        $expire = EnrollmentLogic::expireTime($entitlement, $available);
        $secret = $this->codes()->generateSecret();
        $id = Db::table('sand_license_enrollment_ticket')->insertGetId([
            'entitlement_id' => $entitlement['id'], 'source_activation_id' => $activation['id'] ?? null,
            'secret_hash' => $this->codes()->hashSecret($secret), 'state' => 'issued', 'available_time' => Values::sqlTime($available),
            'expire_time' => Values::sqlTime($expire), 'create_time' => Values::sqlTime($now), 'update_time' => Values::sqlTime($now),
        ]);
        return ['ticket_id' => (string) $id, 'enrollment_ticket' => $secret, 'ticket_expire_time' => Values::iso($expire)];
    }

    private function revokeUnusedTicketLocked(array $entitlement, string $id, int $now, ?array $activation = null): array
    {
        $ticket = Db::table('sand_license_enrollment_ticket')->where('id', $id)->where('entitlement_id', $entitlement['id'])->lock(true)->find();
        if (!is_array($ticket)) Values::fail('SAND_LICENSE_ENROLLMENT_INVALID', '设备资格不可用');
        EnrollmentLogic::assertUnused($ticket, $now);
        if ($activation !== null) EnrollmentLogic::assertReissueAuthority($ticket, $activation);
        Db::table('sand_license_enrollment_ticket')->where('id', $id)->update(['state' => 'revoked', 'update_time' => Values::sqlTime($now)]);
        return $ticket;
    }

    private function assertRetryActive(array $retry, array $product, array $key, int $now): void
    {
        $entitlement = Db::table('sand_license_entitlement')->where('id', Values::id($retry['entitlement_id'] ?? null))
            ->where('product_id', $product['id'])->lock(true)->find();
        $activation = Db::table('sand_license_activation')->where('id', Values::id($retry['activation_id'] ?? null))
            ->where('entitlement_id', $retry['entitlement_id'])->where('installation_key_thumbprint', InstallationProof::thumbprint($key))->lock(true)->find();
        if (!is_array($entitlement) || !is_array($activation)) Values::fail('SAND_LICENSE_ACTIVATION_UNAVAILABLE', '设备许可不可用');
        LeaseLogic::assertActive($entitlement, $activation, $now);
    }

    private function result(array $entitlement, array $activation, string $requestId, int $now): array
    {
        return ['state' => 'active', 'entitlement_id' => (string) $entitlement['id'], 'activation_id' => (string) $activation['id'],
            'expire_time' => Values::iso(Values::time($entitlement['expire_time'])), 'request_id' => $requestId, 'server_time' => Values::iso($now)];
    }

    private function codes(): CodeLogic
    {
        if ($this->codes === null) Values::fail('SAND_LICENSE_CONFIG_REQUIRED', '请配置卡密摘要密钥后重试');
        return $this->codes;
    }
}
