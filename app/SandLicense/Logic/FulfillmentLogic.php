<?php

declare(strict_types=1);

namespace app\SandLicense\Logic;

use plugin\sandadmin\exception\ApiException;
use think\db\ConnectionInterface;
use think\facade\Db;

/** Commercial delivery and buyer claim state share PostgreSQL transactions. */
final class FulfillmentLogic
{
    public function __construct(
        private readonly CodeLogic $codes,
        private readonly MembershipLogic $memberships,
        private readonly int $claimTtlSeconds = 2592000,
    ) {
        if ($claimTtlSeconds < 3600 || $claimTtlSeconds > 31536000) {
            self::deny('SAND_LICENSE_CONFIG_INVALID', '领取期限须在1小时至365天之间');
        }
    }

    public function ingest(array $input, array $scope, int $now): array
    {
        self::validateEvent($input);
        if (!isset($scope['channel_code']) || !hash_equals((string) $scope['channel_code'], (string) $input['channel_code'])) {
            self::deny('SAND_LICENSE_SCOPE_DENIED', '履约渠道不在授权范围内', 401);
        }
        return Db::transaction(function (ConnectionInterface $connection) use ($input, $scope, $now): array {
            // Product is the first write lock, before any INSERT can acquire its
            // FK KEY SHARE lock. All delivery/redeem writers use this same order.
            $product = Db::table('sand_license_product')->where('id', $scope['product_id'] ?? 0)->lock(true)->find();
            MembershipLogic::assertProductScope($product ?: [], $scope, (string) $input['product_code']);
            $knownSource = Db::table('sand_license_fulfillment')->where('product_id', $product['id'])
                ->where('channel_code', $input['channel_code'])->where('order_item_id', $input['order_item_id'])
                ->where('payment_cycle_id', $input['payment_cycle_id'])->find();
            if ($knownSource) {
                // Existing purchases retain the frozen version after SKU edits
                // or a plan is withdrawn. Refunds cannot depend on current sales.
                $snapshot = Values::json($knownSource['plan_snapshot']);
            } else {
                $mapping = Db::table('sand_license_sku_mapping')->where('product_id', $product['id'])
                    ->where('channel_code', $input['channel_code'])->where('sku_code', $input['sku_code'])
                    ->where('status', 1)->whereNull('delete_time')->find();
                if (!$mapping) self::deny('SAND_LICENSE_SKU_INVALID', '商品尚未配置有效授权套餐');
                $plan = Db::table('sand_license_plan')->where('id', $mapping['plan_id'])->where('product_id', $product['id'])
                    ->where('state', 'published')->where('status', 1)->whereNull('delete_time')->find();
                if (!$plan) self::deny('SAND_LICENSE_PLAN_INVALID', '商品授权套餐不可用');
                $snapshot = self::planSnapshot($plan);
            }
            if ($snapshot['kind'] === 'membership') {
                MembershipLogic::validatePeriod($input);
                if (!isset($scope['subject_code']) || !hash_equals((string) $scope['subject_code'], (string) $input['subject_code'])) {
                    self::deny('SAND_LICENSE_SCOPE_DENIED', '会员主体不在授权范围内', 401);
                }
            }
            $facts = self::sourceFacts($input, $snapshot);
            $sourceHash = self::digest($facts);
            $eventHash = self::digest($facts + ['event_id' => $input['event_id'], 'event_type' => $input['event_type'], 'refund_kind' => $input['refund_kind'] ?? null]);
            $time = gmdate('Y-m-d H:i:s', $now);
            Db::query(
                'INSERT INTO sand_license_fulfillment'
                . ' (product_id,channel_code,order_item_id,payment_cycle_id,order_id,quantity,sku_code,plan_snapshot,subject_code,cycle_start_time,cycle_expire_time,state,request_hash,create_time,update_time)'
                . ' VALUES (:product,:channel,:item,:cycle,:order_id,:quantity,:sku,CAST(:plan AS jsonb),:subject,:cycle_start,:cycle_expire,:state,:hash,:created,:updated)'
                . ' ON CONFLICT (product_id,channel_code,order_item_id,payment_cycle_id) DO NOTHING RETURNING id',
                ['product' => $product['id'], 'channel' => $input['channel_code'], 'item' => $input['order_item_id'],
                    'cycle' => $input['payment_cycle_id'], 'order_id' => $input['order_id'], 'quantity' => $input['quantity'],
                    'sku' => $input['sku_code'], 'plan' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'subject' => $facts['subject_code'], 'cycle_start' => $facts['cycle_start_time'],
                    'cycle_expire' => $facts['cycle_expire_time'], 'state' => 'pending', 'hash' => $sourceHash,
                    'created' => $time, 'updated' => $time], true
            );
            $source = Db::table('sand_license_fulfillment')->where('product_id', $product['id'])
                ->where('channel_code', $input['channel_code'])->where('order_item_id', $input['order_item_id'])
                ->where('payment_cycle_id', $input['payment_cycle_id'])->lock(true)->find();
            if (!$source) self::deny('SAND_LICENSE_SOURCE_INVALID', '履约来源不可用');
            self::assertSameSource((string) $source['request_hash'], $sourceHash);
            if ($source['state'] === 'pending' && $input['event_type'] === 'paid') {
                // A new grant must use current sales permission, unlike a refund
                // or replay of an existing paid source. Re-read under row locks.
                self::assertNewSaleAllowed($product, (string) $source['state'], (string) $input['event_type']);
                $frozen = Values::json($source['plan_snapshot']);
                $salePlan = Db::table('sand_license_plan')->where('product_id', $product['id'])
                    ->where('code', $frozen['code'])->where('revision', $frozen['revision'])->lock(true)->find();
                if (!$salePlan || $salePlan['state'] !== 'published' || (int) $salePlan['status'] !== 1
                    || !empty($salePlan['delete_time'])
                    || !hash_equals(self::digest(self::planSnapshot($salePlan)), self::digest($frozen))) {
                    self::deny('SAND_LICENSE_PLAN_INVALID', '商品授权套餐不可用');
                }
            }
            $eventRows = Db::query(
                'INSERT INTO sand_license_fulfillment_event'
                . ' (product_id,channel_code,event_id,fulfillment_id,event_type,request_hash,result_ref,create_time)'
                . ' VALUES (:product,:channel,:event,:source,:type,:hash,:result,:created)'
                . ' ON CONFLICT (product_id,channel_code,event_id) DO NOTHING RETURNING id',
                ['product' => $product['id'], 'channel' => $input['channel_code'], 'event' => $input['event_id'],
                    'source' => $source['id'], 'type' => $input['event_type'], 'hash' => $eventHash,
                    'result' => Values::encode(['fulfillment_id' => (string) $source['id']]), 'created' => $time], true
            );
            if ($eventRows === []) {
                $event = Db::table('sand_license_fulfillment_event')->where('product_id', $product['id'])
                    ->where('channel_code', $input['channel_code'])->where('event_id', $input['event_id'])->find();
                if (!$event || !hash_equals((string) $event['request_hash'], $eventHash)) {
                    self::deny('SAND_LICENSE_EVENT_CONFLICT', '同一履约事件内容不一致');
                }
                return $this->sourceResult($source, (string) $input['request_id'], $now);
            }
            $next = self::nextState((string) $source['state'], (string) $input['event_type']);
            $wasPaid = $source['state'] === 'paid';
            if ($next !== $source['state']) {
                Db::table('sand_license_fulfillment')->where('id', $source['id'])->update(['state' => $next, 'update_time' => $time]);
                $source['state'] = $next;
            }
            $credentials = [];
            if ($input['event_type'] === 'cancelled') {
                $this->memberships->cancelSourceLocked($source, $now);
            } elseif ($next === 'paid' && !$wasPaid) {
                if ($snapshot['kind'] === 'membership') {
                    $this->memberships->applyPaidLocked($source, $snapshot, $now);
                } else {
                    for ($unit = 1; $unit <= (int) $source['quantity']; ++$unit) {
                        $secret = $this->codes->generateSecret();
                        $claimId = Db::table('sand_license_claim')->insertGetId([
                            'fulfillment_id' => $source['id'], 'unit_no' => $unit,
                            'secret_hash' => $this->codes->hashSecret($secret), 'state' => 'ready',
                            'expire_time' => gmdate('Y-m-d H:i:s', $now + $this->claimTtlSeconds), 'create_time' => $time, 'update_time' => $time,
                        ]);
                        $credentials[] = ['claim_id' => (string) $claimId, 'claim_credential' => $secret];
                    }
                }
            } elseif (in_array($next, ['refunded', 'chargeback'], true)) {
                $this->revokeClaimsLocked($source, $now);
                $this->memberships->revokeSourceLocked($source, $now);
            }
            $result = $this->sourceResult($source, (string) $input['request_id'], $now);
            Records::event($product['id'], 'fulfillment.' . $input['event_type'], 'fulfillment', $source['id'],
                (string) ($scope['actor_ref'] ?? ''), (string) $input['request_id'], $now);
            if ($credentials !== []) $result['claim_credentials'] = $credentials;
            return $result;
        });
    }

