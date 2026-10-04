<?php

declare(strict_types=1);

/** Mechanical inventories consumed by the standard SandPackage lifecycle. */
$root = dirname(__DIR__);
function payloadInventory(string $directory, string $root): array
{
    $map = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isLink() || !$file->isFile()) throw new RuntimeException('载荷只能包含普通文件');
        $path = substr($file->getPathname(), strlen($root) + 1);
        $map[$path] = hash_file('sha256', $file->getPathname());
    }
    ksort($map, SORT_STRING);
    return $map;
}
try {
    echo "步骤 1/2：更新 app/config 精确清单\n";
    $files = payloadInventory($root . '/app', $root) + payloadInventory($root . '/config', $root);
    ksort($files, SORT_STRING);
    $entries = [];
    foreach ($files as $path => $hash) $entries[] = ['path' => $path, 'sha256' => $hash];
    $json = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
    file_put_contents($root . '/host-payload.json', json_encode(['schema' => 1, 'app' => 'sand-license', 'files' => $entries], $json) . "\n");
    echo "步骤 2/2：登记宿主现有 JWT 栈，不生成重复依赖\n";
    if (file_exists($root . '/plugin/sand-license/vendor')
        || file_exists($root . '/plugin/sand-license/composer.lock')
        || file_exists($root . '/plugin/sand-license/composer.json')) {
        throw new RuntimeException('JWT 由宿主 Tinywan 栈提供，不允许插件独立 Composer/vendor');
    }
    $contract = [
        'schema' => 'sand-license-runtime-payload-v1',
        'kind' => 'reviewed-runtime-payload-inputs',
        'generated_payloads' => new stdClass(),
        'host_runtime_dependencies' => [
            'tinywan/jwt' => ['provider' => 'host-existing-stack', 'verified_version' => '1.15.0'],
            'firebase/php-jwt' => ['provider' => 'tinywan/jwt-transitive', 'compatible_constraint' => '^6.8||^7.0', 'verified_version' => '7.1.1'],
        ],
    ];
    file_put_contents($root . '/release-build-contract.json', json_encode($contract, $json) . "\n");
    echo '成功：' . count($entries) . " 个宿主文件，零插件 JWT/vendor 副本；未连接数据库。\n";
} catch (Throwable $exception) {
    fwrite(STDERR, '失败：' . $exception->getMessage() . "\n");
    exit(1);
}
