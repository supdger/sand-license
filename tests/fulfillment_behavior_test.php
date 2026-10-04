<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use app\SandLicense\Logic\FulfillmentLogic;
use app\SandLicense\Logic\CodeLogic;
use app\SandLicense\Logic\MembershipLogic;
use app\SandLicense\Logic\Records;
use plugin\sandadmin\exception\ApiException;

// Same event/source/claim policy used in real transactional methods.
// This does not claim to exercise unique constraints, locks, or database writes.
$count = 0;
$check = static function (bool $ok, string $label) use (&$count): void {
    licenseAssert($ok, $label); ++$count; echo "PASS {$label}\n";
};
$reject = static function (callable $action, string $code) use ($check): void {
    try { $action(); } catch (ApiException $e) {
        $check(str_contains($e->getMessage(), $code) && $e->getCode() === 400, $code);
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $code);
};
$input = ['product_code' => 'desktop', 'channel_code' => 'shop', 'event_id' => 'event-1', 'event_type' => 'paid',
    'order_id' => 'order-1', 'order_item_id' => 'item-1', 'payment_cycle_id' => 'cycle-1', 'sku_code' => 'sku-1',
    'quantity' => 2, 'request_id' => 'request-1'];
FulfillmentLogic::validateEvent($input);
$check(true, 'paid event validated without opening DB');
$check(FulfillmentLogic::nextState('pending', 'paid') === 'paid', 'first paid transitions pending');
$check(FulfillmentLogic::nextState('paid', 'paid') === 'paid', 'duplicate payment does not transition again');
$check(FulfillmentLogic::nextState('pending', 'refunded') === 'refunded', 'refund before payment leaves tombstone');
$check(FulfillmentLogic::nextState('refunded', 'paid') === 'refunded', 'later paid cannot revive refund');
$check(FulfillmentLogic::nextState('chargeback', 'paid') === 'chargeback', 'later paid cannot revive chargeback');
$check(FulfillmentLogic::nextState('paid', 'cancelled') === 'paid', 'renewal cancellation does not revoke current paid');
$check(FulfillmentLogic::nextState('pending', 'cancelled') === 'pending', 'early cancellation is a fact separate from paid');
$disabledProduct = ['state' => 'published', 'status' => 2];
foreach (['refunded', 'chargeback', 'cancelled', 'paid'] as $existingEvent) {
    FulfillmentLogic::assertNewSaleAllowed($disabledProduct, 'paid', $existingEvent);
    $check(true, 'disabled existing paid source permits ' . $existingEvent . ' policy');
}
$reject(static fn () => FulfillmentLogic::assertNewSaleAllowed(['state' => 'draft', 'status' => 1], 'pending', 'paid'), 'SAND_LICENSE_PRODUCT_UNAVAILABLE');
$reject(static fn () => FulfillmentLogic::assertNewSaleAllowed($disabledProduct, 'pending', 'paid'), 'SAND_LICENSE_PRODUCT_UNAVAILABLE');
FulfillmentLogic::assertNewSaleAllowed(['state' => 'published', 'status' => 1], 'pending', 'paid');
$check(true, 'published enabled product permits new paid source');
FulfillmentLogic::assertNewSaleAllowed($disabledProduct, 'refunded', 'paid');
$check(FulfillmentLogic::nextState('refunded', 'paid') === 'refunded', 'disabled refund tombstone never creates a new grant');
$snapshot = ['code' => 'basic', 'revision' => 1, 'kind' => 'desktop', 'duration_unit' => 'month',
    'duration_value' => 1, 'seat_limit' => 1, 'features' => ['editor' => true]];