    public function read(string $fulfillmentId, array $scope, int $now): array
    {
        self::assertId($fulfillmentId);
        $source = Db::table('sand_license_fulfillment')->where('id', $fulfillmentId)->find();
        if (!$source) self::deny('SAND_LICENSE_SCOPE_DENIED', '履约记录不在授权范围内', 401);
        $product = Db::table('sand_license_product')->where('id', $source['product_id'])->find();
        MembershipLogic::assertProductScope($product ?: [], $scope, (string) ($product['code'] ?? ''));
        if (($scope['channel_code'] ?? null) !== $source['channel_code']) self::deny('SAND_LICENSE_SCOPE_DENIED', '履约渠道不在授权范围内', 401);
        if (!empty($source['subject_code']) && ($scope['subject_code'] ?? null) !== $source['subject_code']) {
            self::deny('SAND_LICENSE_SCOPE_DENIED', '会员主体不在授权范围内', 401);
        }
        return $this->sourceResult($source, (string) ($scope['request_id'] ?? ''), $now);
    }

    /** Read-only status never binds the buyer session or generates a code. */
    public function status(string $claimId, array $input, int $now): array
    {
        self::assertId($claimId);
        $claim = Db::table('sand_license_claim')->where('id', $claimId)->find();
        $this->assertClaimAuthorization($claim ?: [], $input, $now, false);
        return $this->claimResult($claim, (string) $input['request_id'], $now);
    }

