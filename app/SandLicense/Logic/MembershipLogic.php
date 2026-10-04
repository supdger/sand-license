<?php

declare(strict_types=1);

namespace app\SandLicense\Logic;

use DateTimeImmutable;
use DateTimeZone;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

/**
 * Adapted from laravelcm/laravel-subscriptions v1.8.0:
 * src/Models/Subscription.php and Plan.php, commit 68bf992aff33633659aa34ef85d705ba3c074e68.
 * Copyright (c) 2016-2021, Rinvex LLC. MIT; see THIRD_PARTY_NOTICES.
 * Eloquent/Carbon periods become immutable UTC source grants. Cancellation never
 * erases paid periods, and renewal adds a source instead of resetting old usage.
 */
final class MembershipLogic
{
    /** Caller holds product then fulfillment locks in the same transaction. */
    public function applyPaidLocked(array $fulfillment, array $plan, int $now): array
    {
        $period = self::validatePeriod($fulfillment);
        if (($fulfillment['state'] ?? '') !== 'paid' || ($plan['kind'] ?? '') !== 'membership') {
            self::deny('SAND_LICENSE_SOURCE_INVALID', '会员来源未支付或套餐类型不符');
        }
        $existing = Db::table('sand_license_grant')->where('fulfillment_id', $fulfillment['id'])
            ->where('source_unit_no', 1)->lock(true)->find();
        if ($existing) {
            return ['state' => $existing['state'], 'entitlement_id' => (string) $existing['entitlement_id'], 'grant_id' => (string) $existing['id']];
        }
        $time = self::sqlTime($now);
        $entitlementId = Db::table('sand_license_entitlement')->insertGetId([
            'product_id' => $fulfillment['product_id'],
            'plan_snapshot' => json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'kind' => 'membership',
            'subject_code' => $fulfillment['subject_code'],
            'state' => 'active',
            'start_time' => self::sqlTime($period['start']),
            'expire_time' => self::sqlTime($period['expire']),
            'seat_limit' => (int) ($plan['seat_limit'] ?? 1),
            'create_time' => $time,
            'update_time' => $time,
        ]);
        $grantId = Db::table('sand_license_grant')->insertGetId([
            'entitlement_id' => $entitlementId,
            'fulfillment_id' => $fulfillment['id'],
            'source_unit_no' => 1,
            'source_code' => 'fulfillment:' . (string) $fulfillment['id'] . ':1',
            'start_time' => self::sqlTime($period['start']),
            'expire_time' => self::sqlTime($period['expire']),
            'state' => 'active',
            'create_time' => $time,
            'update_time' => $time,
        ]);
        return ['state' => 'active', 'entitlement_id' => (string) $entitlementId, 'grant_id' => (string) $grantId];
    }

    /** Product/source/claim/code are locked first; only this source is revoked. */
    public function revokeSourceLocked(array $fulfillment, int $now): array
    {
        $grants = Db::table('sand_license_grant')->where('fulfillment_id', $fulfillment['id'])
            ->order('entitlement_id')->select()->toArray();
        $ids = array_values(array_unique(array_column($grants, 'entitlement_id')));
        $time = self::sqlTime($now);
        foreach ($ids as $id) {
            Db::table('sand_license_entitlement')->where('id', $id)->lock(true)->find();
            Db::table('sand_license_grant')->where('fulfillment_id', $fulfillment['id'])
                ->where('entitlement_id', $id)->where('state', 'active')
                ->update(['state' => 'revoked', 'revoked_time' => $time, 'update_time' => $time]);
            $remaining = Db::table('sand_license_grant')->where('entitlement_id', $id)
                ->where('state', 'active')->count();
            if ($remaining === 0) {
                Db::table('sand_license_entitlement')->where('id', $id)
                    ->update(['state' => 'revoked', 'update_time' => $time]);
                $activationIds = Db::table('sand_license_activation')->where('entitlement_id', $id)->column('id');
                if ($activationIds !== []) {
                    Db::table('sand_license_lease')->whereIn('activation_id', $activationIds)->where('state', 'issued')
                        ->update(['state' => 'revoked', 'revoked_time' => $time]);
                }
            }
        }
        return ['state' => 'revoked'];
    }

    public function cancelSourceLocked(array $fulfillment, int $now): array
    {
        if (empty($fulfillment['cancelled_time'])) {
            Db::table('sand_license_fulfillment')->where('id', $fulfillment['id'])
                ->update(['cancelled_time' => self::sqlTime($now), 'update_time' => self::sqlTime($now)]);
        }
        return ['state' => (string) $fulfillment['state']];
    }

    public function current(array $input, array $scope, int $now): array
    {
        $subject = (string) ($input['subject_code'] ?? '');
        if ($subject === '' || !isset($scope['subject_code']) || !hash_equals((string) $scope['subject_code'], $subject)) {
            self::deny('SAND_LICENSE_SCOPE_DENIED', '会员主体不在授权范围内', 401);
        }
        $product = Db::table('sand_license_product')->where('id', $scope['product_id'] ?? 0)->find();
        self::assertCurrentProductScope($product ?: [], $scope, (string) ($input['product_code'] ?? ''));
        $rows = Db::query(
            'SELECT g.start_time,g.expire_time,g.state,e.id AS entitlement_id,e.state AS entitlement_state,e.plan_snapshot'
            . ' FROM sand_license_grant g JOIN sand_license_entitlement e ON e.id=g.entitlement_id'
            . ' WHERE e.product_id=:product AND e.subject_code=:subject AND e.kind=:kind ORDER BY g.start_time,g.id',
            ['product' => $product['id'], 'subject' => $subject, 'kind' => 'membership'], true
        );
        $result = self::summarize($rows, $now);
        $result['server_time'] = gmdate('Y-m-d\TH:i:s\Z', $now);
        return $result;
    }

