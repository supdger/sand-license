<?php

declare(strict_types=1);

namespace app\SandLicense\Logic;

use think\facade\Db;

final class CodeLogic
{
    public function __construct(private readonly string $pepper)
    {
        if (strlen($pepper) < 32) Values::fail('SAND_LICENSE_CONFIG_REQUIRED', '请配置至少32字节的受限卡密摘要密钥');
    }

    public function generateSecret(): string { return bin2hex(random_bytes(24)); }

    public function hashSecret(string $secret): string
    {
        Values::text($secret, '秘密值', 256);
        return hash_hmac('sha256', $secret, $this->pepper);
    }

    public function issue(array $input, array $scope, int $now): array
    {
        $requestId = Values::requestId($input);
        return Db::transaction(function () use ($input, $scope, $now, $requestId): array {
            $product = ProductLogic::scoped(Values::id($input['product_id'] ?? null), $scope, true);
            ProductLogic::assertPublished($product);
            $plan = $this->plan(Values::id($input['plan_id'] ?? null), (string) $product['id']);
            $business = ['plan_id' => (string) $plan['id'], 'expire_time' => $input['expire_time'] ?? null];
            $retry = Records::retry($product['id'], 'code.issue', $requestId, $business);
            if ($retry !== null) return $retry;
            $expire = isset($input['expire_time']) ? Values::time($input['expire_time']) : $now + 365 * 86400;
            if ($expire <= $now) Values::fail('SAND_LICENSE_INPUT_INVALID', '可兑换截止时间必须晚于当前时间');
            $minted = $this->insertCode($product, $plan, null, $expire, $now);
            $result = $minted + ['state' => 'issued', 'request_id' => $requestId, 'server_time' => Values::iso($now)];
            Records::remember($product['id'], 'code.issue', $requestId, $business, $result, $now);
            Records::event($product['id'], 'code.issue', 'redemption_code', $minted['code_id'], (string) ($scope['actor_ref'] ?? 'admin'), $requestId, $now);
            return $result;
        });
    }

    public function status(string $requestId, array $scope, int $now): array
    {
        Values::text($requestId, 'request_id');
        $products = Db::table('sand_license_product')->whereNull('delete_time')->select()->toArray();
        $matches = [];
        foreach ($products as $product) {
            try { Values::assertOrganization($scope, (int) $product['organization_id']); }
            catch (\plugin\sandadmin\exception\ApiException) { continue; }
            $row = Db::table('sand_license_request_dedup')->where('product_id', $product['id'])
                ->whereIn('scope', ['code.issue', 'code.reissue'])
                ->where('idempotency_key_hash', hash('sha256', $requestId))->find();
            if (is_array($row)) {
                $result = Values::json($row['result_ref']);
                $code = Db::table('sand_license_redemption_code')->where('id', $result['code_id'])->find();
                $matches[] = array_merge($result, ['state' => $code['state'] ?? $result['state'], 'server_time' => Values::iso($now)]);
            }
        }
        if (count($matches) > 1) Values::fail('SAND_LICENSE_REQUEST_AMBIGUOUS', '请求标识对应多个产品，请使用全局唯一请求标识');
        return $matches[0] ?? ['state' => 'not_found', 'request_id' => $requestId, 'server_time' => Values::iso($now)];
    }

    public function reissue(string $codeId, string $requestId, array $scope, int $now): array
    {
        Values::text($requestId, 'request_id');
        return Db::transaction(function () use ($codeId, $requestId, $scope, $now): array {
            $hint = Db::table('sand_license_redemption_code')->where('id', Values::id($codeId))->find();
            if (!is_array($hint)) Values::fail('SAND_LICENSE_CODE_INVALID', '卡密不可用');
            if ($hint['claim_id'] !== null) Values::fail('SAND_LICENSE_CLAIM_REQUIRED', '领取卡密请使用对应领取资格的重新生成入口');
            ProductLogic::assertPublished(ProductLogic::scoped((string) $hint['product_id'], $scope, true));
            $code = Db::table('sand_license_redemption_code')->where('id', $codeId)->lock(true)->find();
            $business = ['code_id' => $codeId];
            $retry = Records::retry($code['product_id'], 'code.reissue', $requestId, $business);
            if ($retry !== null) return $retry;
            self::assertReissuable($code, $now);
            $plan = $this->plan((string) $code['plan_id'], (string) $code['product_id']);
            $product = ['id' => $code['product_id']];
            Db::table('sand_license_redemption_code')->where('id', $codeId)->update(['state' => 'revoked', 'update_time' => Values::sqlTime($now)]);
            $minted = $this->insertCode($product, $plan, null, Values::time($code['expire_time']), $now);
            $result = $minted + ['state' => 'issued', 'request_id' => $requestId, 'server_time' => Values::iso($now)];
            Records::remember($code['product_id'], 'code.reissue', $requestId, $business, $result, $now);
            Records::event($code['product_id'], 'code.reissue', 'redemption_code', $codeId, (string) ($scope['actor_ref'] ?? 'admin'), $requestId, $now);
            return $result;
        });
    }