    public function claim(string $claimId, array $input, int $now): array
    {
        return $this->claimAction($claimId, $input, $now, false);
    }

    public function reissue(string $claimId, array $input, int $now): array
    {
        return $this->claimAction($claimId, $input, $now, true);
    }

    /** Trusted business recovery only; never relies on an order number as authority. */
    public function refreshClaimCredential(string $claimId, array $input, array $scope, int $now): array
    {
        self::assertId($claimId);
        $requestId = Values::requestId($input);
        return Db::transaction(function (ConnectionInterface $connection) use ($claimId, $scope, $requestId, $now): array {
            [$product, $source, $claim] = $this->lockClaimContext($claimId);
            MembershipLogic::assertProductScope($product ?: [], $scope, (string) ($product['code'] ?? ''));
            if (($scope['channel_code'] ?? null) !== ($source['channel_code'] ?? null)
                || (!empty($source['subject_code']) && ($scope['subject_code'] ?? null) !== $source['subject_code'])) {
                self::deny('SAND_LICENSE_SCOPE_DENIED', '购买来源不在授权范围内', 401);
            }
            $code = empty($claim['redemption_code_id']) ? null
                : Db::table('sand_license_redemption_code')->where('id', $claim['redemption_code_id'])->lock(true)->find();
            self::assertClaimBindings($product, $source, $claim, $code ?: []);
            $state = self::claimState($claim, $source, $code ?: [], $now);
            if (!in_array($state, ['ready', 'claimed'], true) || $source['state'] !== 'paid') {
                self::deny('SAND_LICENSE_CLAIM_UNAVAILABLE', '已兑换、退款或过期的资格不能重签领取凭证');
            }
            $scopeName = 'claim.credential.reissue:' . $claimId;
            $business = ['claim_id' => $claimId];
            if (Records::retry($product['id'], $scopeName, $requestId, $business) !== null) {
                return $this->claimResult($claim, $requestId, $now);
            }
            $secret = $this->codes->generateSecret();
            Db::table('sand_license_claim')->where('id', $claimId)->update([
                'secret_hash' => $this->codes->hashSecret($secret), 'session_key_hash' => null,
                'update_time' => gmdate('Y-m-d H:i:s', $now),
            ]);
            $result = $this->claimResult($claim, $requestId, $now);
            Records::remember($product['id'], $scopeName, $requestId, $business, $result, $now);
            Records::event($product['id'], 'claim.credential.reissue', 'claim', $claimId,
                (string) ($scope['actor_ref'] ?? ''), $requestId, $now);
            $result['claim_credential'] = $secret;
            return $result;
        });
    }

