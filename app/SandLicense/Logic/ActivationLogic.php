<?php

declare(strict_types=1);

namespace app\SandLicense\Logic;

/**
 * UsageRegistrarService (masterix/laravel-licensing 2.2.1) seat registration flow,
 * adapted to installation keys and a released-seat lease drain instead of hardware fingerprints.
 */
final class ActivationLogic
{
    public static function occupied(array $activations, int $now): int
    {
        $used = 0;
        foreach ($activations as $activation) {
            if (($activation['state'] ?? '') === 'active'
                || (isset($activation['seat_available_time']) && Values::time($activation['seat_available_time']) > $now)) {
                $used++;
            }
        }
        return $used;
    }

    public static function assertSeat(array $activations, int $seatLimit, int $now): void
    {
        if ($seatLimit < 1 || self::occupied($activations, $now) >= $seatLimit) {
            Values::fail('SAND_LICENSE_SEAT_LIMIT', '席位已占用，请等待原租约到期或增加套餐席位');
        }
    }

    public static function releaseTime(array $leases, int $now): int
    {
        $available = $now;
        foreach ($leases as $lease) $available = max($available, Values::time($lease['expire_time']));
        return $available;
    }
}
