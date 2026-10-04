<?php

declare(strict_types=1);

// behavior-test-gate: static-rule. This checks source order, not PostgreSQL lock scheduling.
$start = microtime(true);
$source = file_get_contents(__DIR__ . '/../app/SandLicense/Logic/AdminLogic.php');
if (!is_string($source)) throw new RuntimeException('Admin source missing');
$from = strpos($source, 'private function lockConfigurationRecord(');
$end = strpos($source, 'public function action(', $from ?: 0);
if ($from === false || $end === false) throw new RuntimeException('Configuration lock method missing');
$body = substr($source, $from, $end - $from);
$hint = strpos($body, '$hint = $this->scopedQuery');
$product = strpos($body, 'ProductLogic::scoped((string) $hint[\'product_id\'], $scope, true)');
$record = strpos($body, '$record = $this->scopedQuery');
$lock = strpos($body, '->lock(true)->find()');
$verify = strpos($body, 'ProductLogic::assertSameProduct(');
if ($hint === false || $product === false || $record === false || $lock === false || $verify === false
    || !($hint < $product && $product < $record && $record < $lock && $lock < $verify)
    || str_contains(substr($body, $hint, $product - $hint), '->lock(')) {
    throw new RuntimeException('Expected scoped unlocked hint -> product lock -> record lock -> product recheck');
}
foreach (['save','publish'] as $method) {
    $methodStart = strpos($source, 'public function ' . $method . '(');
    $nextMethod = $methodStart === false ? false : strpos($source, "\n    public function ", $methodStart + 1);
    $methodBody = $methodStart === false ? '' : substr($source, $methodStart, ($nextMethod ?: strlen($source)) - $methodStart);
    if (!str_contains($methodBody, '$this->lockConfigurationRecord(')) {
        throw new RuntimeException($method . ' must consume the ordered configuration lock path');
    }
}
echo sprintf("Admin lock-order static: save/publish scoped hint -> product -> plan/SKU -> recheck passed; %.3fs. No DB transaction executed.\n", microtime(true) - $start);