    private function claimAction(string $claimId, array $input, int $now, bool $reissue): array
    {
        self::assertId($claimId);
        return Db::transaction(function (ConnectionInterface $connection) use ($claimId, $input, $now, $reissue): array {
            [$product, $source, $claim] = $this->lockClaimContext($claimId);
            $this->assertClaimAuthorization($claim ?: [], $input, $now, true);
            if (!$source || $source['state'] !== 'paid') self::deny('SAND_LICENSE_CLAIM_UNAVAILABLE', '购买来源已失效，无法领取');
            $snapshot = Values::json($source['plan_snapshot']);
            $plan = Db::table('sand_license_plan')->where('product_id', $product['id'])
                ->where('code', $snapshot['code'])->where('revision', $snapshot['revision'])->find();
            if (!$plan || !hash_equals(self::digest(self::planSnapshot($plan)), self::digest($snapshot))) {
                self::deny('SAND_LICENSE_PLAN_INVALID', '已购套餐版本不可用，请联系购买渠道');
            }
            $currentCode = empty($claim['redemption_code_id']) ? null
                : Db::table('sand_license_redemption_code')->where('id', $claim['redemption_code_id'])->lock(true)->find();
            self::assertClaimBindings($product, $source, $claim, $currentCode ?: []);
            if (($currentCode['state'] ?? '') === 'redeemed' || $claim['state'] === 'redeemed') {
                $claim['state'] = 'redeemed';
                if ($reissue) self::deny('SAND_LICENSE_ALREADY_REDEEMED', '卡密已兑换，不能重新签发');
                return $this->claimResult($claim, (string) $input['request_id'], $now);
            }
            $scopeName = 'claim:' . $claimId . ':' . ($reissue ? 'reissue' : 'claim');
            $business = ['claim_id' => $claimId, 'session_hash' => $this->codes->hashSecret((string) $input['session_key'])];
            $retry = Records::retry($product['id'], $scopeName, (string) $input['request_id'], $business);
            if ($retry !== null) {
                return $this->claimResult($claim, (string) $input['request_id'], $now);
            }
            if (!$reissue && $claim['state'] === 'claimed') {
                return $this->claimResult($claim, (string) $input['request_id'], $now);
            }
            if ($reissue && $claim['state'] !== 'claimed') self::deny('SAND_LICENSE_CLAIM_STATE_INVALID', '尚未领取，请先领取卡密');
            if ($reissue && (!$currentCode || $currentCode['state'] !== 'issued')) self::deny('SAND_LICENSE_CLAIM_STATE_INVALID', '原卡密状态不允许重新签发');
            if ($currentCode) {
                Db::table('sand_license_redemption_code')->where('id', $currentCode['id'])->update([
                    'state' => 'revoked', 'update_time' => gmdate('Y-m-d H:i:s', $now),
                ]);
            }
            $issued = $this->codes->issueForClaimLocked($claim, $product, $plan, $now);
            Db::table('sand_license_claim')->where('id', $claimId)->update([
                'state' => 'claimed', 'redemption_code_id' => $issued['code_id'],
                'session_key_hash' => $this->codes->hashSecret((string) $input['session_key']),
                'update_time' => gmdate('Y-m-d H:i:s', $now),
            ]);
            $claim['state'] = 'claimed';
            $claim['redemption_code_id'] = $issued['code_id'];
            $result = $this->claimResult($claim, (string) $input['request_id'], $now);
            Records::remember($product['id'], $scopeName, (string) $input['request_id'], $business, $result, $now);
            Records::event($product['id'], $reissue ? 'claim.reissue' : 'claim.claim', 'claim', $claimId,
                'buyer-session', (string) $input['request_id'], $now);
            $result['code'] = $issued['code'];
            $result['prefix'] = $issued['prefix'];
            return $result;
        });
    }

