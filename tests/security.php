<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use app\SandLicense\Security\Challenge;
use app\SandLicense\Security\InstallationProof;
use app\SandLicense\Security\LeaseToken;
use Firebase\JWT\JWT;
use plugin\sandadmin\exception\ApiException;

/** Every rejection must use the real host business exception, with no secret disclosure. */
function securityReject(callable $operation, string $label, int $code = 401): void
{
    try {
        $operation();
    } catch (ApiException $exception) {
        licenseAssert($exception->getCode() === $code, $label . ': wrong business status');
        licenseAssert(str_starts_with($exception->getMessage(), 'SAND_LICENSE_'), $label . ': missing stable error');
        return;
    }
    throw new RuntimeException($label . ': expected rejection');
}

$started = microtime(true);
$now = time();
$jwtTimestamp = JWT::$timestamp;
$jwtLeeway = JWT::$leeway;
$keyPair = sodium_crypto_sign_keypair();
$private = sodium_crypto_sign_secretkey($keyPair);
$public = sodium_crypto_sign_publickey($keyPair);
$privateBase64 = base64_encode($private);
$jwk = ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => JWT::urlsafeB64Encode($public)];
$jkt = InstallationProof::thumbprint($jwk);
$issuer = new LeaseToken('test-key', $privateBase64, ['test-key' => base64_encode($public)], 'https://license.example');
$domain = [
    'aud' => 'desktop.example',
    'sub' => '21',
    'entitlement_id' => '21',
    'activation_id' => '34',
    'product_code' => 'desktop',
    'plan_revision' => 1,
    'features' => ['editor' => true, 'exports' => 100],
    'cnf' => ['jkt' => $jkt],
    'jti' => bin2hex(random_bytes(16)),
];
$verify = static fn (string $token): array => $issuer->verify($token, 'desktop.example', 'desktop', '21', '34', $jkt, time());
$signLease = static fn (array $claims, string $keyId = 'test-key', array $header = []): string =>
    JWT::encode($claims, $privateBase64, 'EdDSA', $keyId, $header);

echo "Security 1/4: real EdDSA lease signing and entitlement expiry\n";
$lease = $issuer->issue($domain, $now + 7200, $now);
$claims = $verify($lease);
licenseAssert($claims['exp'] === $now + 900, 'Lease must cap at 900 seconds');
licenseAssert($claims['iat'] === $now && $claims['nbf'] === $now, 'Lease time contract');
licenseAssert($claims['sub'] === '21' && $claims['cnf']['jkt'] === $jkt, 'Subject and installation binding');
$shortLease = $verify($issuer->issue($domain, $now + 45, $now));
licenseAssert($shortLease['exp'] === $now + 45, 'Lease must stop at entitlement expiry');
securityReject(static fn () => $issuer->issue($domain, $now, $now), 'Expired entitlement', 400);
securityReject(static fn () => $issuer->issue(array_replace($domain, ['sub' => '22']), $now + 45, $now), 'Subject mismatch at issue', 400);
securityReject(static fn () => new LeaseToken('test-key', $privateBase64, ['test-key' => base64_encode(random_bytes(32))], 'https://license.example'), 'Mismatched signing key', 400);
securityReject(static fn () => $verify('malformed.token'), 'Malformed token');
securityReject(static fn () => $verify($signLease($claims, 'unknown-key')), 'Unknown kid');
securityReject(static fn () => $verify($signLease($claims, 'test-key', ['typ' => 'sand-license-proof+jwt'])), 'Wrong token purpose');
securityReject(static fn () => $verify($signLease($claims, 'test-key', ['crit' => ['unhandled']])), 'Unhandled critical header');
securityReject(static fn () => $verify(JWT::encode($claims, $privateBase64, 'HS256', 'test-key')), 'Algorithm confusion');
$otherKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
securityReject(static fn () => $verify(JWT::encode($claims, base64_encode($otherKey), 'EdDSA', 'test-key')), 'Different signing key');

