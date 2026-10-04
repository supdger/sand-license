<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use app\SandLicense\Logic\ActivationLogic;
use app\SandLicense\Logic\AdminLogic;
use app\SandLicense\Logic\CodeLogic;
use app\SandLicense\Logic\EnrollmentLogic;
use app\SandLicense\Logic\LeaseLogic;
use app\SandLicense\Logic\PlanLogic;
use app\SandLicense\Logic\ProductLogic;
use app\SandLicense\Logic\Records;
use app\SandLicense\Logic\Values;
use plugin\sandadmin\exception\ApiException;

// Executes production policy methods used by the transactional business paths.
// No DB connection is opened; unique constraints, SQL and HTTP are separate acceptance layers.
$start = microtime(true);
$passed = 0;
$check = static function (bool $ok, string $label) use (&$passed): void {
    licenseAssert($ok, $label); ++$passed; echo "PASS {$label}\n";
};
$reject = static function (callable $action, string $label) use ($check): void {
    try { $action(); }
    catch (ApiException $exception) {
        $check($exception->getCode() === 400 && str_starts_with($exception->getMessage(), 'SAND_LICENSE_'), $label);
        return;
    }
    throw new RuntimeException('Expected business rejection: ' . $label);
};
$now = Values::time('2026-01-31T13:12:11Z');
$monthly = ['code' => 'basic', 'name' => '基础版', 'revision' => 1, 'kind' => 'desktop',
    'duration_unit' => 'month', 'duration_value' => 1, 'features' => ['editor' => true, 'exports' => 100]];
echo "Core 1/4: UTC intervals and immutable plan inputs\n";
$snapshot = PlanLogic::snapshot($monthly);
$check($snapshot['seat_limit'] === 1, 'default seat limit is one');
$check(Values::iso(PlanLogic::expires($snapshot, $now)) === '2026-02-28T13:12:11Z', 'Jan 31 clamps to nonleap Feb 28');
$check(Values::iso(PlanLogic::expires($snapshot, Values::time('2028-01-31T13:12:11Z'))) === '2028-02-29T13:12:11Z', 'Jan 31 clamps to leap Feb 29');
$annual = array_replace($snapshot, ['duration_unit' => 'year']);
$check(Values::iso(PlanLogic::expires($annual, Values::time('2028-02-29T13:12:11Z'))) === '2029-02-28T13:12:11Z', 'leap day annual clamp');
$check(PlanLogic::expires(array_replace($snapshot, ['duration_unit' => 'day','duration_value' => 2]), $now) === $now + 172800, 'day duration uses fixed UTC days');
$check(PlanLogic::snapshot(array_replace($monthly, ['seat_limit' => 4]))['seat_limit'] === 4, 'team seat limit configurable');
$reject(static fn () => PlanLogic::snapshot(array_replace($monthly, ['seat_limit' => 0])), 'zero seat limit rejected');
$reject(static fn () => PlanLogic::snapshot(array_replace($monthly, ['duration_value' => 0])), 'zero duration rejected');
$reject(static fn () => PlanLogic::snapshot(array_replace($monthly, ['features' => ['editor' => 'true']])), 'untyped feature rejected');
$reject(static fn () => Values::time('2026-02-31T00:00:00Z'), 'invalid date cannot overflow');
$reject(static fn () => Values::time('next Monday'), 'relative dates rejected');
ProductLogic::assertPublished(['state'=>'published','status'=>1]);
$check(true, 'published enabled product can serve runtime');
$reject(static fn () => ProductLogic::assertPublished(['state'=>'draft','status'=>1]), 'draft product cannot issue or redeem');
$reject(static fn () => ProductLogic::assertPublished(['state'=>'published','status'=>2]), 'disabled product cannot issue or renew');
$identity = ['state'=>'published','organization_id'=>1,'code'=>'desktop','audience'=>'desktop.example'];
ProductLogic::assertEditableIdentity(array_replace($identity, ['state'=>'draft']), array_replace($identity, ['code'=>'changed']));
$check(true, 'draft identity editable before publish');
$reject(static fn () => ProductLogic::assertEditableIdentity($identity, array_replace($identity, ['code'=>'changed'])), 'published product code immutable');
$reject(static fn () => ProductLogic::assertEditableIdentity($identity, array_replace($identity, ['audience'=>'other'])), 'published product audience immutable');
$reject(static fn () => ProductLogic::assertEditableIdentity(array_replace($identity, ['application_id'=>'9']), array_replace($identity, ['application_id'=>'10'])), 'published application reference immutable');
$reject(static fn () => ProductLogic::assertEditableIdentity(array_replace($identity, ['application_id'=>'9']), $identity), 'published application reference cannot be cleared');
ProductLogic::assertEditableIdentity(array_replace($identity, ['state'=>'draft','application_id'=>'9']), array_replace($identity, ['application_id'=>'10']));
$check(true, 'draft application reference may change with fresh IAM validation');
$check(AdminLogic::pagination(['page'=>2,'limit'=>50]) === [2,50], 'UI page and limit consumed');
$check(AdminLogic::pagination(['page'=>0,'limit'=>1000]) === [1,100], 'pagination limits bounded');
ProductLogic::assertSameProduct(['product_id'=>'9'], '9');
$check(true, 'locked configuration retains authorized hint product');
$reject(static fn () => ProductLogic::assertSameProduct(['product_id'=>'10'], '9'), 'changed product after hint rejected');
$reject(static fn () => ProductLogic::assertSameProduct([], '9'), 'missing product after hint rejected');

