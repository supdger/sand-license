<?php

declare(strict_types=1);

namespace app\SandLicense\Security;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use plugin\sandadmin\exception\ApiException;
use stdClass;
use Throwable;

/** Applies the SandLicense lease contract around the standard JWT implementation. */
final class LeaseToken
{
    /** @var array<string, Key> */
    private array $verificationKeys = [];

    /** @param array<string, string> $publicKeysByKid Standard base64 sodium public keys. */
    public function __construct(
        private readonly string $kid,
        #[\SensitiveParameter] private readonly string $privateKeyBase64,
        array $publicKeysByKid,
        private readonly string $issuer,
    ) {
        if ($kid === '' || $issuer === '' || !function_exists('sodium_crypto_sign_publickey_from_secretkey')) {
            throw new ApiException('SAND_LICENSE_SIGNING_CONFIG_INVALID：许可签名配置不可用', 400);
        }
        $privateKey = base64_decode($privateKeyBase64, true);
        if ($privateKey === false || strlen($privateKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new ApiException('SAND_LICENSE_SIGNING_CONFIG_INVALID：许可签名配置不可用', 400);
        }
        foreach ($publicKeysByKid as $keyId => $encodedKey) {
            if (!is_string($keyId) || $keyId === '' || !is_string($encodedKey)) {
                throw new ApiException('SAND_LICENSE_SIGNING_CONFIG_INVALID：许可验签配置不可用', 400);
            }
            $publicKey = base64_decode($encodedKey, true);
            if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new ApiException('SAND_LICENSE_SIGNING_CONFIG_INVALID：许可验签配置不可用', 400);
            }
            $this->verificationKeys[$keyId] = new Key($encodedKey, 'EdDSA');
        }
        $activePublic = isset($publicKeysByKid[$kid]) ? base64_decode($publicKeysByKid[$kid], true) : false;
        if ($activePublic === false || !hash_equals(sodium_crypto_sign_publickey_from_secretkey($privateKey), $activePublic)) {
            throw new ApiException('SAND_LICENSE_SIGNING_CONFIG_INVALID：签名与验签密钥不匹配', 400);
        }
    }

    public function kid(): string
    {
        return $this->kid;
    }

    /**
     * Domain claims come from the locked entitlement and activation, never from client input.
     *
     * @param array<string, mixed> $domainClaims
     */
    public function issue(array $domainClaims, int $entitlementExpire, int $now): string
    {
        if ($now <= 0 || $entitlementExpire <= $now) {
            throw new ApiException('SAND_LICENSE_LEASE_INVALID：权益已到期，无法签发租约', 400);
        }
        $claims = [
            'iss' => $this->issuer,
            'iat' => $now,
            'nbf' => $now,
            'exp' => min($entitlementExpire, $now + 900),
        ];
        foreach (['aud', 'sub', 'entitlement_id', 'activation_id', 'product_code', 'plan_revision', 'features', 'cnf', 'jti'] as $name) {
            if (!array_key_exists($name, $domainClaims)) {
                throw new ApiException('SAND_LICENSE_LEASE_INVALID：租约信息不完整', 400);
            }
            $claims[$name] = $domainClaims[$name];
        }
        if (!$this->validClaims($claims, $now)) {
            throw new ApiException('SAND_LICENSE_LEASE_INVALID：租约信息不合法', 400);
        }
        try {
            return JWT::encode($claims, $this->privateKeyBase64, 'EdDSA', $this->kid);
        } catch (Throwable) {
            throw new ApiException('SAND_LICENSE_SIGNING_UNAVAILABLE：许可签名暂不可用', 400);
        }
    }

    /** @return array<string, mixed> */
    public function verify(
        string $token,
        string $expectedAudience,
        string $expectedProductCode,
        string $expectedEntitlementId,
        string $expectedActivationId,
        string $expectedJkt,
        int $now,
    ): array {
        try {
            $header = new stdClass();
            $payload = JWT::decode($token, $this->verificationKeys, $header);
            $claims = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($claims)
                || ($header->typ ?? null) !== 'JWT'
                || ($header->alg ?? null) !== 'EdDSA'
                || !is_string($header->kid ?? null)
                || !isset($this->verificationKeys[$header->kid])
                || isset($header->crit)
                || !$this->validClaims($claims, $now)
                || $claims['iss'] !== $this->issuer
                || $claims['aud'] !== $expectedAudience
                || $claims['product_code'] !== $expectedProductCode
                || $claims['sub'] !== $expectedEntitlementId
                || $claims['entitlement_id'] !== $expectedEntitlementId
                || $claims['activation_id'] !== $expectedActivationId
                || !hash_equals($expectedJkt, $claims['cnf']['jkt'])
            ) {
                throw new ApiException('SAND_LICENSE_LEASE_INVALID：租约无效，请重新在线获取', 401);
            }
            return $claims;
        } catch (Throwable) {
            throw new ApiException('SAND_LICENSE_LEASE_INVALID：租约无效，请重新在线获取', 401);
        }
    }

    /** @param array<string, mixed> $claims */
    private function validClaims(array $claims, int $now): bool
    {
        foreach (['iss', 'aud', 'sub', 'entitlement_id', 'activation_id', 'product_code', 'jti'] as $name) {
            if (!isset($claims[$name]) || !is_string($claims[$name]) || $claims[$name] === '' || strlen($claims[$name]) > 512) {
                return false;
            }
        }
        foreach (['sub', 'entitlement_id', 'activation_id'] as $name) {
            if (preg_match('/^[1-9][0-9]{0,18}$/D', $claims[$name]) !== 1) {
                return false;
            }
        }
        foreach (['iat', 'nbf', 'exp', 'plan_revision'] as $name) {
            if (!isset($claims[$name]) || !is_int($claims[$name]) || $claims[$name] <= 0) {
                return false;
            }
        }
        return $now > 0
            && $claims['sub'] === $claims['entitlement_id']
            && $claims['nbf'] <= $claims['iat']
            && $claims['iat'] <= $now
            && $claims['nbf'] <= $now
            && $now < $claims['exp']
            && $claims['iat'] < $claims['exp']
            && $claims['exp'] - $claims['iat'] <= 900
            && isset($claims['features'], $claims['cnf'])
            && is_array($claims['features'])
            && is_array($claims['cnf'])
            && is_string($claims['cnf']['jkt'] ?? null)
            && preg_match('/^[A-Za-z0-9_-]{43}$/D', $claims['cnf']['jkt']) === 1;
    }
}
