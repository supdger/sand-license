<?php

declare(strict_types=1);

namespace app\SandLicense\Security;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use plugin\sandadmin\exception\ApiException;
use stdClass;
use Throwable;

/** EdDSA proof JWT for this product's installation protocol; not an RFC DPoP implementation. */
final class InstallationProof
{
    /** @param array<string, mixed> $OKPJwk */
    public static function thumbprint(array $OKPJwk): string
    {
        if (($OKPJwk['kty'] ?? null) !== 'OKP'
            || ($OKPJwk['crv'] ?? null) !== 'Ed25519'
            || array_key_exists('d', $OKPJwk)
            || !is_string($OKPJwk['x'] ?? null)
            || preg_match('/^[A-Za-z0-9_-]{43}$/D', $OKPJwk['x']) !== 1
        ) {
            throw new ApiException('SAND_LICENSE_INSTALLATION_KEY_INVALID：安装公钥格式不合法', 400);
        }
        $publicKey = base64_decode(strtr($OKPJwk['x'], '-_', '+/') . '=', true);
        if ($publicKey === false || strlen($publicKey) !== 32 || JWT::urlsafeB64Encode($publicKey) !== $OKPJwk['x']) {
            throw new ApiException('SAND_LICENSE_INSTALLATION_KEY_INVALID：安装公钥格式不合法', 400);
        }
        // RFC 7638 requires only these members in lexicographic order.
        $canonical = json_encode(['crv' => 'Ed25519', 'kty' => 'OKP', 'x' => $OKPJwk['x']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return JWT::urlsafeB64Encode(hash('sha256', $canonical, true));
    }

    /**
     * The caller supplies the challenge-bound key and configured origin + matched route path.
     * Purpose checks and one-time nonce/jti consumption belong to the same domain transaction.
     *
     * @param array<string, mixed> $OKPJwk
     * @return array<string, mixed>
     */
    public static function verify(
        string $proof,
        array $OKPJwk,
        string $method,
        string $configuredAbsoluteUrl,
        string $rawBody,
        string $nonce,
        string $productCode,
        int $now,
    ): array {
        try {
            self::thumbprint($OKPJwk);
            $url = parse_url($configuredAbsoluteUrl);
            if (!is_array($url)
                || !in_array($url['scheme'] ?? null, ['https', 'http'], true)
                || !isset($url['host'], $url['path'])
                || isset($url['query']) || isset($url['fragment'])
                || isset($url['user']) || isset($url['pass'])
                || str_contains($url['path'], '//')
                || str_contains($url['path'], '%')
                || str_contains($url['path'], '\\')
                || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $url['path']) === 1
                || preg_match('/[\x00-\x20\x7f]/', $configuredAbsoluteUrl) === 1
                || preg_match('/^[A-Z]+$/D', $method) !== 1
                || $productCode === ''
                || $now <= 0
            ) {
                throw new ApiException('SAND_LICENSE_PROOF_INVALID：安装证明无效，请重新获取挑战', 401);
            }
            Challenge::digest($nonce);
            $key = JWK::parseKey(['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => $OKPJwk['x']], 'EdDSA');
            if ($key === null) {
                throw new ApiException('SAND_LICENSE_PROOF_INVALID：安装证明无效，请重新获取挑战', 401);
            }
            $header = new stdClass();
            $payload = JWT::decode($proof, $key, $header);
            $claims = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($claims)
                || ($header->typ ?? null) !== 'sand-license-proof+jwt'
                || ($header->alg ?? null) !== 'EdDSA'
                || isset($header->crit)
            ) {
                throw new ApiException('SAND_LICENSE_PROOF_INVALID：安装证明无效，请重新获取挑战', 401);
            }
            foreach (['htm', 'htu', 'jti', 'nonce', 'body_sha256', 'product_code'] as $name) {
                if (!is_string($claims[$name] ?? null) || $claims[$name] === '' || strlen($claims[$name]) > 2048) {
                    throw new ApiException('SAND_LICENSE_PROOF_INVALID：安装证明无效，请重新获取挑战', 401);
                }
            }
            if (!is_int($claims['iat'] ?? null)
                || $claims['iat'] > $now
                || $claims['iat'] < $now - 60
                || $claims['htm'] !== $method
                || $claims['htu'] !== $configuredAbsoluteUrl
                || $claims['product_code'] !== $productCode
                || !hash_equals($nonce, $claims['nonce'])
                || !hash_equals(hash('sha256', $rawBody), $claims['body_sha256'])
            ) {
                throw new ApiException('SAND_LICENSE_PROOF_INVALID：安装证明无效，请重新获取挑战', 401);
            }
            return $claims;
        } catch (Throwable) {
            throw new ApiException('SAND_LICENSE_PROOF_INVALID：安装证明无效，请重新获取挑战', 401);
        }
    }
}
