<?php

declare(strict_types=1);

namespace app\SandLicense\Logic;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Adapted plan/interval concepts from laravelcm/subscriptions v1.8.0 Plan and Subscription.
 * Laravel relations are replaced with an immutable snapshot; UTC month-end clamps are explicit.
 */
final class PlanLogic
{
    public static function snapshot(array $plan): array
    {
        $kind = $plan['kind'] ?? '';
        $unit = $plan['duration_unit'] ?? '';
        $duration = filter_var($plan['duration_value'] ?? null, FILTER_VALIDATE_INT);
        $seats = filter_var($plan['seat_limit'] ?? 1, FILTER_VALIDATE_INT);
        if (!in_array($kind, ['desktop', 'membership'], true) || !in_array($unit, ['day', 'month', 'year'], true)
            || $duration === false || $duration < 1 || $duration > 10000 || $seats === false || $seats < 1 || $seats > 10000) {
            Values::fail('SAND_LICENSE_PLAN_INVALID', '套餐期限或席位配置不正确');
        }
        $features = Values::json($plan['features'] ?? []);
        foreach ($features as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $key) !== 1
                || (!is_bool($value) && (!is_int($value) || $value < 0))) {
                Values::fail('SAND_LICENSE_PLAN_INVALID', '功能值只接受启用状态或非负整数');
            }
        }
        return [
            'code' => Values::text($plan['code'] ?? null, '套餐代码', 80),
            'name' => Values::text($plan['name'] ?? $plan['code'] ?? null, '套餐名称', 120),
            'revision' => max(1, (int) ($plan['revision'] ?? 1)),
            'kind' => $kind, 'duration_unit' => $unit, 'duration_value' => $duration,
            'seat_limit' => $seats, 'features' => $features,
        ];
    }

    public static function expires(array $snapshot, int $start): int
    {
        $plan = self::snapshot($snapshot);
        if ($plan['duration_unit'] === 'day') return $start + $plan['duration_value'] * 86400;
        $date = (new DateTimeImmutable('@' . $start))->setTimezone(new DateTimeZone('UTC'));
        $months = $plan['duration_value'] * ($plan['duration_unit'] === 'year' ? 12 : 1);
        $monthIndex = (int) $date->format('Y') * 12 + (int) $date->format('n') - 1 + $months;
        $year = intdiv($monthIndex, 12);
        $month = $monthIndex % 12 + 1;
        $first = $date->setDate($year, $month, 1);
        $day = min((int) $date->format('j'), (int) $first->format('t'));
        return $first->setDate($year, $month, $day)->getTimestamp();
    }
}