    /** Caller holds claim row lock and atomically updates claim after this insert. */
    public function issueForClaimLocked(array $claim, array $product, array $plan, int $now): array
    {
        return $this->insertCode($product, $plan, Values::id($claim['id']), Values::time($claim['expire_time']), $now);
    }

    public static function assertReissuable(array $code, int $now): void
    {
        if (($code['state'] ?? '') !== 'issued' || ($code['entitlement_id'] ?? null) !== null || Values::time($code['expire_time']) <= $now) {
            Values::fail('SAND_LICENSE_CODE_REISSUE_DENIED', '卡密已兑换、撤销或过期，不能重新生成');
        }
    }

    /** Validate current locked facts, never the pre-lock hint, before redemption or retry. */
    public static function assertClaimBinding(array $source, array $claim, array $code, string $productId): void
    {
        if (!isset($source['id'], $claim['id'], $code['id'], $claim['fulfillment_id'], $claim['redemption_code_id'], $code['claim_id'])
            || ($source['state'] ?? '') !== 'paid'
            || (string) ($source['product_id'] ?? '') !== $productId
            || (string) ($code['product_id'] ?? '') !== $productId
            || (string) ($claim['fulfillment_id'] ?? '') !== (string) ($source['id'] ?? '')
            || (string) ($code['claim_id'] ?? '') !== (string) ($claim['id'] ?? '')
            || (string) ($claim['redemption_code_id'] ?? '') !== (string) ($code['id'] ?? '')
            || !in_array($claim['state'] ?? '', ['claimed', 'redeemed'], true)) {
            Values::fail('SAND_LICENSE_CODE_INVALID', '卡密不可用');
        }
    }

    private function plan(string $id, string $productId): array
    {
        $plan = Db::table('sand_license_plan')->where('id', $id)->where('product_id', $productId)->whereNull('delete_time')->find();
        if (!is_array($plan) || $plan['state'] !== 'published' || (int) $plan['status'] !== 1 || $plan['kind'] !== 'desktop') {
            Values::fail('SAND_LICENSE_PLAN_UNAVAILABLE', '请选择已发布的桌面许可套餐');
        }
        return $plan;
    }

    private function insertCode(array $product, array $plan, ?string $claimId, int $expire, int $now): array
    {
        if ((string) $plan['product_id'] !== (string) $product['id'] || $plan['state'] !== 'published' || (int) $plan['status'] !== 1) {
            Values::fail('SAND_LICENSE_PLAN_UNAVAILABLE', '套餐不属于目标产品或尚未发布');
        }
        if ($expire <= $now) Values::fail('SAND_LICENSE_CLAIM_EXPIRED', '领取资格已过期');
        $secret = $this->generateSecret();
        $prefix = substr($secret, 0, 8);
        $id = Db::table('sand_license_redemption_code')->insertGetId([
            'product_id' => $product['id'], 'plan_id' => $plan['id'], 'claim_id' => $claimId,
            'prefix' => $prefix, 'secret_hash' => $this->hashSecret($secret), 'pepper_version' => 'v1', 'state' => 'issued',
            'expire_time' => Values::sqlTime($expire), 'create_time' => Values::sqlTime($now), 'update_time' => Values::sqlTime($now),
        ]);
        return ['code_id' => (string) $id, 'code' => $secret, 'prefix' => $prefix];
    }
}
