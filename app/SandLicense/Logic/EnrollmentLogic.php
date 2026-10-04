<?php

declare(strict_types=1);

namespace app\SandLicense\Logic;

/** One-time new-installation eligibility; callers also hold the entitlement row lock. */
final class EnrollmentLogic
{
    public static function assertUnused(array $ticket, int $now): void
    {
        if (($ticket['state'] ?? '') !== 'issued' || Values::time($ticket['expire_time']) <= $now) {
            Values::fail('SAND_LICENSE_ENROLLMENT_INVALID', '设备资格已使用、撤销或过期，不能重新生成或激活');
        }
    }

    public static function assertAvailable(array $ticket, int $now): void
    {
        self::assertUnused($ticket, $now);
        if (Values::time($ticket['available_time']) > $now) Values::fail('SAND_LICENSE_SEAT_WAIT', '原租约尚未到期，请在席位可用时间后重试');
    }

    public static function assertReissueAuthority(array $ticket, array $activation): void
    {
        $source = $ticket['source_activation_id'] ?? null;
        $state = $activation['state'] ?? '';
        if ($source === null) {
            if ($state === 'active') return;
        } elseif ((string) $source === (string) ($activation['id'] ?? '') && in_array($state, ['active','released'], true)) {
            return;
        }
        Values::fail('SAND_LICENSE_ENROLLMENT_INVALID', '仅活动安装或原释放安装可重新生成自身设备资格');
    }

    public static function expireTime(array $entitlement, int $available): int
    {
        $expire = min(Values::time($entitlement['expire_time']), $available + 86400);
        if ($expire <= $available) Values::fail('SAND_LICENSE_ENROLLMENT_INVALID', '权益到期前无法释放席位，请检查套餐期限');
        return $expire;
    }
}
