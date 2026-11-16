<?php

declare(strict_types=1);

namespace app\SandLicense\Logic;

/**
 * Adapted from masterix21/laravel-licensing v2.2.1:
 * src/Services/PasetoTokenService.php, commit d304d1cbaa50624bd0731a61500658936cbb7f62.
 * Copyright (c) Luca Longo <l.longo@ambita.it>. MIT; see THIRD_PARTY_NOTICES.
 * Refresh/re-sign flow; explicit state/window checks are local hardening.
 */
final class LeaseLogic
{
    public static function assertActive(array $entitlement, ?array $activation, int $now): void
    {
        if (($entitlement['state'] ?? '') !== 'active'
            || Values::time($entitlement['start_time']) > $now || Values::time($entitlement['expire_time']) <= $now
            || ($activation !== null && ($activation['state'] ?? '') !== 'active')) {
            Values::fail('SAND_LICENSE_ENTITLEMENT_UNAVAILABLE', '许可或设备当前不可用，请检查期限与设备状态');
        }
    }

    public static function expireTime(array $entitlement, int $now): int
    {
        self::assertActive($entitlement, null, $now);
        return min($now + 900, Values::time($entitlement['expire_time']));
    }
}