    /** Pure projection used by current(); no store or alternate production path. */
    public static function summarize(array $grants, int $now): array
    {
        $intervals = [];
        $features = [];
        $activeIds = [];
        foreach ($grants as $grant) {
            if (($grant['state'] ?? '') !== 'active' || ($grant['entitlement_state'] ?? 'active') !== 'active') {
                continue;
            }
            $start = self::parseTime((string) $grant['start_time']);
            $expire = self::parseTime((string) $grant['expire_time']);
            if ($start >= $expire) {
                self::deny('SAND_LICENSE_PERIOD_INVALID', '会员来源周期无效');
            }
            $intervals[] = ['start' => $start, 'expire' => $expire];
            if ($start <= $now && $now < $expire) {
                $activeIds[] = (string) ($grant['entitlement_id'] ?? '');
                $plan = is_array($grant['plan_snapshot'] ?? null) ? $grant['plan_snapshot']
                    : json_decode((string) ($grant['plan_snapshot'] ?? '{}'), true, 32, JSON_THROW_ON_ERROR);
                foreach (($plan['features'] ?? []) as $key => $value) {
                    if (!is_string($key) || $key === '' || (!is_bool($value) && (!is_int($value) || $value < 0))) {
                        self::deny('SAND_LICENSE_FEATURE_INVALID', '套餐功能须为布尔值或非负整数');
                    }
                    if (array_key_exists($key, $features) && gettype($features[$key]) !== gettype($value)) {
                        self::deny('SAND_LICENSE_FEATURE_INVALID', '同名套餐功能类型不一致');
                    }
                    if (is_bool($value)) {
                        $features[$key] = ($features[$key] ?? false) || $value;
                    } else {
                        $features[$key] = max($features[$key] ?? $value, $value);
                    }
                }
            }
        }
        usort($intervals, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $merged = [];
        foreach ($intervals as $interval) {
            $last = count($merged) - 1;
            if ($last >= 0 && $interval['start'] <= $merged[$last]['expire']) {
                $merged[$last]['expire'] = max($merged[$last]['expire'], $interval['expire']);
            } else {
                $merged[] = $interval;
            }
        }
        $result = [
            'state' => $activeIds === [] ? 'inactive' : 'active',
            'features' => $features,
            'intervals' => array_map(static fn (array $i): array => [
                'start_time' => gmdate('Y-m-d\TH:i:s\Z', $i['start']),
                'expire_time' => gmdate('Y-m-d\TH:i:s\Z', $i['expire']),
            ], $merged),
        ];
        if (count(array_unique($activeIds)) === 1) $result['entitlement_id'] = $activeIds[0];
        return $result;
    }

    public static function validatePeriod(array $input): array
    {
        if ((int) ($input['quantity'] ?? 0) !== 1 || !is_string($input['subject_code'] ?? null)
            || trim($input['subject_code']) === '' || strlen($input['subject_code']) > 160
            || preg_match('/[\x00-\x1f]/', $input['subject_code'])) {
            self::deny('SAND_LICENSE_MEMBERSHIP_INPUT_INVALID', '会员须指定业务主体，购买数量须为1');
        }
        $start = self::parseTime((string) ($input['cycle_start_time'] ?? ''));
        $expire = self::parseTime((string) ($input['cycle_expire_time'] ?? ''));
        if ($start >= $expire) self::deny('SAND_LICENSE_PERIOD_INVALID', '会员周期开始须早于结束');
        return ['start' => $start, 'expire' => $expire];
    }

    public static function assertProductScope(array $product, array $scope, string $productCode): void
    {
        foreach (['organization_id', 'application_id', 'environment_id', 'product_id'] as $field) {
            if (!isset($scope[$field]) || (string) $scope[$field] === '') {
                self::deny('SAND_LICENSE_SCOPE_DENIED', '产品授权范围不完整', 401);
            }
        }
        if (!$product || !empty($product['delete_time'])
            || (string) ($product['id'] ?? '') !== (string) $scope['product_id']
            || (string) ($product['organization_id'] ?? '') !== (string) $scope['organization_id']
            || (string) ($product['application_id'] ?? '') !== (string) $scope['application_id']
            || !hash_equals((string) ($product['code'] ?? ''), $productCode)) {
            self::deny('SAND_LICENSE_SCOPE_DENIED', '产品不在授权范围内', 401);
        }
    }

    /** Current benefits remain subject to live product withdrawal. */
    public static function assertCurrentProductScope(array $product, array $scope, string $productCode): void
    {
        self::assertProductScope($product, $scope, $productCode);
        ProductLogic::assertPublished($product);
    }

    public static function parseTime(string $time): int
    {
        $format = str_ends_with($time, 'Z') ? '!Y-m-d\TH:i:s\Z' : '!Y-m-d H:i:s';
        $date = DateTimeImmutable::createFromFormat($format, $time, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            self::deny('SAND_LICENSE_PERIOD_INVALID', '时间须为有效UTC日期和秒');
        }
        return $date->getTimestamp();
    }

    private static function sqlTime(int $time): string
    {
        return gmdate('Y-m-d H:i:s', $time);
    }

    private static function deny(string $code, string $message, int $status = 400): never
    {
        throw new ApiException($code . ': ' . $message, $status);
    }
}
