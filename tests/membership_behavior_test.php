<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use app\SandLicense\Logic\MembershipLogic;
use plugin\sandadmin\exception\ApiException;

// Calls the same pure projection/period gate used by production current()/paid.
// No in-memory persistence substitute. PostgreSQL transaction acceptance is separate.
$count = 0;
$check = static function (bool $ok, string $label) use (&$count): void {
    licenseAssert($ok, $label);
    ++$count;
    echo "PASS {$label}\n";
};
$reject = static function (callable $action, string $code) use ($check): void {
    try { $action(); } catch (ApiException $e) {
        $check(str_contains($e->getMessage(), $code) && $e->getCode() === 400, $code);
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $code);
};
$at = MembershipLogic::parseTime('2026-10-15T00:00:00Z');
$grant = static fn (string $start, string $expire, string $id, array $features, string $state = 'active'): array => [
    'start_time' => $start, 'expire_time' => $expire, 'entitlement_id' => $id,
    'state' => $state, 'entitlement_state' => 'active', 'plan_snapshot' => ['features' => $features],
];
$a = $grant('2026-10-01 00:00:00', '2026-11-01 00:00:00', '1', ['reports' => true, 'limit' => 10]);
$b = $grant('2026-10-10 00:00:00', '2026-12-01 00:00:00', '2', ['reports' => false, 'limit' => 20]);
$summary = MembershipLogic::summarize([$a, $b], $at);
$check($summary['state'] === 'active', 'overlapping paid cycles remain active');
$check(count($summary['intervals']) === 1 && $summary['intervals'][0]['expire_time'] === '2026-12-01T00:00:00Z', 'overlap interval union');
$check($summary['features'] === ['reports' => true, 'limit' => 20], 'boolean union and integer maximum without invented extra quota');
$check(!isset($summary['entitlement_id']), 'multiple active sources do not invent a single entitlement');
$refundedA = $a;
$refundedA['state'] = 'revoked';
$afterRefund = MembershipLogic::summarize([$refundedA, $b], $at);
$check($afterRefund['intervals'] === [['start_time' => '2026-10-10T00:00:00Z', 'expire_time' => '2026-12-01T00:00:00Z']], 'A refund preserves B fixed interval');
$check($afterRefund['features'] === ['reports' => false, 'limit' => 20], 'refunded source features removed only');
$check($a['start_time'] === '2026-10-01 00:00:00' && $b['expire_time'] === '2026-12-01 00:00:00', 'projection never shifts source dates');
$check(MembershipLogic::summarize([$a], MembershipLogic::parseTime($a['start_time']))['state'] === 'active', 'inclusive start');
$check(MembershipLogic::summarize([$a], MembershipLogic::parseTime($a['expire_time']))['state'] === 'inactive', 'exclusive expiration');
$check(MembershipLogic::summarize([$a], MembershipLogic::parseTime('2026-09-30T23:59:59Z'))['features'] === [], 'future cycle gives no early features');
$cancelled = $a + ['cancelled_time' => '2026-10-11 00:00:00'];
$check(MembershipLogic::summarize([$cancelled], $at)['state'] === 'active', 'cancelled renewal preserves paid current period');
$suspended = $a;
$suspended['entitlement_state'] = 'suspended';
$check(MembershipLogic::summarize([$suspended], $at)['state'] === 'inactive', 'suspended entitlement denies benefits');
$c = $grant('2026-12-01 00:00:00', '2027-01-01 00:00:00', '3', ['future' => true]);
$check(count(MembershipLogic::summarize([$a, $c], $at)['intervals']) === 2, 'gaps between periods remain visible');
$period = MembershipLogic::validatePeriod(['quantity' => 1, 'subject_code' => 'buyer-1',
    'cycle_start_time' => '2026-10-01T00:00:00Z', 'cycle_expire_time' => '2026-11-01T00:00:00Z']);
$check($period['expire'] > $period['start'], 'trusted period parsed in UTC');
$reject(static fn () => MembershipLogic::validatePeriod(['quantity' => 2, 'subject_code' => 'buyer-1']), 'SAND_LICENSE_MEMBERSHIP_INPUT_INVALID');
$reject(static fn () => MembershipLogic::parseTime('2026-02-30T00:00:00Z'), 'SAND_LICENSE_PERIOD_INVALID');
$reject(static fn () => MembershipLogic::parseTime('2026-10-01T00:00:00+08:00'), 'SAND_LICENSE_PERIOD_INVALID');
$invalid = $a;
$invalid['plan_snapshot'] = ['features' => ['limit' => 'unlimited']];
$reject(static fn () => MembershipLogic::summarize([$invalid], $at), 'SAND_LICENSE_FEATURE_INVALID');
$mixed = $a;
$mixed['plan_snapshot'] = ['features' => ['reports' => 1]];
$reject(static fn () => MembershipLogic::summarize([$a, $mixed], $at), 'SAND_LICENSE_FEATURE_INVALID');
$product = ['id' => '9', 'organization_id' => '1', 'application_id' => '2', 'code' => 'member-app',
    'state' => 'published', 'status' => 2, 'delete_time' => null];
$scope = ['product_id' => '9', 'organization_id' => '1', 'application_id' => '2', 'environment_id' => '3'];
MembershipLogic::assertProductScope($product, $scope, 'member-app');
$check(true, 'ownership permits existing disabled product source processing');
$reject(static fn () => MembershipLogic::assertCurrentProductScope($product, $scope, 'member-app'), 'SAND_LICENSE_PRODUCT_UNAVAILABLE');
$reject(static fn () => MembershipLogic::assertCurrentProductScope(array_replace($product, ['status' => 1, 'state' => 'draft']), $scope, 'member-app'), 'SAND_LICENSE_PRODUCT_UNAVAILABLE');
MembershipLogic::assertCurrentProductScope(array_replace($product, ['status' => 1]), $scope, 'member-app');
$check(true, 'current membership requires enabled published product');
try {
    MembershipLogic::assertProductScope($product, array_replace($scope, ['organization_id' => '99']), 'member-app');
    throw new RuntimeException('Another organization must be denied');
} catch (ApiException $e) {
    $check($e->getCode() === 401 && str_contains($e->getMessage(), 'SAND_LICENSE_SCOPE_DENIED'), 'ownership still rejects another organization');
}
$logic = new MembershipLogic();
try {
    $logic->current(['product_code' => 'app', 'subject_code' => 'buyer-2'], ['subject_code' => 'buyer-1'], $at);
    throw new RuntimeException('Subject scope must reject before database access');
} catch (ApiException $e) {
    $check($e->getCode() === 401 && str_contains($e->getMessage(), 'SAND_LICENSE_SCOPE_DENIED'), 'public membership lookup denies another subject before database access');
}
echo "Membership: {$count} passed; PostgreSQL not connected or exercised.\n";