echo "Security 2/4: required lease claims, times and trusted scope\n";
foreach (array_keys($claims) as $name) {
    $missing = $claims;
    unset($missing[$name]);
    securityReject(static fn () => $verify($signLease($missing)), 'Missing lease claim ' . $name);
}
$invalidLeaseClaims = [
    ['iss' => 'https://other.example'],
    ['aud' => 'other.example'],
    ['aud' => ['desktop.example']],
    ['product_code' => 'other-product'],
    ['sub' => '22'],
    ['entitlement_id' => '22'],
    ['activation_id' => '35'],
    ['activation_id' => 34],
    ['cnf' => ['jkt' => JWT::urlsafeB64Encode(random_bytes(32))]],
    ['cnf' => []],
    ['features' => 'editor'],
    ['plan_revision' => 0],
    ['plan_revision' => '1'],
    ['jti' => ''],
    ['iat' => $now + 60, 'nbf' => $now], // Firebase skips iat when nbf is present.
    ['nbf' => $now + 60],
    ['iat' => $now - 60, 'nbf' => $now],
    ['exp' => $now],
    ['exp' => $now - 1],
    ['exp' => $now + 901],
    ['iat' => (string) $now],
    ['nbf' => (string) $now],
    ['exp' => (string) ($now + 45)],
];
foreach ($invalidLeaseClaims as $index => $change) {
    securityReject(static fn () => $verify($signLease(array_replace($claims, $change))), 'Invalid lease case ' . $index);
}
securityReject(
    static fn () => $issuer->verify($lease, 'desktop.example', 'desktop', '21', '34', $jkt, $now + 900),
    'No leeway after exact expiry',
);

echo "Security 3/4: RFC 7638 thumbprint and request-bound proof JWT\n";
$thumbprintJson = '{"crv":"Ed25519","kty":"OKP","x":"' . $jwk['x'] . '"}';
licenseAssert($jkt === JWT::urlsafeB64Encode(hash('sha256', $thumbprintJson, true)), 'RFC 7638 thumbprint');
licenseAssert(InstallationProof::thumbprint(['x' => $jwk['x'], 'crv' => 'Ed25519', 'kty' => 'OKP', 'kid' => 'ignored']) === $jkt, 'JWK metadata and order do not change thumbprint');
securityReject(static fn () => InstallationProof::thumbprint(array_replace($jwk, ['crv' => 'X25519'])), 'Wrong key curve', 400);
securityReject(static fn () => InstallationProof::thumbprint(array_replace($jwk, ['d' => null])), 'Private JWK member', 400);
securityReject(static fn () => InstallationProof::thumbprint(array_replace($jwk, ['x' => $jwk['x'] . '='])), 'Noncanonical key encoding', 400);
$challenge = Challenge::issue($now);
$url = 'https://license.example/api/sand-license/v1/redemptions';
$rawBody = "{\n\"product_code\":\"desktop\",\"code\":\"temporary-test-input\"\n}";
$proofClaims = [
    'htm' => 'POST', 'htu' => $url, 'iat' => $now, 'jti' => bin2hex(random_bytes(16)),
    'nonce' => $challenge['nonce'], 'body_sha256' => hash('sha256', $rawBody), 'product_code' => 'desktop',
];
$signProof = static fn (array $payload, array $headers = []): string =>
    JWT::encode($payload, $privateBase64, 'EdDSA', null, array_replace(['typ' => 'sand-license-proof+jwt'], $headers));
$proof = $signProof($proofClaims);
$verifyProof = static fn (string $token): array =>
    InstallationProof::verify($token, $jwk, 'POST', $url, $rawBody, $challenge['nonce'], 'desktop', time());
