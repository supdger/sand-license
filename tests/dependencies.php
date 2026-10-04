<?php

declare(strict_types=1);

$plugin = dirname(__DIR__) . '/plugin/sand-license';
$host = getenv('SAND_LICENSE_TEST_HOST_AUTOLOAD') ?: '';
$old = getenv('SAND_LICENSE_TEST_OLD_JWT_ARCHIVE') ?: '';
$foreign = getenv('SAND_LICENSE_TEST_FOREIGN_HOST_AUTOLOAD') ?: '';
if (!is_file($host) || !is_file($old)) throw new RuntimeException('Provide real host autoload and an actual earlier Firebase archive via documented environment variables');
if (!is_file($foreign) || realpath($foreign) === realpath($host)) throw new RuntimeException('Set SAND_LICENSE_TEST_FOREIGN_HOST_AUTOLOAD to a second installed host vendor/autoload.php');
foreach (['no-host', 'host-first', 'plugin-first', 'iam-autoload-first', 'unexpected-classpath', 'mixed-registry', 'foreign-stack', 'metadata-missing', 'metadata-package-missing', 'metadata-foreign-path'] as $mode) {
    echo '步骤：独立进程 ' . $mode . "\n";
    $process = proc_open([PHP_BINARY, __DIR__ . '/dependency_process.php', $mode, $plugin, $host, $old], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not launch dependency check');
    fclose($pipes[0]);
    if (proc_close($process) !== 0) exit(1);
}
echo "成功：十种独立进程检查通过；正式 IAM 先加载兼容，缺宿主、异常来源、混合栈及非法宿主元数据明确拒绝；未访问数据库。\n";
