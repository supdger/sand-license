<?php

declare(strict_types=1);

namespace app\SandLicense\Logic;

use think\facade\Db;

/** Shared dedup/event persistence only. Callers hold their domain row lock in the same transaction. */
final class Records
{
    public static function retry(int|string $productId, string $scope, string $requestId, array $businessInput): ?array
    {
        $row = Db::table('sand_license_request_dedup')->where('product_id', $productId)->where('scope', $scope)
            ->where('idempotency_key_hash', hash('sha256', $requestId))->find();
        if (!is_array($row)) return null;
        if (!hash_equals((string) $row['request_hash'], self::fingerprint($businessInput))) {
            Values::fail('SAND_LICENSE_IDEMPOTENCY_CONFLICT', '同一请求标识对应不同内容，请检查调用记录');
        }
        return Values::json($row['result_ref']);
    }

    public static function remember(int|string $productId, string $scope, string $requestId, array $businessInput, array $result, int $now): void
    {
        // One-time secrets and signed tokens are never persisted in a replay record.
        unset($result['code'], $result['enrollment_ticket'], $result['lease'], $result['claim_credential'], $result['claim_credentials']);
        Db::table('sand_license_request_dedup')->insert([
            'product_id' => $productId, 'scope' => $scope,
            'idempotency_key_hash' => hash('sha256', $requestId), 'request_hash' => self::fingerprint($businessInput),
            'state' => 'succeeded', 'result_ref' => Values::encode($result), 'create_time' => Values::sqlTime($now),
        ]);
    }

    public static function event(int|string $productId, string $action, string $type, int|string|null $id, string $actor, string $requestId, int $now, array $detail = []): void
    {
        Db::table('sand_license_event')->insert([
            'product_id' => $productId, 'action' => $action, 'object_type' => $type, 'object_id' => $id,
            'actor_ref' => $actor, 'request_id' => $requestId,
            'detail' => Values::encode($detail), 'create_time' => Values::sqlTime($now),
        ]);
    }

    public static function fingerprint(array $input): string
    {
        // The payload contains only a request digest for secrets; never serialize raw code/proof into DB.
        unset($input['challenge'], $input['proof'], $input['request_id']);
        self::sort($input);
        return hash('sha256', Values::encode($input));
    }

    private static function sort(array &$value): void
    {
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as &$item) if (is_array($item)) self::sort($item);
    }
}
