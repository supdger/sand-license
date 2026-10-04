<?php

declare(strict_types=1);

/** Builds one installable package, including exact host payload and pinned IAM. */
$started = microtime(true);
$root = dirname(__DIR__);
$arguments = getopt('', ['output:', 'iam-bundle:']);
$output = $arguments['output'] ?? null;
$iam = $arguments['iam-bundle'] ?? null;

try {
    if (!is_string($output) || !is_dir($output) || !is_writable($output) || is_link($output)) {
        throw new RuntimeException('用法：php tools/build-package.php --output=/已建输出目录 --iam-bundle=/锁定的sand-iam-0.8.4.zip');
    }
    $config = json_decode((string) file_get_contents($root . '/config.json'), true, 32, JSON_THROW_ON_ERROR);
    if (!is_string($iam) || !is_file($iam) || is_link($iam)
        || !hash_equals($config['plugin_dependencies']['sand-iam']['sha256'], (string) hash_file('sha256', $iam))) {
        throw new RuntimeException('SandIAM 0.8.4 依赖包缺失或摘要不符，请提供锁定公开工件');
    }
    $target = rtrim((string) realpath($output), '/') . '/sand-license-0.1.0.zip';
    if (file_exists($target) || is_link($target)) throw new RuntimeException('输出包已存在，拒绝覆盖；请选择新输出目录');
    echo "步骤 1/3：核对源码清单和固定依赖\n";
    $manifest = json_decode((string) file_get_contents($root . '/host-payload.json'), true, 32, JSON_THROW_ON_ERROR);
    $hostMap = [];
    foreach ($manifest['files'] as $entry) {
        if (!hash_equals($entry['sha256'], (string) hash_file('sha256', $root . '/' . $entry['path']))) {
            throw new RuntimeException('源码变化后须先刷新 host-payload.json：' . $entry['path']);
        }
        $hostMap[$entry['path']] = $entry['sha256'];
    }
    $files = [];
    foreach (['plugin/sand-license', 'app', 'config', 'sandadmin-artd/src/views/plugin/sand-license'] as $directory) {
        if (!is_dir($root . '/' . $directory)) throw new RuntimeException('缺少完整产品载荷：' . $directory);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry->isLink() || !$entry->isFile()) throw new RuntimeException('包不接受符号链接或特殊文件');
            $relative = substr($entry->getPathname(), strlen($root) + 1);
            if (preg_match('~^plugin/sand-license/(?:vendor(?:/|$)|composer\.(?:json|lock)$)~', $relative)) {
                throw new RuntimeException('不允许独立 JWT/Composer 载荷：' . $relative);
            }
            if (preg_match('~(?:^|/)(?:\.env(?:\..*)?|node_modules|\.git|\.DS_Store)(?:/|$)|\.(?:log|pem|key)$~', $relative)) {
                throw new RuntimeException('包包含禁止交付内容：' . $relative);
            }
            $files[$relative] = $entry->getPathname();
        }
    }
    foreach (['info.ini', 'config.json', 'install.sql', 'update.sql', 'uninstall.sql', 'README.md', 'CHANGELOG.md', 'LICENSE', 'NOTICE', 'THIRD_PARTY_NOTICES', 'host-payload.json', 'release-build-contract.json'] as $name) {
        if (!is_file($root . '/' . $name) || is_link($root . '/' . $name)) throw new RuntimeException('缺少根文件：' . $name);
        $files[$name] = $root . '/' . $name;
    }
    // Developer-facing client assets are distributable source, not installed
    // server application paths. Never sweep tests, state or client keys into ZIP.
    foreach (['docs/client-integration.md','examples/php-client/LicenseClient.php','examples/php-client/run.php','examples/php-client/live-test.php'] as $name) {
        if (!is_file($root . '/' . $name) || is_link($root . '/' . $name)) throw new RuntimeException('缺少完整客户端接入载荷：' . $name);
        $files[$name] = $root . '/' . $name;
    }
    $actual = [];
    foreach ($files as $name => $path) {
        if (str_starts_with($name, 'app/') || str_starts_with($name, 'config/')) $actual[$name] = hash_file('sha256', $path);
    }
    ksort($actual); ksort($hostMap);
    if ($actual !== $hostMap) throw new RuntimeException('宿主清单与实际源码集合不一致');
    $contract = json_decode((string) file_get_contents($root . '/release-build-contract.json'), true, 32, JSON_THROW_ON_ERROR);
    if (($contract['generated_payloads'] ?? null) !== []
        || ($contract['host_runtime_dependencies']['tinywan/jwt']['provider'] ?? null) !== 'host-existing-stack'
        || ($contract['host_runtime_dependencies']['firebase/php-jwt']['compatible_constraint'] ?? null) !== '^6.8||^7.0') {
        throw new RuntimeException('须刷新 release-build-contract.json 以登记宿主已有 JWT 栈');
    }
    $files['dependencies/sand-iam-0.8.4.zip'] = $iam;
    ksort($files);
    if (count($files) > 2048 || array_sum(array_map('filesize', $files)) > 67108864) throw new RuntimeException('包超过宿主文件数/解压大小限制');
    echo '步骤 2/3：生成单 ZIP（' . count($files) . " 个文件）\n";
    $zip = new ZipArchive();
    if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('无法创建输出包');
    foreach ($files as $name => $path) {
        if (!$zip->addFile($path, $name)) throw new RuntimeException('无法加入包文件：' . $name);
    }
    if (!$zip->close()) throw new RuntimeException('ZIP 写入失败');
    echo "步骤 3/3：重新读取包并核对每个文件摘要\n";
    if ($zip->open($target) !== true || $zip->numFiles !== count($files)) throw new RuntimeException('ZIP 文件集合不完整');
    foreach ($files as $name => $path) {
        $contents = $zip->getFromName($name);
        if (!is_string($contents) || !hash_equals(hash_file('sha256', $path), hash('sha256', $contents))) throw new RuntimeException('ZIP 摘要不一致：' . $name);
    }
    $zip->close();
    if (filesize($target) > 5242880) throw new RuntimeException('ZIP 超过宿主 5MiB 上限');
    echo '成功：' . $target . "\nSHA-256：" . hash_file('sha256', $target) . "\n耗时：" . round(microtime(true) - $started, 2) . " 秒；未执行安装或 SQL。\n";
} catch (Throwable $exception) {
    fwrite(STDERR, '失败：' . $exception->getMessage() . "\n耗时：" . round(microtime(true) - $started, 2) . " 秒\n");
    exit(1);
}
