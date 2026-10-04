<?php

declare(strict_types=1);

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use SandLicenseExample\ClientFailure;
use SandLicenseExample\LicenseClient;

$options = getopt('', ['autoload:']);
$autoload = $options['autoload'] ?? null;
if (!is_string($autoload) || !str_starts_with($autoload, '/') || !is_file($autoload)) {
    fwrite(STDERR, "Provide --autoload=/existing/host/vendor/autoload.php; no separate Composer installation.\n");
    exit(1);
}
require $autoload;
require __DIR__ . '/LicenseClient.php';
$checks = 0;
function clientAssert(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('Client test failed: ' . $label);
    ++$checks;
}
function clientReject(callable $action, string $label): void
{
    try {
        $action();
    } catch (ClientFailure) {
        clientAssert(true, $label);
        return;
    }
    throw new RuntimeException('Client rejection missing: ' . $label);
}

$directory = '/private/tmp/sand-license-client-test-' . bin2hex(random_bytes(12));
if (!mkdir($directory, 0700)) throw new RuntimeException('Could not create private test directory');
register_shutdown_function(static function () use ($directory): void {
    foreach (['device.key', 'installation.json', 'device2.key', 'state2.json'] as $file) {
        $path = $directory . '/' . $file;
        if (is_file($path) || is_link($path)) unlink($path);
    }
    if (is_dir($directory)) rmdir($directory);
});
$now = time();
$wall = $now;
$monotonic = 100.0;
$offline = false;
$failCatalog = false;
$denyCurrent = false;
$catalogDelay = 0.0;
$catalogCalibrate = false;
$serverPair = sodium_crypto_sign_keypair();
$serverSecret = sodium_crypto_sign_secretkey($serverPair);
$serverJwk = [
    'kty' => 'OKP', 'crv' => 'Ed25519', 'x' => JWT::urlsafeB64Encode(sodium_crypto_sign_publickey($serverPair)),
    'kid' => 'reference-test', 'use' => 'sig', 'alg' => 'EdDSA',
];
$jwks = ['keys' => [$serverJwk]];
$installation = null;
$lastLease = '';
$lastClaims = [];
$calls = [];
// A deterministic protocol fixture; real Firebase JWK/JWT operations verify every proof.
// This is a clock/contract test, not a claim of HTTP/PostgreSQL acceptance.
$transport = static function (string $method, string $url, string $rawBody, array $headers) use (
    &$offline, &$failCatalog, &$denyCurrent, &$installation, &$lastLease, &$lastClaims, &$calls,
    &$catalogDelay, &$catalogCalibrate, &$wall, &$monotonic, $now, $serverSecret, $jwks,
): array {
    if ($offline || ($failCatalog && str_ends_with($url, '/jwks.json'))) {
        throw new ClientFailure('CLIENT_NETWORK_UNAVAILABLE：deterministic transport fixture', true);
    }
    $calls[] = [$method, $url, $rawBody];
    if (str_ends_with($url, '/jwks.json')) {
        $monotonic += $catalogDelay;
        if ($catalogCalibrate) $wall = $now + (int) $catalogDelay;
        return ['status' => 200, 'body' => json_encode($jwks, JSON_THROW_ON_ERROR)];
    }
    $input = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
    if (str_ends_with($url, '/challenges')) {
        $installation = $input['installation_public_key'];
        $data = ['challenge' => JWT::urlsafeB64Encode(random_bytes(32)), 'server_time' => gmdate('c', $now), 'expire_time' => gmdate('c', $now + 120)];
    } else {
        $head = new stdClass();
        $proof = JWT::decode($headers['Sand-License-Proof'], JWK::parseKey($installation, 'EdDSA'), $head);
        clientAssert($head->typ === 'sand-license-proof+jwt' && $head->alg === 'EdDSA', 'standard proof JWT header');
        clientAssert($proof->htm === 'POST' && $proof->htu === $url && $method === 'POST', 'fixed origin, matched path and POST');
        clientAssert($proof->body_sha256 === hash('sha256', $rawBody) && $proof->nonce === $input['challenge'], 'original request bytes and challenge');
        clientAssert(count(get_object_vars($proof)) === 7 && !isset($proof->purpose), 'exact seven proof claims');
        if (str_ends_with($url, '/entitlements/current')) {
            if ($denyCurrent) return ['status' => 200, 'body' => '{"code":401,"message":"SAND_LICENSE_ACTIVATION_UNAVAILABLE: fixture","data":null}'];
            $data = ['state' => 'active', 'entitlement_id' => '21', 'activation_id' => '34', 'features' => ['editor' => true]];
        } elseif (str_ends_with($url, '/activations/release')) {
            $data = ['state' => 'released', 'seat_available_time' => gmdate('c', $now + 900)];
        } else {
            $canonical = json_encode(['crv' => 'Ed25519', 'kty' => 'OKP', 'x' => $installation['x']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $lastClaims = [
                'iss' => 'https://license.example', 'aud' => 'desktop.example', 'sub' => '21',
                'entitlement_id' => '21', 'activation_id' => '34', 'product_code' => 'desktop', 'plan_revision' => 1,
                'iat' => $now, 'nbf' => $now, 'exp' => $now + 900, 'jti' => bin2hex(random_bytes(24)),
                'features' => ['editor' => true, 'exports' => 0], 'cnf' => ['jkt' => JWT::urlsafeB64Encode(hash('sha256', $canonical, true))],
            ];
            $lastLease = JWT::encode($lastClaims, base64_encode($serverSecret), 'EdDSA', 'reference-test');
            $data = ['state' => 'active', 'entitlement_id' => '21', 'activation_id' => '34', 'lease' => $lastLease];
        }
    }
    return ['status' => 200, 'body' => json_encode(['code' => 200, 'data' => $data], JSON_THROW_ON_ERROR)];
};
$wallClock = static function () use (&$wall): int { return $wall; };
$monoClock = static function () use (&$monotonic): float { return $monotonic; };
$make = static fn (): LicenseClient => new LicenseClient(
    'https://license.example', 'https://license.example', 'desktop.example', 'desktop',
    $directory . '/device.key', $directory . '/installation.json', null, $transport, $wallClock, $monoClock,
);

echo "Client tests 1/4: protected storage, real proof and lease APIs\n";
$client = $make();
clientAssert(!$client->canRun(), 'no authority before online start');
$client->start('reference-test-code');
clientAssert($client->canRun(), 'verified online response admits current run');
clientAssert($client->requireFeature('editor') === true, 'feature gate');
clientReject(static fn () => $client->requireFeature('exports'), 'disabled or zero feature');
clientAssert($client->current()['state'] === 'active', 'current rights POST');
$client->renew();
clientAssert((fileperms($directory . '/device.key') & 0777) === 0600, 'owner-only private key');
$state = file_get_contents($directory . '/installation.json');
clientAssert(!str_contains($state, $lastLease) && !str_contains($state, 'reference-test-code') && !str_contains($state, 'secret'), 'no tokens, codes or keys in state');
foreach ($calls as [$method, $url, $body]) {
    clientAssert(!str_contains($url, '?') && !str_contains($url, '#'), 'no challenge or secrets in URL');
}
chmod($directory . '/device.key', 0644);
clearstatcache();
clientReject($make, 'shared key file refusal');
chmod($directory . '/device.key', 0600);
clearstatcache();

echo "Client tests 2/4: complete lease scope and rejection paths\n";
$sign = static fn (array $claims, string $kid = 'reference-test', array $headers = []): string =>
    JWT::encode($claims, base64_encode($serverSecret), 'EdDSA', $kid, $headers);
$verify = static fn (string $token): array => $client->verifyLease($token, $jwks, '21', '34', $now);
clientAssert($verify($lastLease)['sub'] === '21', 'actual JWK lease verification');
foreach (array_keys($lastClaims) as $field) {
    $missing = $lastClaims;
    unset($missing[$field]);
    clientReject(static fn () => $verify($sign($missing)), 'missing ' . $field);
}
foreach ([
    ['iss' => 'https://other.example'], ['aud' => 'other'], ['aud' => ['desktop.example']], ['sub' => '22'],
    ['entitlement_id' => '22'], ['activation_id' => '35'], ['activation_id' => 34], ['product_code' => 'other'],
    ['iat' => $now + 60, 'nbf' => $now], ['nbf' => $now + 60], ['nbf' => $now, 'iat' => $now - 60],
    ['exp' => $now], ['exp' => $now + 901], ['plan_revision' => 0], ['features' => 'all'], ['cnf' => []],
] as $change) {
    clientReject(static fn () => $verify($sign(array_replace($lastClaims, $change))), 'lease binding or interval');
}
clientReject(static fn () => $verify($sign($lastClaims, 'unknown')), 'unknown key');
clientReject(static fn () => $verify($sign($lastClaims, 'reference-test', ['typ' => 'sand-license-proof+jwt'])), 'wrong token purpose');
clientReject(static fn () => $verify($sign($lastClaims, 'reference-test', ['crit' => ['unknown']])), 'critical header');
clientReject(static fn () => $verify(JWT::encode($lastClaims, base64_encode($serverSecret), 'HS256', 'reference-test')), 'algorithm confusion');
clientReject(static fn () => $client->verifyLease($lastLease, ['keys' => [array_replace($serverJwk, ['d' => 'forbidden'])]], '21', '34', $now), 'private material in JWKS');
clientReject(static fn () => $client->verifyLease($lastLease, ['keys' => [$serverJwk, $serverJwk]], '21', '34', $now), 'duplicate key identifiers');

echo "Client tests 3/4: fresh startup, network failure and monotonic cutoff\n";
$offline = true;
$fresh = $make();
clientAssert(!$fresh->canRun(), 'persisted installation never authorizes new process');
clientReject(static fn () => $fresh->start(), 'unreachable fresh startup');
clientAssert(!$fresh->canRun(), 'offline startup failure leaves gate closed');
clientReject(static fn () => $client->renew(), 'existing run network renewal failure');
clientAssert($client->canRun(), 'existing signed lease retained until deadline');
$wall = $now - 3600;
$monotonic = 999.999;
clientAssert($client->canRun(), 'wall rollback cannot extend monotonic deadline');
$monotonic = 1000.0;
clientAssert(!$client->canRun(), 'exact monotonic expiry stops despite clock rollback');
clientReject(static fn () => $client->requireFeature('editor'), 'feature guard at expiration');
// A post-response JWKS outage retains the previous verified lease, never the unverified replacement.
$wall = $now;
$monotonic = 100.0;
$offline = false;
$fresh->start();
$failCatalog = true;
clientReject(static fn () => $fresh->renew(), 'JWKS request failure');
clientAssert($fresh->canRun(), 'catalog outage preserves only prior admitted run');
$failCatalog = false;
$denyCurrent = true;
clientReject(static fn () => $fresh->current(), 'server-side current denial');
clientAssert(!$fresh->canRun(), 'known revocation closes gate immediately');
$denyCurrent = false;

// Real signed 900-second lease: slow request clock -> calibrated verification ->
// subsequent wall rollback must not extend the signed lifetime to 930 seconds.
$wall = $now - 30;
$monotonic = 100.0;
$catalogCalibrate = true;
$fresh->start();
$wall = $now - 3600;
$monotonic = 999.999;
clientAssert($fresh->canRun(), 'slow-clock lease valid just before signed 900-second budget');
foreach ([1000.0, 1029.0, 1030.0] as $instant) {
    $monotonic = $instant;
    clientAssert(!$fresh->canRun(), 'calibrate then rollback never extends signed lifetime at ' . $instant);
}
// Seventeen seconds of real-crypto fixture response/catalog latency consume budget.
$wall = $now;
$monotonic = 100.0;
$catalogDelay = 17.0;
$fresh->start();
$wall = $now - 3600;
$monotonic = 999.999;
clientAssert($fresh->canRun(), 'network delay retains only remaining signed budget');
$monotonic = 1000.0;
clientAssert(!$fresh->canRun(), 'network/JWKS delay is not added to signed deadline');
$wall = $now;
$monotonic = 100.0;
$catalogDelay = 901.0;
clientReject(static fn () => $fresh->start(), 'response/catalog arriving beyond signed expiry');
clientAssert(!$fresh->canRun(), 'already elapsed lease is never admitted');
$catalogDelay = 0.0;
$catalogCalibrate = false;
$wall = $now;
$monotonic = 100.0;

echo "Client tests 4/4: explicit release and shared JWT configuration isolation\n";
$fresh->start();
$fresh->release('reference-release-once');
clientAssert(!$fresh->canRun(), 'explicit release always stops local run');
clientAssert(JWT::$timestamp === null && JWT::$leeway === 0, 'reference never mutates shared JWT clock');
sodium_memzero($serverSecret);
sodium_memzero($serverPair);
echo "Client tests passed: " . $checks . " checks; real JWT crypto, injected clocks/transport only. HTTP and natural expiration are separate live acceptance.\n";