    /** Hints select IDs only; locked rows must retain the original binding. */
    private function lockClaimContext(string $claimId): array
    {
        $claimHint = Db::table('sand_license_claim')->where('id', $claimId)->find();
        $sourceHint = $claimHint ? Db::table('sand_license_fulfillment')->where('id', $claimHint['fulfillment_id'])->find() : null;
        if (!$claimHint || !$sourceHint) self::deny('SAND_LICENSE_CLAIM_DENIED', '领取凭证无效或已失效', 401);
        $product = Db::table('sand_license_product')->where('id', $sourceHint['product_id'])->lock(true)->find();
        if (!$product || !empty($product['delete_time'])) self::deny('SAND_LICENSE_CLAIM_UNAVAILABLE', '产品暂不可领取');
        $source = Db::table('sand_license_fulfillment')->where('id', $sourceHint['id'])->lock(true)->find();
        $claim = Db::table('sand_license_claim')->where('id', $claimId)->lock(true)->find();
        self::assertClaimBindings($product, $source ?: [], $claim ?: []);
        return [$product, $source, $claim];
    }

    /** FK existence alone does not enforce cross-row product/source ownership. */
    public static function assertClaimBindings(array $product, array $source, array $claim, ?array $code = null): void
    {
        if (empty($product['id']) || empty($source['id']) || empty($claim['id'])
            || (string) ($source['product_id'] ?? '') !== (string) $product['id']
            || (string) ($claim['fulfillment_id'] ?? '') !== (string) $source['id']
            || ($code !== null && !empty($claim['redemption_code_id']) && (
                (string) ($code['id'] ?? '') !== (string) $claim['redemption_code_id']
                || (string) ($code['product_id'] ?? '') !== (string) $product['id']
                || (string) ($code['claim_id'] ?? '') !== (string) $claim['id']
            ))) {
            self::deny('SAND_LICENSE_CLAIM_DENIED', '领取记录绑定无效或已变更', 401);
        }
    }

    private function assertClaimAuthorization(array $claim, array $input, int $now, bool $write): void
    {
        Values::requestId($input);
        foreach (['session_key', 'claim_credential'] as $field) {
            if (!is_string($input[$field] ?? null) || $input[$field] === '' || strlen($input[$field]) > 256) {
                self::deny('SAND_LICENSE_CLAIM_DENIED', '领取凭证无效或已失效', 401);
            }
        }
        if ((string) ($input['request_id'] ?? '') === '' || (string) ($input['session_key'] ?? '') === ''
            || (string) ($input['claim_credential'] ?? '') === '' || !$claim
            || !hash_equals((string) $claim['secret_hash'], $this->codes->hashSecret((string) $input['claim_credential']))) {
            self::deny('SAND_LICENSE_CLAIM_DENIED', '领取凭证无效或已失效', 401);
        }
        if (!empty($claim['session_key_hash']) && !hash_equals((string) $claim['session_key_hash'], $this->codes->hashSecret((string) $input['session_key']))) {
            self::deny('SAND_LICENSE_CLAIM_DENIED', '请使用首次领取时的会话', 401);
        }
        if ($write && (in_array($claim['state'], ['revoked'], true) || MembershipLogic::parseTime((string) $claim['expire_time']) <= $now)) {
            self::deny('SAND_LICENSE_CLAIM_UNAVAILABLE', '领取资格已失效，请联系购买渠道');
        }
    }

