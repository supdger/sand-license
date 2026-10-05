<?php

declare(strict_types=1);

// behavior-test-gate: static-rule
// Lifecycle inventory and safety checks only. This does not execute SQL or prove PostgreSQL behavior.
$start = microtime(true);
$root = dirname(__DIR__);
$install = file_get_contents($root . '/install.sql');
$uninstall = file_get_contents($root . '/uninstall.sql');
$update = file_get_contents($root . '/update.sql');
if ($install === false || $uninstall === false || $update === false) throw new RuntimeException('Lifecycle file missing');
preg_match_all('/^CREATE TABLE (sand_license_[a-z_]+) \(/m', $install, $created);
preg_match_all('/^DROP TABLE (sand_license_[a-z_]+);$/m', $uninstall, $dropped);
$left = $created[1]; $right = $dropped[1]; sort($left); sort($right);
if (count($left) !== 15 || count(array_unique($left)) !== 15 || $left !== $right) throw new RuntimeException('Lifecycle table inventory differs');
foreach ([$install, $uninstall, $update] as $sql) {
    if (preg_match('/\b(?:CREATE DATABASE|CREATE SCHEMA|CASCADE|sa_[a-z_]+|AUTO_INCREMENT|ENGINE=|USE DATABASE)\b/i', $sql)) throw new RuntimeException('Out-of-scope SQL found');
}
foreach (['sand_license_product_code_unique','sand_license_fulfillment_source_unique','sand_license_fulfillment_event_unique',
    'sand_license_claim_unit_unique','sand_license_redemption_secret_unique','sand_license_activation_key_unique',
    'sand_license_lease_jti_unique','sand_license_challenge_proof_unique','sand_license_request_dedup_unique'] as $constraint) {
    if (!str_contains($install, 'CONSTRAINT ' . $constraint)) throw new RuntimeException('Missing unique invariant: ' . $constraint);
}
if (str_contains($install, 'timestamp with time zone') || !str_contains($install, 'timestamp(0) without time zone')) throw new RuntimeException('UTC baseline changed');
if (!str_contains($install, "DEFAULT 1 CHECK(seat_limit BETWEEN 1 AND 10000)")) throw new RuntimeException('Default seat invariant missing');
$menuBlock = static function (string $sql): string {
    if (preg_match('/-- SAND_LICENSE_MENU_BEGIN\n(.*?)-- SAND_LICENSE_MENU_END/s', $sql, $match) !== 1) {
        throw new RuntimeException('Standard menu lifecycle block missing');
    }
    return $match[1];
};
$menus = $menuBlock($install);
if ($menus !== $menuBlock($update)) throw new RuntimeException('Install/update menu contract differs');
if (!str_contains($menus, "1,'/sand-license'")
    || !str_contains($menus, "2,'index','/plugin/sand-license/index/index'")
    || !str_contains($menus, 'RETURNING id INTO root_id')
    || !str_contains($menus, 'RETURNING id INTO page_id')
    || !str_contains($menus, 'RETURNING id INTO permission_id')
    || !str_contains($menus, 'RETURNING id INTO center_id')) {
    throw new RuntimeException('Menu paths/dynamic IDs contract missing');
}
if (!str_contains($menus, "path IN ('/sand-license','/plugin/sand-license')")
    || !str_contains($menus, "path='/sand-license' AND id<>root_id AND parent_id=0")
    || !str_contains($menus, "WHERE id=root_id AND path='/plugin/sand-license';")) {
    throw new RuntimeException('Legacy root route upgrade/collision guard missing');
}
$pages = [
    'Product' => 'product', 'Plan' => 'plan', 'Code' => 'code',
    'Entitlement' => 'entitlement', 'Activation' => 'activation',
    'SkuMapping' => 'sku-mapping', 'Fulfillment' => 'fulfillment',
    'Membership' => 'membership', 'Event' => 'event',
];
foreach ($pages as $code => $resource) {
    if (!preg_match("/\\('SandLicense{$code}','[^']+','{$resource}','sand_license:"
        . str_replace('-', '_', $resource) . ":index'/", $menus)) {
        throw new RuntimeException('Independent page/menu contract missing: ' . $resource);
    }
}
if (!str_contains($menus, "WHERE menu_id=list_permission_id")
    || !str_contains($menus, 'existing.menu_id=page_id')
    || substr_count($menus, 'INSERT INTO sand_system_role_menu') !== 1
    || preg_match('/UPDATE sand_system_menu SET[^;]*\btype\s*=\s*2/s', $menus)
    || !str_contains($menus, 'parent_id IN (center_id,page_id)')
    || !str_contains($menus, 'CASE WHEN center_status=2 THEN 2 ELSE status END')) {
    throw new RuntimeException('API permission preservation/scoped navigation inheritance missing');
}
$controller = file_get_contents($root . '/plugin/sand-license/app/admin/controller/ManagementController.php');
preg_match_all("/Permission\\('[^']+', '(sand_license:[^']+)'\\)/", $controller, $permissions);
preg_match_all("/\\('(sand_license:[^']+)','[^']+'\\)/", $menus, $registered);
$expected = array_values(array_unique($permissions[1]));
$actual = $registered[1];
sort($expected); sort($actual);
if (count($actual) !== 31 || $expected !== $actual) throw new RuntimeException('Registered permission coverage differs from actual controllers');
$condition = "code = 'SandLicense' OR code = 'SandLicenseCenter'";
foreach (array_keys($pages) as $code) $condition .= " OR code = 'SandLicense{$code}'";
$condition .= " OR code LIKE 'sand\\_license:%' ESCAPE '\\'";
if (substr_count($uninstall, $condition) !== 2
    || strpos($uninstall, 'DELETE FROM sand_system_role_menu') > strpos($uninstall, 'DELETE FROM sand_system_menu')
    || str_contains($uninstall, "slug LIKE 'sand_license:%'")) {
    throw new RuntimeException('Shared menu cleanup scope/order does not match exact-code ownership');
}
echo sprintf("SQL static: 15 owned tables, 2 compatibility anchors/9 pages/31 type=3 controller permissions, identical menu install/update and scoped uninstall; only matching old list grants inherit new navigation; %.3fs. SQL was not executed.\n", microtime(true)-$start);