$facts = FulfillmentLogic::sourceFacts($input, $snapshot);
$retry = array_replace($input, ['event_id' => 'event-2', 'request_id' => 'request-2']);
$check($facts === FulfillmentLogic::sourceFacts($retry, $snapshot), 'different delivery event retains one commercial source fingerprint');
$check($facts['quantity'] === 2 && $facts['subject_code'] === null, 'desktop units do not turn quantity into membership periods');
FulfillmentLogic::assertSameSource(Records::fingerprint($facts), Records::fingerprint(FulfillmentLogic::sourceFacts($retry, $snapshot)));
$check(true, 'identical source accepted');
$changed = FulfillmentLogic::sourceFacts(array_replace($input, ['quantity' => 3]), $snapshot);
$reject(static fn () => FulfillmentLogic::assertSameSource(Records::fingerprint($facts), Records::fingerprint($changed)), 'SAND_LICENSE_SOURCE_CONFLICT');
$partial = array_replace($input, ['event_type' => 'refunded', 'refund_kind' => 'partial']);
$reject(static fn () => FulfillmentLogic::validateEvent($partial), 'SAND_LICENSE_PARTIAL_REFUND_UNSUPPORTED');
$refund = array_replace($input, ['event_type' => 'refunded', 'refund_kind' => 'full']);
FulfillmentLogic::validateEvent($refund);
$check(true, 'precise full refund accepted');
$reject(static fn () => FulfillmentLogic::validateEvent(array_replace($input, ['quantity' => 0])), 'SAND_LICENSE_FULFILLMENT_INPUT_INVALID');
$reject(static fn () => FulfillmentLogic::validateEvent(array_replace($input, ['quantity' => 1001])), 'SAND_LICENSE_FULFILLMENT_INPUT_INVALID');
$reject(static fn () => FulfillmentLogic::validateEvent(array_replace($input, ['payment_cycle_id' => ''])), 'SAND_LICENSE_FULFILLMENT_INPUT_INVALID');
$now = strtotime('2026-10-03T00:00:00Z');
$claim = ['state' => 'ready', 'expire_time' => '2026-10-04 00:00:00'];
$check(FulfillmentLogic::claimState($claim, ['state' => 'paid'], [], $now) === 'ready', 'paid claim ready');
$check(FulfillmentLogic::claimState(array_replace($claim, ['state' => 'claimed']), ['state' => 'paid'], ['state' => 'issued'], $now) === 'claimed', 'lost response returns claimed state');
$check(FulfillmentLogic::claimState($claim, ['state' => 'paid'], ['state' => 'redeemed'], $now) === 'redeemed', 'redeemed code cannot masquerade as ready');
$check(FulfillmentLogic::claimState($claim, ['state' => 'refunded'], [], $now) === 'revoked', 'refund removes claim availability');
$check(FulfillmentLogic::claimState($claim, ['state' => 'paid'], [], $now + 86400) === 'expired', 'claim expiration boundary');
$refundPresentation = FulfillmentLogic::claimPresentation($claim, ['state' => 'refunded', 'order_id' => 'private-order'], [], $now);
$check($refundPresentation['state'] === 'revoked' && $refundPresentation['reason_code'] === 'refunded'
    && str_contains($refundPresentation['message'], '已退款'), 'production claim presentation explains actual refund');
$chargebackPresentation = FulfillmentLogic::claimPresentation($claim, ['state' => 'chargeback'], [], $now);
$check($chargebackPresentation['state'] === 'revoked' && $chargebackPresentation['reason_code'] === 'chargeback'
    && str_contains($chargebackPresentation['message'], '已拒付'), 'production claim presentation explains actual chargeback');
