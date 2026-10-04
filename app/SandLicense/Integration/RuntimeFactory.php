<?php

declare(strict_types=1);

namespace app\SandLicense\Integration;

use app\SandLicense\Logic\AdminLogic;
use app\SandLicense\Logic\CodeLogic;
use app\SandLicense\Logic\FulfillmentLogic;
use app\SandLicense\Logic\LicensingLogic;
use app\SandLicense\Logic\MembershipLogic;
use app\SandLicense\Security\InstallationProof;
use app\SandLicense\Security\LeaseToken;
use plugin\sandadmin\exception\ApiException;

/** Composes the single domain kernel from restricted runtime configuration. */
final class RuntimeFactory
{
    /** @return array<string,mixed> */
    public static function configuration(): array
    {
        $settings = config('sand_license_runtime', []);
        if (!is_array($settings)) self::missing();
        return $settings;
    }

    public static function origin(): string
    {
        $origin = (string) (self::configuration()['origin'] ?? '');
        if (preg_match('~\Ahttps://[A-Za-z0-9.-]+(?::[0-9]{1,5})?\z~D', $origin) !== 1) self::missing();
        return $origin;
    }

    private static function restrictedFile(string $setting): string
    {
        $path = self::configuration()[$setting] ?? null;
        if (!is_string($path) || !str_starts_with($path, '/') || is_link($path)
            || !is_file($path) || !is_readable($path) || filesize($path) > 16384) self::missing();
        $resolved = realpath($path);
        $host = realpath(base_path());
        if ($resolved === false || ($host !== false && str_starts_with($resolved, $host . '/'))
            || ((fileperms($path) & 0077) !== 0)) self::missing();
        return (string) file_get_contents($path);
    }

    public static function pepper(): string
    {
        $pepper = trim(self::restrictedFile('pepper_file'));
        if (strlen($pepper) < 32) self::missing();
        return $pepper;
    }

    /** @return array<string,mixed> */
    public static function signingKeys(): array
    {
        try {
            $data = json_decode(self::restrictedFile('signing_key_file'), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            self::missing();
        }
        if (!is_array($data) || !is_string($data['kid'] ?? null) || !is_string($data['private_key_base64'] ?? null)
            || !is_string($data['issuer'] ?? null) || !is_array($data['public_keys_by_kid'] ?? null)) self::missing();
        return $data;
    }

    public static function codes(): CodeLogic
    {
        return new CodeLogic(self::pepper());
    }

    public static function licensing(string $action = 'redeem'): LicensingLogic
    {
        $needsSecrets = $action !== 'challenge' && $action !== 'current';
        $needsSigning = in_array($action, ['redeem', 'renew', 'enroll'], true);
        $keys = $needsSigning ? self::signingKeys() : [];
        return new LicensingLogic(
            $needsSecrets ? self::codes() : null,
            $needsSigning ? new LeaseToken($keys['kid'], $keys['private_key_base64'], $keys['public_keys_by_kid'], $keys['issuer']) : null,
            new InstallationProof(),
            self::origin(),
        );
    }

    public static function fulfillment(): FulfillmentLogic
    {
        return new FulfillmentLogic(self::codes(), new MembershipLogic(), (int) (self::configuration()['claim_ttl_seconds'] ?? 2592000));
    }

    public static function admin(string $resource = '', string $action = ''): AdminLogic
    {
        return new AdminLogic(
            $resource === 'code' && in_array($action, ['issue', 'status', 'reissue'], true) ? self::codes() : null,
            ($resource === 'activation' && in_array($action, ['release', 'reset'], true))
                || ($resource === 'entitlement' && $action === 'ticket') ? self::licensing('reset') : null,
        );
    }

    private static function missing(): never
    {
        throw new ApiException('SAND_LICENSE_CONFIGURATION_REQUIRED: 请由部署管理员配置可信服务地址及外部受限密钥文件', 400);
    }
}