    private function revokeClaimsLocked(array $source, int $now): void
    {
        $claims = Db::table('sand_license_claim')->where('fulfillment_id', $source['id'])->order('id')->lock(true)->select()->toArray();
        foreach ($claims as $claim) {
            if (!empty($claim['redemption_code_id'])) {
                $code = Db::table('sand_license_redemption_code')->where('id', $claim['redemption_code_id'])->lock(true)->find();
                self::assertClaimBindings(['id' => $source['product_id']], $source, $claim, $code ?: []);
                if ($code && $code['state'] === 'issued') {
                    Db::table('sand_license_redemption_code')->where('id', $code['id'])
                        ->update(['state' => 'revoked', 'update_time' => gmdate('Y-m-d H:i:s', $now)]);
                }
            }
            Db::table('sand_license_claim')->where('id', $claim['id'])
                ->update(['state' => 'revoked', 'update_time' => gmdate('Y-m-d H:i:s', $now)]);
        }
    }

    private function claimResult(array $claim, string $requestId, int $now): array
    {
        $source = Db::table('sand_license_fulfillment')->where('id', $claim['fulfillment_id'])->find();
        $product = $source ? Db::table('sand_license_product')->where('id', $source['product_id'])->find() : null;
        $plan = $source ? json_decode((string) $source['plan_snapshot'], true, 32, JSON_THROW_ON_ERROR) : [];
        $code = empty($claim['redemption_code_id']) ? null : Db::table('sand_license_redemption_code')->where('id', $claim['redemption_code_id'])->find();
        $result = self::claimPresentation($claim, $source ?: [], $code ?: [], $now) + [
            'claim_id' => (string) $claim['id'], 'product_name' => (string) ($product['name'] ?? ''),
            'plan_name' => (string) ($plan['name'] ?? $plan['code'] ?? ''), 'request_id' => $requestId,
            'expire_time' => gmdate('Y-m-d\TH:i:s\Z', MembershipLogic::parseTime((string) $claim['expire_time'])),
            'server_time' => gmdate('Y-m-d\TH:i:s\Z', $now),
        ];
        if ($code) {
            $result['code_id'] = (string) $code['id'];
            $result['prefix'] = (string) $code['prefix'];
        }
        return $result;
    }

    private function sourceResult(array $source, string $requestId, int $now): array
    {
        $ids = Db::table('sand_license_claim')->where('fulfillment_id', $source['id'])->order('unit_no')->column('id');
        $grant = Db::table('sand_license_grant')->where('fulfillment_id', $source['id'])->order('id')->find();
        $result = ['state' => (string) $source['state'], 'fulfillment_id' => (string) $source['id'],
            'claim_ids' => array_map(static fn (mixed $id): string => (string) $id, $ids),
            'request_id' => $requestId, 'server_time' => gmdate('Y-m-d\TH:i:s\Z', $now)];
        if ($grant) $result['entitlement_id'] = (string) $grant['entitlement_id'];
        return $result;
    }

    public static function claimState(array $claim, array $source, array $code, int $now): string
    {
        if (in_array($source['state'] ?? '', ['refunded', 'chargeback'], true) || ($claim['state'] ?? '') === 'revoked') return 'revoked';
        if (($code['state'] ?? '') === 'redeemed' || ($claim['state'] ?? '') === 'redeemed') return 'redeemed';
        if (MembershipLogic::parseTime((string) ($claim['expire_time'] ?? '')) <= $now) return 'expired';
        return (string) $claim['state'];
    }

    /** Only trusted persisted source facts select a buyer-visible reason. */
    public static function claimPresentation(array $claim, array $source, array $code, int $now): array
    {
        $state = self::claimState($claim, $source, $code, $now);
        $reason = match ($source['state'] ?? '') {
            'refunded' => 'refunded',
            'chargeback' => 'chargeback',
            default => $state === 'revoked' ? 'claim_revoked' : '',
        };
        $message = match ($reason) {
            'refunded' => '购买来源已退款，领取资格已失效；如有疑问请联系购买渠道',
            'chargeback' => '购买来源已拒付，领取资格已失效；如有疑问请联系购买渠道',
            'claim_revoked' => '领取资格已撤销，请联系购买渠道',
            default => match ($state) {
                'ready' => '待领取，卡密仅在领取成功时显示一次',
                'claimed' => '已领取；如未保存卡密，请明确重新签发，原未兑换卡密将失效',
                'redeemed' => '卡密已兑换，不能重新签发',
                'expired' => '领取资格已过期，请联系购买渠道',
                default => '购买来源或领取资格已失效，请联系购买渠道',
            },
        };
        return ['state' => $state, 'reason_code' => $reason, 'message' => $message];
    }

