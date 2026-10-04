<?php

declare(strict_types=1);

use SandLicenseExample\ClientFailure;
use SandLicenseExample\LicenseClient;

// A dedicated product/code fixture and directory are required. Never use production codes.
$options = getopt('', ['autoload:', 'origin:', 'issuer:', 'audience:', 'product:', 'directory:', 'ca-file:', 'code-file:']);
foreach (['autoload', 'origin', 'issuer', 'audience', 'product', 'directory', 'ca-file', 'code-file'] as $name) {
    if (!is_string($options[$name] ?? null) || $options[$name] === '') {
        fwrite(STDERR, "Live test requires --{$name}; see docs/client-integration.md. No secrets belong in arguments.\n");
        exit(2);
    }
}
if (!is_file($options['autoload'])) {
    fwrite(STDERR, "Existing host vendor/autoload.php is required.\n");
    exit(2);
}
require $options['autoload'];
require __DIR__ . '/LicenseClient.php';
$socket = null;
$started = hrtime(true) / 1_000_000_000;
try {
    $codePath = $options['code-file'];
    if (!str_starts_with($codePath, '/') || is_link($codePath) || !is_file($codePath)
        || (fileperms($codePath) & 0777) !== 0600 || filesize($codePath) > 1024
        || (function_exists('posix_geteuid') && fileowner($codePath) !== posix_geteuid())
    ) throw new ClientFailure('LIVE_CODE_STORAGE_INVALID：专用测试授权码必须位于当前用户的 0600 普通文件');
    $code = trim((string) file_get_contents($codePath));
    $port = 0;
    $offline = false;
    $transport = static function (string $method, string $url, string $body, array $headers) use (&$offline, &$port, $options): array {
        return LicenseClient::httpsRequest($method, $url, $body, $headers, $options['ca-file'], $offline ? $port : null);
    };
    $makeClient = static fn (): LicenseClient => new LicenseClient(
        $options['origin'], $options['issuer'], $options['audience'], $options['product'],
        $options['directory'] . '/device.key', $options['directory'] . '/installation.json',
        $options['ca-file'], $transport,
    );
    $client = $makeClient();
    fwrite(STDOUT, "[1/4] Real TLS online startup and signed POST current\n");
    $client->start($code);
    sodium_memzero($code);
    $client->current();
    $status = $client->renew();
    if (!$client->canRun() || !is_string($status['lease_expire_time'])) {
        throw new ClientFailure('LIVE_ONLINE_FAILED：未取得可运行的在线租约');
    }
    $expire = (int) strtotime($status['lease_expire_time']);
    if ($expire - time() < 880 || $expire - time() > 900) {
        throw new ClientFailure('LIVE_LEASE_INTERVAL_INVALID：验收要求自然的默认 900 秒租约，不接受人为缩短');
    }
    fwrite(STDOUT, "READY online startup/current/renew; signed exp={$status['lease_expire_time']}; no lease/token printed\n");
    // Darwin may time out on a bound-but-unlistening TCP socket. Select our own
    // ephemeral port, release it, then prove refusal with a public, secret-free GET.
    $socket = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage, STREAM_SERVER_BIND);
    if ($socket === false) throw new ClientFailure('LIVE_SOCKET_FAILED：无法选择本测试临时端口');
    $address = stream_socket_get_name($socket, false);
    $port = is_string($address) ? (int) substr($address, strrpos($address, ':') + 1) : 0;
    fclose($socket);
    $socket = null;
    if ($port < 1) throw new ClientFailure('LIVE_SOCKET_FAILED：无法确定本测试临时端口');
    try {
        LicenseClient::httpsRequest('GET', $options['origin'] . '/api/sand-license/v1/.well-known/jwks.json',
            '', ['Accept' => 'application/json'], $options['ca-file'], $port);
        throw new ClientFailure('LIVE_REFUSAL_PRECHECK_FAILED：已释放的任务端口意外可达');
    } catch (ClientFailure $failure) {
        if (!$failure->transient || !str_contains($failure->getMessage(), '（curl 7）')) throw $failure;
    }
    fwrite(STDOUT, "PRECHECK PASS released task-selected TCP port; public GET only; curl errno=7\n");
    $offline = true;
    fwrite(STDOUT, "[2/4] Real cURL connection refusal; a fresh client must deny startup\n");
    $fresh = $makeClient();
    try {
        $fresh->start();
        throw new ClientFailure('LIVE_FRESH_START_UNSAFE：失联的新启动意外放行');
    } catch (ClientFailure $failure) {
        if (!$failure->transient || !str_contains($failure->getMessage(), '（curl 7）') || $fresh->canRun()) throw $failure;
    }
    fwrite(STDOUT, "PASS fresh startup denied; curl errno=7; original URI/proof/TLS trust unchanged\n");
    fwrite(STDOUT, "[3/4] Existing run: failed renewal may retain only the last signed lease\n");
    try {
        $client->renew();
        throw new ClientFailure('LIVE_REFUSAL_FAILED：独占未监听端口意外可达');
    } catch (ClientFailure $failure) {
        if (!$failure->transient || !str_contains($failure->getMessage(), '（curl 7）') || !$client->canRun()) throw $failure;
    }
    fwrite(STDOUT, "PASS current run retained old signed deadline; curl errno=7; waiting for natural expiration\n");
    $nextHeartbeat = time();
    $lastMinuteAnnounced = false;
    while ($client->canRun()) {
        $remaining = max(0, $expire - time());
        if ($remaining <= 60 && !$lastMinuteAnnounced) {
            fwrite(STDOUT, "AT_REMAINING_60S signed expiration approaching; authority unchanged\n");
            $lastMinuteAnnounced = true;
        }
        if (time() >= $nextHeartbeat) {
            fwrite(STDOUT, "WAIT remaining={$remaining}s; server connection unavailable; same process only\n");
            $nextHeartbeat = time() + 30;
        }
        sleep(1);
    }
    fwrite(STDOUT, "[4/4] Natural signed expiration: run and feature gate are closed\n");
    if (time() < $expire - 1) throw new ClientFailure('LIVE_EARLY_STOP：测试未等到自然租约截止');
    try {
        $client->requireFeature('__expiration_probe__');
        throw new ClientFailure('LIVE_EXPIRATION_UNSAFE：到期后的功能操作意外放行');
    } catch (ClientFailure $failure) {
        if (!str_starts_with($failure->getMessage(), 'CLIENT_FEATURE_UNAVAILABLE')) throw $failure;
    }
    $elapsed = round(hrtime(true) / 1_000_000_000 - $started, 2);
    fwrite(STDOUT, "EXPIRED PASS online/new-start-denial/current-run-natural-expiration; elapsed={$elapsed}s\n");
    fwrite(STDOUT, "Cleanup: task-selected socket released before fault; private client files remain for task-owner cleanup.\n");
} catch (ClientFailure $failure) {
    fwrite(STDERR, "FAIL " . $failure->getMessage() . "\n");
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "FAIL CLIENT_UNEXPECTED_ERROR：检查依赖或配置；错误详情不可包含秘密。\n");
    exit(1);
} finally {
    if (is_resource($socket)) fclose($socket);
}
