<?php

declare(strict_types=1);

// Opt-in real PostgreSQL read-only regression; never creates fixtures, keys or a database.
$started = microtime(true);
$bootstrap = getenv('SAND_LICENSE_TEST_BOOTSTRAP');
$database = getenv('SAND_LICENSE_TEST_DATABASE');
$productCode = getenv('SAND_LICENSE_TEST_PRODUCT_CODE');
if (!$bootstrap || !is_file($bootstrap) || !$database || !$productCode) {
    throw new RuntimeException('Set SAND_LICENSE_TEST_BOOTSTRAP, SAND_LICENSE_TEST_DATABASE and SAND_LICENSE_TEST_PRODUCT_CODE for existing isolated fixtures');
}
require_once dirname(__DIR__) . '/app/SandLicense/Logic/AdminLogic.php';
require_once dirname(__DIR__) . '/app/SandLicense/Logic/Values.php';
require $bootstrap;

use app\SandLicense\Logic\AdminLogic;
use app\SandLicense\Logic\Values;
use think\facade\Db;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
    echo "PASS {$message}\n";
};
echo "Admin projection 1/3: verify explicit target and read-only session\n";
$actual = Db::query('SELECT current_database() AS database_name');
$assert(($actual[0]['database_name'] ?? null) === $database, 'explicit existing database matches');
Db::execute('SET default_transaction_read_only = on');
$mode = Db::query('SHOW transaction_read_only');
$assert(($mode[0]['transaction_read_only'] ?? null) === 'on', 'real connection is read-only');

echo "Admin projection 2/3: real Think ORM timestamp aggregation\n";
$fixtureProduct = Db::table('sand_license_product')->where('code', $productCode)->find();
$assert(is_array($fixtureProduct), 'explicit fixture product exists');
$entitlementIds = Db::table('sand_license_entitlement')->where('product_id', $fixtureProduct['id'])->column('id');
$activationIds = Db::table('sand_license_activation')->whereIn('entitlement_id', $entitlementIds)->column('id');
$activationId = Db::table('sand_license_lease')->whereIn('activation_id', $activationIds)->order('id', 'desc')->value('activation_id');
$assert($activationId !== null, 'existing installed lease fixture is available');
$query = Db::table('sand_license_lease')->where('activation_id', $activationId);
$coerced = (clone $query)->max('expire_time');
$raw = (clone $query)->max('expire_time', false);
$assert(is_float($coerced), 'default max coercion reproduces real ORM numeric behavior');
$assert(is_string($raw), 'force=false preserves PostgreSQL timestamp');
$expected = Values::iso(Values::time($raw));
$assert(str_ends_with($expected, 'Z'), 'preserved expiry converts to UTC ISO');

echo "Admin projection 3/3: authoritative production management projection\n";
$row = Db::table('sand_license_activation')->where('id', $activationId)->find();
$assert(is_array($row), 'existing activation row is available');
$entitlement = Db::table('sand_license_entitlement')->where('id', $row['entitlement_id'])->find();
$assert(is_array($entitlement) && (string) $entitlement['product_id'] === (string) $fixtureProduct['id'],
    'actual lease activation entitlement product lineage matches explicit fixture');
$scope = ['organization_ids' => [(int) $fixtureProduct['organization_id']], 'is_super_admin' => false];
$method = new ReflectionMethod(AdminLogic::class, 'display');
$admin = new AdminLogic();
$result = $method->invoke($admin, 'activation', $row, $scope);
$assert(($result['lease_expire_time'] ?? null) === $expected, 'actual admin display returns exact maximum lease expiry');
$assert(is_string($result['id']), 'activation DTO keeps decimal string identity');
$assert(($result['product_id'] ?? null) === (string) $fixtureProduct['id']
    && ($result['product_name'] ?? null) === $fixtureProduct['name'], 'activation product label resolves within original organizational scope');
$listing = $admin->index('activation', ['keywords' => (string) $activationId, 'product_id' => (string) $fixtureProduct['id']], $scope);
$assert(($listing['data'][0]['product_name'] ?? null) === $fixtureProduct['name'], 'public management index preserves real activation product label');
$detail = $admin->read('activation', (string) $activationId, $scope);
$assert(($detail['product_name'] ?? null) === $fixtureProduct['name'], 'public management read preserves real activation product label');
try {
    $method->invoke($admin, 'activation', $row, ['organization_ids' => [], 'is_super_admin' => false]);
    throw new RuntimeException('Empty organizational scope must not enrich activation product');
} catch (\plugin\sandadmin\exception\ApiException $exception) {
    $assert($exception->getCode() === 400 && str_starts_with($exception->getMessage(), 'SAND_LICENSE_SCOPE_FORBIDDEN'),
        'product label projection rejects empty organizational scope');
}
$assert(!array_key_exists('public_key', $result) && !array_key_exists('secret_hash', $result),
    'management projection does not disclose installation secrets');
$assert((new ReflectionClass(AdminLogic::class))->getFileName() === dirname(__DIR__) . '/app/SandLicense/Logic/AdminLogic.php',
    'projection executes authoritative source, not an installed-copy patch');
printf("PASS admin projection: %d real ORM/PG read-only checks in %.3fs; no HTTP authorization or DB write assertion\n", $checks, microtime(true) - $started);