    public static function validateEvent(array $input): void
    {
        foreach (['product_code', 'channel_code', 'event_id', 'event_type', 'order_id', 'order_item_id', 'payment_cycle_id', 'sku_code', 'request_id'] as $field) {
            $max = in_array($field, ['product_code', 'channel_code'], true) ? 80 : 160;
            if (!is_string($input[$field] ?? null) || trim($input[$field]) === '' || strlen($input[$field]) > $max
                || preg_match('/[\x00-\x1f]/', $input[$field])) {
                self::deny('SAND_LICENSE_FULFILLMENT_INPUT_INVALID', '履约字段缺失或无效：' . $field);
            }
        }
        if (!is_int($input['quantity'] ?? null) || $input['quantity'] < 1 || $input['quantity'] > 1000) {
            self::deny('SAND_LICENSE_FULFILLMENT_INPUT_INVALID', '履约数量须为1至1000的整数');
        }
        if (!in_array($input['event_type'], ['paid', 'refunded', 'chargeback', 'cancelled'], true)) {
            self::deny('SAND_LICENSE_FULFILLMENT_INPUT_INVALID', '履约事件类型无效');
        }
        if (in_array($input['event_type'], ['refunded', 'chargeback'], true) && ($input['refund_kind'] ?? '') !== 'full') {
            self::deny('SAND_LICENSE_PARTIAL_REFUND_UNSUPPORTED', '仅支持精确全额来源撤销，部分退款请人工处理');
        }
    }

    public static function nextState(string $current, string $event): string
    {
        if (!in_array($current, ['pending', 'paid', 'refunded', 'chargeback'], true)
            || !in_array($event, ['paid', 'refunded', 'chargeback', 'cancelled'], true)) {
            self::deny('SAND_LICENSE_SOURCE_INVALID', '履约状态无效');
        }
        if (in_array($current, ['refunded', 'chargeback'], true) || $event === 'cancelled') return $current;
        return $event;
    }

    /** Existing source withdrawal/replay uses ownership, not current sales status. */
    public static function assertNewSaleAllowed(array $product, string $current, string $event): void
    {
        if ($current === 'pending' && $event === 'paid') ProductLogic::assertPublished($product);
    }

    public static function assertSameSource(string $existingHash, string $incomingHash): void
    {
        if (!hash_equals($existingHash, $incomingHash)) self::deny('SAND_LICENSE_SOURCE_CONFLICT', '同一购买来源的商品、数量、套餐、主体或周期不一致');
    }

    public static function sourceFacts(array $input, array $snapshot): array
    {
        $member = $snapshot['kind'] === 'membership';
        return [
            'product_code' => $input['product_code'], 'channel_code' => $input['channel_code'],
            'order_item_id' => $input['order_item_id'], 'payment_cycle_id' => $input['payment_cycle_id'],
            'order_id' => $input['order_id'], 'quantity' => $input['quantity'], 'sku_code' => $input['sku_code'],
            'plan_snapshot' => $snapshot, 'subject_code' => $member ? $input['subject_code'] : null,
            'cycle_start_time' => $member ? gmdate('Y-m-d H:i:s', MembershipLogic::parseTime((string) $input['cycle_start_time'])) : null,
            'cycle_expire_time' => $member ? gmdate('Y-m-d H:i:s', MembershipLogic::parseTime((string) $input['cycle_expire_time'])) : null,
        ];
    }

    private static function planSnapshot(array $plan): array
    {
        $result = [];
        foreach (['code', 'revision', 'kind', 'duration_unit', 'duration_value', 'seat_limit'] as $field) $result[$field] = $plan[$field];
        $result['features'] = is_array($plan['features']) ? $plan['features'] : json_decode((string) $plan['features'], true, 32, JSON_THROW_ON_ERROR);
        return $result;
    }

    private static function digest(array $facts): string
    {
        return Records::fingerprint($facts);
    }

    private static function assertId(string $id): void
    {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $id)) self::deny('SAND_LICENSE_INPUT_INVALID', '记录编号无效');
    }

    private static function deny(string $code, string $message, int $status = 400): never
    {
        throw new ApiException($code . ': ' . $message, $status);
    }
}
