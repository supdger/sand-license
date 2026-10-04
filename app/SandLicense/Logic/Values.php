<?php

declare(strict_types=1);

namespace app\SandLicense\Logic;

use DateTimeImmutable;
use DateTimeZone;
use plugin\sandadmin\exception\ApiException;

/** Small domain input/time helpers shared by the actual business paths. */
final class Values
{
    public static function fail(string $code, string $message): never
    {
        throw new ApiException($code . ': ' . $message, 400);
    }

    public static function text(mixed $value, string $field, int $max = 160): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > $max || preg_match('/[\x00-\x1f]/', $value)) {
            self::fail('SAND_LICENSE_INPUT_INVALID', $field . '格式不正确');
        }
        return $value;
    }

    public static function id(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value)) || preg_match('/^[1-9][0-9]{0,18}$/D', (string) $value) !== 1) {
            self::fail('SAND_LICENSE_INPUT_INVALID', '记录标识格式不正确');
        }
        if (strlen((string) $value) === 19 && strcmp((string) $value, '9223372036854775807') > 0) {
            self::fail('SAND_LICENSE_INPUT_INVALID', '记录标识超出PostgreSQL范围');
        }
        return (string) $value;
    }

    public static function json(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (!is_string($value)) self::fail('SAND_LICENSE_DATA_INVALID', '策略数据不完整');
        try { $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { self::fail('SAND_LICENSE_DATA_INVALID', '策略JSON格式不正确'); }
        if (!is_array($decoded)) self::fail('SAND_LICENSE_DATA_INVALID', '策略数据不完整');
        return $decoded;
    }

    public static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function sqlTime(int $time): string { return gmdate('Y-m-d H:i:s', $time); }
    public static function iso(int $time): string { return gmdate('Y-m-d\TH:i:s\Z', $time); }

    public static function time(mixed $value): int
    {
        if (is_int($value) && $value > 0) return $value;
        if (!is_string($value) || $value === '') self::fail('SAND_LICENSE_INPUT_INVALID', '时间格式不正确');
        $sql = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) === 1;
        $iso = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) === 1;
        if (!$sql && !$iso) self::fail('SAND_LICENSE_INPUT_INVALID', '请使用UTC标准时间');
        $format = $sql ? 'Y-m-d H:i:s' : 'Y-m-d\TH:i:s\Z';
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format($format) !== $value) self::fail('SAND_LICENSE_INPUT_INVALID', '时间格式不正确');
        return $date->getTimestamp();
    }

    public static function requestId(array $input): string
    {
        return self::text($input['request_id'] ?? null, 'request_id');
    }

    public static function boolean(mixed $value, string $field): bool
    {
        if (!is_bool($value)) self::fail('SAND_LICENSE_INPUT_INVALID', $field . '必须为true或false');
        return $value;
    }

    /** Reject a scope without organization membership; null is reserved for the trusted super-admin adapter. */
    public static function assertOrganization(array $scope, int $organizationId): void
    {
        if (array_key_exists('organization_ids', $scope)) {
            $ids = $scope['organization_ids'];
            if ($ids === null && ($scope['is_super_admin'] ?? false) === true) return;
            if (is_array($ids) && in_array($organizationId, array_map('intval', $ids), true)) return;
        }
        if (isset($scope['organization_id']) && (int) $scope['organization_id'] === $organizationId && $organizationId > 0) return;
        self::fail('SAND_LICENSE_SCOPE_FORBIDDEN', '当前组织无权访问此产品');
    }
}