echo "Core 2/4: active seats and already signed lease drain\n";
$active = ['state' => 'active', 'seat_available_time' => null];
$released = ['state' => 'released', 'seat_available_time' => Values::sqlTime($now + 900)];
$check(ActivationLogic::occupied([$active, $released], $now) === 2, 'released seat remains occupied until lease exp');
$check(ActivationLogic::occupied([$released], $now + 899) === 1, 'no early reuse');
$check(ActivationLogic::occupied([$released], $now + 900) === 0, 'seat available at exact expiry');
$reject(static fn () => ActivationLogic::assertSeat([$released], 1, $now), 'last frozen seat cannot be stolen');
ActivationLogic::assertSeat([$released], 2, $now);
$check(true, 'team second available seat accepted');
$check(ActivationLogic::releaseTime([['expire_time' => Values::sqlTime($now + 300)], ['expire_time' => Values::sqlTime($now + 800)]], $now) === $now + 800, 'release retains latest signed lease');
$check(ActivationLogic::releaseTime([['expire_time' => Values::sqlTime($now - 1)]], $now) === $now, 'expired lease needs no waiting');
$ticket = ['state'=>'issued','available_time'=>Values::sqlTime($now+900),'expire_time'=>Values::sqlTime($now+1000)];
$reject(static fn () => EnrollmentLogic::assertAvailable($ticket, $now+899), 'ticket respects old lease waiting');
EnrollmentLogic::assertAvailable($ticket, $now+900);
$check(true, 'ticket can enroll at available time');
$reject(static fn () => EnrollmentLogic::assertAvailable(array_replace($ticket, ['state'=>'consumed']), $now+900), 'consumed ticket cannot enroll again');
$reject(static fn () => EnrollmentLogic::assertUnused(array_replace($ticket, ['state'=>'revoked']), $now), 'revoked ticket cannot explicitly reissue');
$reject(static fn () => EnrollmentLogic::assertUnused($ticket, $now+1000), 'expired ticket cannot reissue');
$check(EnrollmentLogic::expireTime(['expire_time'=>Values::sqlTime($now+1000)], $now+900) === $now+1000, 'ticket capped at entitlement expiry');
$reject(static fn () => EnrollmentLogic::expireTime(['expire_time'=>Values::sqlTime($now+900)], $now+900), 'no new ticket when seat drains after entitlement expiry');
$ownTicket = $ticket + ['source_activation_id'=>'7'];
EnrollmentLogic::assertReissueAuthority($ownTicket, ['id'=>'7','state'=>'released']);
$check(true, 'released installation can recover its own replacement ticket');
$reject(static fn () => EnrollmentLogic::assertReissueAuthority($ticket + ['source_activation_id'=>null], ['id'=>'7','state'=>'released']), 'released installation cannot recover extra team ticket');
$reject(static fn () => EnrollmentLogic::assertReissueAuthority($ownTicket, ['id'=>'8','state'=>'active']), 'installation cannot recover another installation ticket');
$reject(static fn () => EnrollmentLogic::assertReissueAuthority($ownTicket, ['id'=>'7','state'=>'revoked']), 'revoked installation cannot recover a ticket');
EnrollmentLogic::assertReissueAuthority($ticket + ['source_activation_id'=>null], ['id'=>'7','state'=>'active']);
$check(true, 'active installation can explicitly recover an extra ticket');