licenseAssert($verifyProof($proof)['jti'] === $proofClaims['jti'], 'Real proof verification');
foreach (array_keys($proofClaims) as $name) {
    $missing = $proofClaims;
    unset($missing[$name]);
    securityReject(static fn () => $verifyProof($signProof($missing)), 'Missing proof claim ' . $name);
}
foreach ([
    ['htm' => 'GET'],
    ['htu' => $url . '/'],
    ['htu' => 'https://attacker.example/api/sand-license/v1/redemptions'],
    ['iat' => $now - 61],
    ['iat' => $now + 1, 'nbf' => $now],
    ['iat' => (string) $now],
    ['jti' => ''],
    ['nonce' => Challenge::issue($now)['nonce']],
    ['body_sha256' => hash('sha256', $rawBody . ' ')],
    ['product_code' => 'other-product'],
] as $index => $change) {
    securityReject(static fn () => $verifyProof($signProof(array_replace($proofClaims, $change))), 'Invalid proof case ' . $index);
}
securityReject(static fn () => $verifyProof($signProof($proofClaims, ['typ' => 'JWT'])), 'Wrong proof type');
securityReject(static fn () => $verifyProof($signProof($proofClaims, ['crit' => ['unhandled']])), 'Critical proof header');
securityReject(static fn () => $verifyProof(JWT::encode($proofClaims, $privateBase64, 'HS256', null, ['typ' => 'sand-license-proof+jwt'])), 'Wrong proof algorithm');
securityReject(
    static fn () => InstallationProof::verify($proof, $jwk, 'POST', $url, str_replace("\n", '', $rawBody), $challenge['nonce'], 'desktop', time()),
    'Original body bytes must match',
);
securityReject(
    static fn () => InstallationProof::verify($proof, array_replace($jwk, ['x' => JWT::urlsafeB64Encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))]), 'POST', $url, $rawBody, $challenge['nonce'], 'desktop', time()),
    'Challenge-bound installation key',
);
foreach ([$url . '?secret=x', $url . '#fragment', str_replace('/v1/', '//v1/', $url), str_replace('/v1/', '/%76%31/', $url), str_replace('/v1/', '/../v1/', $url)] as $invalidUrl) {
    $payload = array_replace($proofClaims, ['htu' => $invalidUrl]);
    securityReject(
        static fn () => InstallationProof::verify($signProof($payload), $jwk, 'POST', $invalidUrl, $rawBody, $challenge['nonce'], 'desktop', time()),
        'Ambiguous configured URL',
    );
}
// Endpoint binding prevents cross-purpose proof use; the transaction checks challenge.purpose.
securityReject(
    static fn () => InstallationProof::verify($proof, $jwk, 'POST', 'https://license.example/api/sand-license/v1/leases/renew', $rawBody, $challenge['nonce'], 'desktop', time()),
    'Cross-purpose endpoint proof',
);
$getUrl = 'https://license.example/api/sand-license/v1/entitlements/current';
$getPayload = array_replace($proofClaims, ['htm' => 'GET', 'htu' => $getUrl, 'body_sha256' => hash('sha256', '')]);
licenseAssert(
    InstallationProof::verify($signProof($getPayload), $jwk, 'GET', $getUrl, '', $challenge['nonce'], 'desktop', time())['htm'] === 'GET',
    'Empty body GET proof',
);

echo "Security 4/4: challenge material and shared JWT state preservation\n";
licenseAssert(strlen($challenge['nonce']) === 43, 'Challenge has 256 bits of random material');
licenseAssert($challenge['secret_hash'] === Challenge::digest($challenge['nonce']), 'Persist only challenge digest');
licenseAssert($challenge['expire_time'] === $now + 120, 'Challenge TTL');
licenseAssert(Challenge::issue($now)['nonce'] !== $challenge['nonce'], 'Independent challenge material');
securityReject(static fn () => Challenge::digest('invalid'), 'Malformed challenge');
securityReject(static fn () => Challenge::digest($challenge['nonce'] . '='), 'Padded challenge');
securityReject(static fn () => Challenge::issue(0), 'Invalid challenge clock', 400);
licenseAssert(JWT::$timestamp === $jwtTimestamp && JWT::$leeway === $jwtLeeway, 'No mutation of shared JWT clock or leeway');
sodium_memzero($private);
sodium_memzero($otherKey);
sodium_memzero($keyPair);
printf("Security passed: real JWT/Key/JWK, request bindings and rejection paths; %.3fs; no DB or persistent test keys.\n", microtime(true) - $started);
