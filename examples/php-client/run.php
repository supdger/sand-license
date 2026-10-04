<?php

declare(strict_types=1);

use SandLicenseExample\ClientFailure;
use SandLicenseExample\LicenseClient;

$options = getopt('', [
    'autoload:', 'origin:', 'issuer:', 'audience:', 'product:', 'directory:',
    'ca-file:', 'code-file:', 'request-id:', 'help',
], $next);
$action = $argv[$next] ?? 'start';
if (isset($options['help'])) {
    echo "PHP 参考客户端：每次启动在线签租约，设备私钥仅存在当前用户独占目录。\n";
    echo "php run.php --autoload=/现有宿主/vendor/autoload.php --origin=https://license.example.com\n";
    echo "  --issuer=https://license.example.com --audience=运营提供的受众 --product=产品代码\n";
    echo "  --directory=/当前用户0700目录 [--code-file=/首次授权码0600文件] [--ca-file=/测试CA] start|watch|current|release\n";
    echo "watch 每5分钟尝试续签，网络失败在当前租约到期时停止；release 还需 --request-id=本次释放稳定请求标识。\n";
    exit(0);
}
try {
    foreach (['autoload', 'origin', 'issuer', 'audience', 'product', 'directory'] as $name) {
        if (!is_string($options[$name] ?? null) || $options[$name] === '') {
            throw new RuntimeException('CLIENT_CONFIGURATION_REQUIRED：缺少 --' . $name . '，运行 --help 查看来源');
        }
    }
    if (!str_starts_with($options['autoload'], '/') || !is_file($options['autoload'])) {
        throw new RuntimeException('CLIENT_DEPENDENCY_REQUIRED：提供现有宿主 vendor/autoload.php 的绝对路径，无需另装依赖');
    }
    require $options['autoload'];
    require __DIR__ . '/LicenseClient.php';
    $directory = realpath($options['directory']);
    if ($directory === false) throw new RuntimeException('CLIENT_PRIVATE_STORAGE_REQUIRED：先创建当前用户独占的 0700 目录');
    $client = new LicenseClient(
        $options['origin'], $options['issuer'], $options['audience'], $options['product'],
        $directory . '/device.key', $directory . '/installation.json', $options['ca-file'] ?? null,
    );
    $code = null;
    if (isset($options['code-file'])) {
        $path = $options['code-file'];
        if (!is_string($path) || !str_starts_with($path, '/') || is_link($path) || !is_file($path)
            || (fileperms($path) & 0777) !== 0600 || filesize($path) > 1024
            || (function_exists('posix_geteuid') && fileowner($path) !== posix_geteuid())
        ) throw new RuntimeException('CLIENT_CODE_FILE_INVALID：首次授权码必须是当前用户的普通 0600 文件');
        $code = trim((string) file_get_contents($path));
    }
    if (!in_array($action, ['start', 'watch', 'current', 'release'], true)) {
        throw new RuntimeException('CLIENT_COMMAND_INVALID：命令为 start、watch、current 或 release');
    }
    if ($action === 'release') {
        if (!is_string($options['request-id'] ?? null) || $options['request-id'] === '') {
            throw new RuntimeException('CLIENT_REQUEST_ID_REQUIRED：释放需 --request-id，响应丢失后沿用同一标识重试');
        }
        echo "步骤 1/1：证明当前安装并释放；本地立即停止许可功能。\n";
        $result = $client->release($options['request-id']);
        echo '释放结果：' . ($result['state'] ?? 'unknown') . '；席位可用时间：' . ($result['seat_available_time'] ?? '请查询客服') . "\n";
        exit(0);
    }
    echo "步骤 1/2：在线获取新租约并验证签名、产品、安装及截止时间。\n";
    $client->start($code);
    if ($code !== null) sodium_memzero($code);
    echo "步骤 2/2：读取服务端当前权益（新挑战、原始 JSON 签名）。\n";
    $current = $client->current();
    echo '许可生效：' . $options['product'] . '；租约截止：' . $client->status()['lease_expire_time'] . '；当前状态：' . ($current['state'] ?? 'unknown') . "\n";
    if ($action !== 'watch') {
        echo "本次接入检查成功；真实应用须在同一运行进程每次功能操作前检查 canRun/requireFeature。\n";
        exit(0);
    }
    $nextRetry = 0.0;
    $nextStatus = 0.0;
    while ($client->canRun()) {
        $monotonic = hrtime(true) / 1_000_000_000;
        if ($monotonic >= $nextStatus) {
            echo '运行中：当前租约截止 ' . $client->status()['lease_expire_time'] . "；每次受保护操作仍须检查运行门禁。\n";
            $nextStatus = $monotonic + 30;
        }
        if ($client->renewalDue() && $monotonic >= $nextRetry) {
            try {
                echo "续签：申请新挑战与租约。\n";
                $client->renew();
                echo '续签成功，当前截止：' . $client->status()['lease_expire_time'] . "\n";
            } catch (ClientFailure $failure) {
                fwrite(STDERR, $failure->getMessage() . "\n");
                if (!$failure->transient) break;
                $nextRetry = $monotonic + 15;
            }
        }
        sleep(1);
    }
    fwrite(STDERR, "许可功能已停止：最后签名租约到期或服务端拒绝。恢复联网后重新启动取得新租约。\n");
    exit(1);
} catch (Throwable $failure) {
    // Our fixed errors are safe. Unexpected framework errors never print arguments or traces.
    $message = str_starts_with($failure->getMessage(), 'CLIENT_') || str_starts_with($failure->getMessage(), 'SAND_LICENSE_')
        ? $failure->getMessage() : 'CLIENT_FAILED：接入失败，请检查依赖、文件权限与服务配置';
    fwrite(STDERR, $message . "\n");
    exit(1);
}