echo "Core 3/4: online lease expiry and domain revocation\n";
$entitlement = ['state' => 'active', 'start_time' => Values::sqlTime($now - 1), 'expire_time' => Values::sqlTime($now + 1000)];
$check(LeaseLogic::expireTime($entitlement, $now) === $now + 900, 'lease caps at 15 minutes');
$check(LeaseLogic::expireTime(array_replace($entitlement, ['expire_time' => Values::sqlTime($now + 15)]), $now) === $now + 15, 'lease stops at entitlement expiry');
$reject(static fn () => LeaseLogic::assertActive($entitlement, ['state' => 'released'], $now), 'released device cannot renew');
$reject(static fn () => LeaseLogic::assertActive(array_replace($entitlement, ['state' => 'revoked']), $active, $now), 'revoked entitlement cannot renew');
$reject(static fn () => LeaseLogic::assertActive(array_replace($entitlement, ['state' => 'suspended']), $active, $now), 'suspended entitlement cannot renew');
$reject(static fn () => LeaseLogic::assertActive(array_replace($entitlement, ['start_time' => Values::sqlTime($now + 1)]), $active, $now), 'future entitlement not active');
$reject(static fn () => LeaseLogic::assertActive(array_replace($entitlement, ['expire_time' => Values::sqlTime($now)]), $active, $now), 'expiry exact boundary rejected');

echo "Core 4/4: one-time secret and restricted retry policies\n";
$codes = new CodeLogic(str_repeat('p', 32));
$secret = $codes->generateSecret();
$check(strlen($secret) === 48 && ctype_xdigit($secret), 'code entropy is 192 bits');
$check($secret !== $codes->generateSecret(), 'new code independent');
$check(strlen($codes->hashSecret($secret)) === 64 && !str_contains($codes->hashSecret($secret), $secret), 'only lookup HMAC persisted');
$check($codes->hashSecret($secret) !== (new CodeLogic(str_repeat('q',32)))->hashSecret($secret), 'pepper bound lookup');
$reject(static fn () => new CodeLogic('short'), 'missing or short pepper denied');
$code = ['state' => 'issued', 'entitlement_id' => null, 'expire_time' => Values::sqlTime($now + 300)];
CodeLogic::assertReissuable($code, $now);
$check(true, 'unredeemed code can explicitly reissue');
$reject(static fn () => CodeLogic::assertReissuable(array_replace($code, ['state' => 'redeemed','entitlement_id' => '1']), $now), 'redeemed code cannot reissue');
$reject(static fn () => CodeLogic::assertReissuable(array_replace($code, ['state' => 'revoked']), $now), 'revoked code cannot reissue');
$reject(static fn () => CodeLogic::assertReissuable(array_replace($code, ['expire_time' => Values::sqlTime($now)]), $now), 'expired code cannot reissue');
$claimSource = ['id'=>'11', 'product_id'=>'2', 'state'=>'paid'];
$claimRecord = ['id'=>'12', 'fulfillment_id'=>'11', 'redemption_code_id'=>'13', 'state'=>'claimed'];
$claimCode = ['id'=>'13', 'product_id'=>'2', 'claim_id'=>'12'];
CodeLogic::assertClaimBinding($claimSource, $claimRecord, $claimCode, '2');
$check(true, 'locked paid source claim and current code binding accepted');
CodeLogic::assertClaimBinding($claimSource, array_replace($claimRecord, ['state'=>'redeemed']), $claimCode, '2');
$check(true, 'redeemed binding remains eligible for separate live idempotency gate');
$reject(static fn () => CodeLogic::assertClaimBinding(array_replace($claimSource, ['state'=>'refunded']), $claimRecord, $claimCode, '2'), 'refund terminal denies old claim redemption and retries');
$reject(static fn () => CodeLogic::assertClaimBinding(array_replace($claimSource, ['state'=>'chargeback']), $claimRecord, $claimCode, '2'), 'chargeback terminal denies old claim redemption');
$reject(static fn () => CodeLogic::assertClaimBinding(array_replace($claimSource, ['product_id'=>'3']), $claimRecord, $claimCode, '2'), 'source cannot cross locked product');
$reject(static fn () => CodeLogic::assertClaimBinding($claimSource, $claimRecord, array_replace($claimCode, ['product_id'=>'3']), '2'), 'code cannot cross locked product');
$reject(static fn () => CodeLogic::assertClaimBinding($claimSource, array_replace($claimRecord, ['fulfillment_id'=>'14']), $claimCode, '2'), 'locked claim must retain hinted source');
$reject(static fn () => CodeLogic::assertClaimBinding($claimSource, $claimRecord, array_replace($claimCode, ['claim_id'=>'14']), '2'), 'locked code must retain claim association');
$reject(static fn () => CodeLogic::assertClaimBinding($claimSource, array_replace($claimRecord, ['redemption_code_id'=>'14']), $claimCode, '2'), 'old code loses explicit reissue race');
$reject(static fn () => CodeLogic::assertClaimBinding($claimSource, array_replace($claimRecord, ['state'=>'revoked']), $claimCode, '2'), 'revoked claim cannot redeem');
$reject(static fn () => CodeLogic::assertClaimBinding(['state'=>'paid','product_id'=>'2'], ['state'=>'claimed'], ['product_id'=>'2'], '2'), 'missing locked binding fields fail closed');
$check(Records::fingerprint(['plan_id'=>'1','request_id'=>'a','challenge'=>'x']) === Records::fingerprint(['challenge'=>'y','request_id'=>'b','plan_id'=>'1']), 'request security material not commercial content');
$check(Records::fingerprint(['plan_id'=>'1']) !== Records::fingerprint(['plan_id'=>'2']), 'changed business content conflicts');
$check(Values::boolean(false, 'ticket') === false && Values::boolean(true, 'ticket') === true, 'ticket accepts exact JSON boolean values');
$reject(static fn () => Values::boolean('false', 'ticket'), 'string false cannot issue a ticket');
$reject(static fn () => Values::boolean('true', 'ticket'), 'string true rejected instead of coercion');
$reject(static fn () => Values::boolean([], 'ticket'), 'array boolean rejected');
$reject(static fn () => Values::boolean(1, 'ticket'), 'numeric boolean rejected');
$check(Records::fingerprint(['activation_id'=>'7','reason'=>'reset','issue_enrollment_ticket'=>false]) !== Records::fingerprint(['activation_id'=>'7','reason'=>'reset','issue_enrollment_ticket'=>true]), 'reset ticket intent participates in idempotency content');
Values::assertOrganization(['organization_ids'=>[1]], 1);
$check(true, 'organization membership accepted');
$reject(static fn () => Values::assertOrganization(['organization_ids'=>[]], 1), 'empty scope not superadmin');
$reject(static fn () => Values::assertOrganization(['organization_ids'=>null], 1), 'null scope requires verified superadmin flag');
Values::assertOrganization(['organization_ids'=>null,'is_super_admin'=>true], 2);
$check(true, 'trusted superadmin scope accepted');
$licensingSource = (string) file_get_contents(__DIR__ . '/../app/SandLicense/Logic/LicensingLogic.php');
licenseAssert(str_contains($licensingSource, "\$expectedMethod = 'POST';")
    && !str_contains($licensingSource, "\$purpose === 'current' ? 'GET'"),
    'All installation proof routes must keep the fixed POST domain gate');