$revokedPresentation = FulfillmentLogic::claimPresentation(array_replace($claim, ['state' => 'revoked']), ['state' => 'paid'], [], $now);
$check($revokedPresentation['reason_code'] === 'claim_revoked' && !str_contains($revokedPresentation['message'], '退款'), 'claim revocation does not invent a refund');
$check(FulfillmentLogic::claimPresentation($claim, ['state' => 'paid'], [], $now)['reason_code'] === '', 'valid paid claim does not invent a failure reason');
$check(!str_contains(json_encode($refundPresentation, JSON_THROW_ON_ERROR), 'private-order'), 'claim reason projection exposes no private order data');
$boundProduct = ['id' => '11'];
$boundSource = ['id' => '22', 'product_id' => '11'];
$boundClaim = ['id' => '33', 'fulfillment_id' => '22', 'redemption_code_id' => '44'];
$boundCode = ['id' => '44', 'product_id' => '11', 'claim_id' => '33'];
FulfillmentLogic::assertClaimBindings($boundProduct, $boundSource, $boundClaim, $boundCode);
$check(true, 'production locked binding guard accepts same product source claim code');
FulfillmentLogic::assertClaimBindings($boundProduct, $boundSource, $boundClaim);
$check(true, 'production binding guard permits context before the code lock');
foreach ([
    [$boundProduct, array_replace($boundSource, ['product_id' => '12']), $boundClaim, $boundCode],
    [$boundProduct, $boundSource, array_replace($boundClaim, ['fulfillment_id' => '23']), $boundCode],
    [$boundProduct, $boundSource, $boundClaim, array_replace($boundCode, ['product_id' => '12'])],
    [$boundProduct, $boundSource, $boundClaim, array_replace($boundCode, ['claim_id' => '34'])],
    [$boundProduct, $boundSource, $boundClaim, array_replace($boundCode, ['id' => '45'])],
    [$boundProduct, $boundSource, $boundClaim, []],
] as $changedBinding) {
    try {
        FulfillmentLogic::assertClaimBindings(...$changedBinding);
        throw new RuntimeException('Changed binding must be rejected');
    } catch (ApiException $e) {
        $check($e->getCode() === 401 && str_contains($e->getMessage(), 'SAND_LICENSE_CLAIM_DENIED'),
            'production guard rejects changed or missing locked binding');
    }
}
// Source-order regression only: these checks do not run PostgreSQL, acquire its
// FK locks, or prove that concurrent HTTP requests are deadlock-free.
$production = file_get_contents(__DIR__ . '/../app/SandLicense/Logic/FulfillmentLogic.php');
$ingestSource = substr($production, strpos($production, 'public function ingest('),
    strpos($production, 'public function read(') - strpos($production, 'public function ingest('));
$productLock = strpos($ingestSource, "where('id', \$scope['product_id'] ?? 0)->lock(true)");
$sourceInsert = strpos($ingestSource, 'INSERT INTO sand_license_fulfillment');
$check($productLock !== false && $sourceInsert !== false && $productLock < $sourceInsert,
    'static ingestion locks product before source INSERT FK acquisition');
$contextSource = substr($production, strpos($production, 'private function lockClaimContext('),
    strpos($production, 'public static function assertClaimBindings(') - strpos($production, 'private function lockClaimContext('));
$productLock = strpos($contextSource, "where('id', \$sourceHint['product_id'])->lock(true)");
$sourceLock = strpos($contextSource, "where('id', \$sourceHint['id'])->lock(true)");
$claimLock = strpos($contextSource, "where('id', \$claimId)->lock(true)");
$check($productLock !== false && $sourceLock !== false && $claimLock !== false
    && $productLock < $sourceLock && $sourceLock < $claimLock,
    'static claim context uses product then source then claim write locks');
$check(substr_count($production, '[$product, $source, $claim] = $this->lockClaimContext($claimId);') === 2,
    'static buyer claim and trusted credential recovery share the same lock context');
$codes = new CodeLogic(str_repeat('local-test-only-', 3));
$secret = $codes->generateSecret();
$check(strlen($secret) >= 32 && ctype_xdigit($secret), 'production helper generates at least 128-bit random credential');
$check($secret !== $codes->generateSecret(), 'credentials are generated independently');
$check(strlen($codes->hashSecret($secret)) === 64 && $codes->hashSecret($secret) !== $secret, 'production helper persists only bounded secret digest');
$logic = new FulfillmentLogic($codes, new MembershipLogic());
try {
    $logic->ingest($input, [], $now);
    throw new RuntimeException('Scope must reject before database access');
} catch (ApiException $e) {
    $check($e->getCode() === 401 && str_contains($e->getMessage(), 'SAND_LICENSE_SCOPE_DENIED'), 'public ingestion fails closed before database access without trusted channel');
}
$reject(static fn () => $logic->claim('not-an-id', [], $now), 'SAND_LICENSE_INPUT_INVALID');
$reject(static fn () => new FulfillmentLogic($codes, new MembershipLogic(), 3599), 'SAND_LICENSE_CONFIG_INVALID');
$reject(static fn () => new FulfillmentLogic($codes, new MembershipLogic(), 31536001), 'SAND_LICENSE_CONFIG_INVALID');
$reject(static fn () => $logic->refreshClaimCredential('1', [], [], $now), 'SAND_LICENSE_INPUT_INVALID');
echo "Fulfillment: {$count} passed; PostgreSQL uniqueness and competition not exercised.\n";
