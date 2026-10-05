<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use plugin\sandpackage\app\service\HostPayloadManifest;
use plugin\sandpackage\app\service\PluginDependencyPolicy;
use plugin\sandpackage\app\service\PluginServiceCatalogPolicy;
use plugin\sandpackage\app\service\PostgresLifecycleSqlExecutor;
use plugin\sandpackage\app\service\AbnormalPluginCleanup;
use plugin\sandpackage\app\logic\LegacyInstallLogic;

$path = $argv[1] ?? '';
$zip = new ZipArchive();
if ($path === '' || $zip->open($path) !== true) throw new RuntimeException('Usage: php tests/packaging.php /path/sand-license-VERSION.zip');
echo "步骤 1/3：调用真实 SandPackage HostPayloadManifest\n";
$host = HostPayloadManifest::inspectArchive($zip, 'sand-license');
licenseAssert(count($host) > 10, 'Shared runtime kernel is not included');
foreach (['info.ini','config.json','install.sql','update.sql','uninstall.sql','README.md','SOURCE_OF_TRUTH.md','LICENSE','NOTICE','THIRD_PARTY_NOTICES','host-payload.json','release-build-contract.json','plugin/sand-license/app/Bootstrap.php','plugin/sand-license/public/claim/index.html','plugin/sand-license/public/claim/claim.js','plugin/sand-license/public/claim/claim.css','sandadmin-artd/src/views/plugin/sand-license/index/index.vue'] as $file) {
    licenseAssert($zip->locateName($file) !== false, 'Missing advertised deliverable: ' . $file);
}
foreach (['docs/client-integration.md','examples/php-client/LicenseClient.php','examples/php-client/run.php','examples/php-client/live-test.php'] as $file) {
    licenseAssert($zip->locateName($file) !== false, 'Missing consumable client protocol/CLI: ' . $file);
}
foreach (['product','plan','code','entitlement','activation','sku-mapping','fulfillment','membership','event'] as $resource) {
    licenseAssert($zip->locateName('sandadmin-artd/src/views/plugin/sand-license/' . $resource . '/index.vue') !== false,
        'Missing independent management page: ' . $resource);
}
$installSql = (string) $zip->getFromName('install.sql');
$updateSql = (string) $zip->getFromName('update.sql');
$uninstallSql = (string) $zip->getFromName('uninstall.sql');
$installer = (new ReflectionClass(LegacyInstallLogic::class))->newInstanceWithoutConstructor();
$metadata = (new ReflectionMethod(LegacyInstallLogic::class, 'readUploadArchiveMetadata'))->invoke($installer, $path);
$info = parse_ini_string((string) $zip->getFromName('info.ini'), false, INI_SCANNER_TYPED);
licenseAssert(($metadata['app'] ?? null) === 'sand-license'
    && ($info['version'] ?? null) === '0.1.2'
    && version_compare((string) $info['version'], '0.1.0', '>'),
    'Actual package metadata is not an upgrade from published 0.1.0');
licenseAssert(str_contains((string) $zip->getFromName('plugin/sand-license/config/app.php'), "'version' => '0.1.2'"),
    'Runtime version differs from upgrade metadata');
licenseAssert(!preg_match('/\b(?:CREATE|ALTER|DROP)\s+(?:TABLE|DATABASE|SCHEMA)\b/i', $updateSql),
    'Navigation upgrade must not replay or change business schema');
foreach ([$installSql, $updateSql] as $sql) {
    $statements = PostgresLifecycleSqlExecutor::split($sql);
    licenseAssert(count(array_filter($statements, static fn (string $statement): bool =>
        str_contains($statement, 'DO $sand_license_menu$') && str_contains($statement, 'SandLicenseProduct'))) === 1,
        'Actual PostgreSQL parser split the menu dollar body');
}
// Read the provider's real declaration parser without opening a database or
// invoking cleanup. The only PDO operation it needs here is literal quoting.
$cleanupType = new ReflectionClass(AbnormalPluginCleanup::class);
$cleanup = $cleanupType->newInstanceWithoutConstructor();
$cleanupType->getProperty('app')->setValue($cleanup, 'sand-license');
$cleanupType->getProperty('pdo')->setValue($cleanup, new class {
    public function quote(string $value): string { return "'" . str_replace("'", "''", $value) . "'"; }
});
$declaration = $cleanupType->getMethod('parseDeclaration')->invoke($cleanup, $installSql, $uninstallSql);
licenseAssert(count($declaration['tables']) === 15 && $declaration['unproven_tables'] === [],
    'Actual abnormal-cleanup parser cannot prove table ownership');
foreach (['Product','Plan','Code','Entitlement','Activation','SkuMapping','Fulfillment','Membership','Event'] as $page) {
    licenseAssert(str_contains($declaration['menu_condition'], "code = 'SandLicense{$page}'"),
        'Actual cleanup parser omitted independent page: ' . $page);
}
echo "真实生命周期 splitter/异常清理声明 parser：九页面与十五表范围通过；未连接数据库或执行 SQL。\n";
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