echo "Core protocol static: fixed POST domain gate passed; actual current body/proof binding belongs to HTTP acceptance.\n";
$redeemStart = strpos($licensingSource, 'public function redeem(');
$redeemEnd = strpos($licensingSource, 'public function renew(', $redeemStart);
$redeemSource = substr($licensingSource, $redeemStart, $redeemEnd - $redeemStart);
$productLock = strpos($redeemSource, '$product = ProductLogic::byCode(');
$sourceLock = strpos($redeemSource, '$source = Db::table(');
$claimLock = strpos($redeemSource, '$claim = Db::table(');
$codeLock = strpos($redeemSource, '$code = Db::table(');
$bindingCheck = strpos($redeemSource, 'CodeLogic::assertClaimBinding(');
$retryCheck = strpos($redeemSource, '$retry = Records::retry(');
$entitlementLock = strpos($redeemSource, '$entitlement = Db::table(');
licenseAssert($productLock !== false && $sourceLock !== false && $claimLock !== false && $codeLock !== false
    && $bindingCheck !== false && $retryCheck !== false && $entitlementLock !== false
    && $productLock < $sourceLock && $sourceLock < $claimLock && $claimLock < $codeLock
    && $codeLock < $bindingCheck && $bindingCheck < $retryCheck && $retryCheck < $entitlementLock,
    'Claim redemption must use product -> source -> claim -> code -> binding -> retry -> entitlement order');
echo "Core lock-order static: claim redemption ordered and locked binding precedes retry; actual FK/deadlock scheduling belongs to PostgreSQL acceptance.\n";
echo sprintf("Core: %d policy checks passed in %.3fs. PostgreSQL transactions/HTTP not exercised.\n", $passed, microtime(true)-$start);
