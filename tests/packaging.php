<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use plugin\sandpackage\app\service\HostPayloadManifest;
use plugin\sandpackage\app\service\PluginDependencyPolicy;
use plugin\sandpackage\app\service\PluginServiceCatalogPolicy;

$path = $argv[1] ?? '';
$zip = new ZipArchive();
if ($path === '' || $zip->open($path) !== true) throw new RuntimeException('Usage: php tests/packaging.php /path/sand-license-0.1.0.zip');
echo "步骤 1/3：调用真实 SandPackage HostPayloadManifest\n";
$host = HostPayloadManifest::inspectArchive($zip, 'sand-license');
licenseAssert(count($host) > 10, 'Shared runtime kernel is not included');
foreach (['info.ini','config.json','install.sql','update.sql','uninstall.sql','README.md','LICENSE','NOTICE','THIRD_PARTY_NOTICES','host-payload.json','release-build-contract.json','plugin/sand-license/app/Bootstrap.php','plugin/sand-license/public/claim/index.html','plugin/sand-license/public/claim/claim.js','plugin/sand-license/public/claim/claim.css','sandadmin-artd/src/views/plugin/sand-license/index/index.vue'] as $file) {
    licenseAssert($zip->locateName($file) !== false, 'Missing advertised deliverable: ' . $file);
}
foreach (['docs/client-integration.md','examples/php-client/LicenseClient.php','examples/php-client/run.php','examples/php-client/live-test.php'] as $file) {
    licenseAssert($zip->locateName($file) !== false, 'Missing consumable client protocol/CLI: ' . $file);
}
licenseAssert($zip->locateName('examples/php-client/tests.php') === false, 'Source-only client test must not be delivered as runtime');
$config = json_decode((string) $zip->getFromName('config.json'), true, 32, JSON_THROW_ON_ERROR);
licenseAssert(PluginDependencyPolicy::requirements($config, 'sand-license') === ['sand-iam' => '0.8.4'], 'Real dependency policy rejected identity');
licenseAssert(PluginServiceCatalogPolicy::declaration($config, 'sand-license')['service']['code'] === 'sand_license', 'Real service catalog policy rejected declaration');
licenseAssert(hash_equals($config['plugin_dependencies']['sand-iam']['sha256'], hash('sha256', (string) $zip->getFromName('dependencies/sand-iam-0.8.4.zip'))), 'Bundled IAM identity mismatch');
echo "步骤 2/3：核对秘密排除和零重复 JWT 载荷，解出真实候选验证宿主消费\n";
$contract = json_decode((string) $zip->getFromName('release-build-contract.json'), true, 32, JSON_THROW_ON_ERROR);
for ($index = 0; $index < $zip->numFiles; ++$index) {
    $name = $zip->getNameIndex($index);
    licenseAssert(is_string($name) && !str_contains($name, '\\') && !str_contains($name, '..'), 'Unsafe ZIP path');
    licenseAssert(preg_match('~(?:^|/)(?:\.env|node_modules|\.git)(?:/|$)|\.(?:pem|key|log)$~', $name) !== 1, 'Forbidden secret/cache payload');
    licenseAssert(preg_match('~^plugin/sand-license/(?:vendor(?:/|$)|composer\.(?:json|lock)$)~', $name) !== 1, 'Duplicate independent JWT/Composer payload');
}
licenseAssert(($contract['generated_payloads'] ?? null) === [], 'Package should not generate dependency copies');
licenseAssert(($contract['host_runtime_dependencies']['firebase/php-jwt']['compatible_constraint'] ?? null) === '^6.8||^7.0', 'Existing Tinywan transitive compatibility declaration missing');
$temporaryRoot = getenv('TMPDIR') ?: '/private/tmp';
if (!is_dir($temporaryRoot) || !is_writable($temporaryRoot)) $temporaryRoot = '/private/tmp';
$runtimeFixture = tempnam($temporaryRoot, 'sand-license-runtime-package-');
if ($runtimeFixture === false) throw new RuntimeException('Cannot create runtime fixture');
unlink($runtimeFixture);
mkdir($runtimeFixture, 0700);
$runtimeFiles = [];
for ($index = 0; $index < $zip->numFiles; ++$index) {
    $name = (string) $zip->getNameIndex($index);
    if (str_starts_with($name, 'plugin/sand-license/') || str_starts_with($name, 'app/')) $runtimeFiles[] = $name;
}
if (!$zip->extractTo($runtimeFixture, $runtimeFiles)) throw new RuntimeException('Cannot extract candidate runtime fixture');
$hostAutoload = getenv('SAND_LICENSE_TEST_HOST_AUTOLOAD') ?: '';
$old = getenv('SAND_LICENSE_TEST_OLD_JWT_ARCHIVE') ?: '';
if (!is_file($old)) throw new RuntimeException('Set SAND_LICENSE_TEST_OLD_JWT_ARCHIVE to an actual earlier Firebase archive');
foreach (['no-host', 'host-first', 'iam-autoload-first', 'unexpected-classpath', 'mixed-registry', 'foreign-stack', 'metadata-missing', 'metadata-package-missing', 'metadata-foreign-path'] as $mode) {
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/dependency_process.php', $mode, $runtimeFixture . '/plugin/sand-license', $hostAutoload, $old],
        [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes,
    );
    if (!is_resource($process)) throw new RuntimeException('Cannot start candidate runtime check');
    fclose($pipes[0]);
    licenseAssert(proc_close($process) === 0, 'Extracted ZIP failed existing-host/no-host/classpath gate: ' . $mode);
}
echo '运行载荷证据保留于：' . $runtimeFixture . "\n";
echo "步骤 3/3：真实安装器拒绝篡改与缺失的宿主载荷\n";
foreach (['tampered', 'missing'] as $case) {
    $temporary = tempnam($temporaryRoot, 'sand-license-invalid-package-');
    if ($temporary === false) throw new RuntimeException('Cannot create archive fixture');
    $bad = new ZipArchive();
    $bad->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $bad->addFromString('host-payload.json', (string) $zip->getFromName('host-payload.json'));
    foreach ($host as $position => $entry) {
        if ($case === 'missing' && $position === 0) continue;
        $contents = (string) $zip->getFromName($entry['path']);
        if ($case === 'tampered' && $position === 0) $contents .= "\n// tamper\n";
        $bad->addFromString($entry['path'], $contents);
    }
    $bad->close();
    $bad->open($temporary);
    $rejected = false;
    try { HostPayloadManifest::inspectArchive($bad, 'sand-license'); } catch (RuntimeException) { $rejected = true; }
    $bad->close();
    unlink($temporary);
    licenseAssert($rejected, 'Actual installer accepted ' . $case . ' archive');
}
$zip->close();
echo '成功：' . count($host) . " 个宿主文件及完整产品包通过真实 SandPackage 检查；两种非法包被拒绝。未执行安装/SQL。\n";
