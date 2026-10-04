<?php

declare(strict_types=1);

namespace app\SandLicense\Security;

use plugin\sandadmin\exception\ApiException;

/** Generates challenge material; persistence, scope and consumption remain in domain logic. */
final class Challenge
{
    /** @return array{nonce: string, secret_hash: string, expire_time: int} */
    public static function issue(int $now): array
    {
        if ($now <= 0) {
            throw new ApiException('SAND_LICENSE_CHALLENGE_INVALID：挑战时间不合法', 400);
        }
        $nonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        return ['nonce' => $nonce, 'secret_hash' => self::digest($nonce), 'expire_time' => $now + 120];
    }

    public static function digest(string $nonce): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $nonce) !== 1) {
            throw new ApiException('SAND_LICENSE_CHALLENGE_INVALID：挑战无效，请重新获取', 401);
        }
        $decoded = base64_decode(strtr($nonce, '-_', '+/') . '=', true);
        if ($decoded === false || strlen($decoded) !== 32
            || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $nonce
        ) {
            throw new ApiException('SAND_LICENSE_CHALLENGE_INVALID：挑战无效，请重新获取', 401);
        }
        return hash('sha256', $nonce);
    }
}
