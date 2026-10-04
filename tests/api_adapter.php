<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use app\SandLicense\Integration\SandIamAuthorization;
use app\SandLicense\Integration\RuntimeFactory;
use app\SandLicense\Integration\ApplicationReferenceVerifier;
use app\SandLicense\Integration\ProductInvocationFacts;
use app\SandLicense\Controller\FulfillmentController;
use app\SandLicense\Controller\LicenseController;
use app\SandLicense\Security\InstallationProof;
use app\SandLicense\Validate\RequestInput;
use plugin\SandIam\app\runtime\IdentityContextProvider;
use plugin\SandIam\app\runtime\ServiceInvocationAuthorizer;
use plugin\sandadmin\exception\ApiException;
use support\Request;

$count = 0;
function adapterReject(callable $operation, string $error): void
{
    global $count;
    try { $operation(); } catch (ApiException $exception) {
        licenseAssert(str_contains($exception->getMessage(), $error), 'Unexpected stable error');
        licenseAssert(in_array($exception->getCode(), [400, 401], true), 'Explicit business HTTP code required');
        ++$count;
        return;
    }
    throw new RuntimeException('Expected request rejection');
}
$authorization = new SandIamAuthorization(['audience' => 'sand-license', 'channels' => []]);
adapterReject(static fn () => $authorization->authorize('', 'sand_license.fulfillment.write', 'store', 'app', '', '127.0.0.1', 'event-1'), 'SAND_LICENSE_IDENTITY_REQUIRED');
adapterReject(static fn () => $authorization->authorize('unsigned-context', 'sand_license.fulfillment.write', 'store', 'app', '', '127.0.0.1', 'event-1'), 'SAND_LICENSE_SERVICE_SCOPE_DENIED');
adapterReject(static fn () => $authorization->authorize('unsigned-context', 'sand_license.fulfillment.write', 'store', 'app', '', 'spoofed-ip', 'event-1'), 'SAND_LICENSE_IDENTITY_REQUIRED');
$settings = ['audience' => 'sand-license', 'channels' => ['store' => [
    'product_code' => 'app', 'product_id' => '1', 'organization_id' => 1, 'application_id' => 2,
    'environment_id' => 3, 'workload_client_id' => 4, 'subject_codes' => ['customer-A'],
]]];
adapterReject(static fn () => (new SandIamAuthorization($settings))->authorize('unsigned-context', 'sand_license.membership.read', 'store', 'app', 'customer-B', '127.0.0.1', 'event-1'), 'SAND_LICENSE_SERVICE_SCOPE_DENIED');
adapterReject(static fn () => RequestInput::text(['request_id' => []], 'request_id'), 'SAND_LICENSE_REQUEST_INVALID');
$body = '{"product_code":"desktop","request_id":"req1"}';
$request = new Request("POST /api/sand-license/v1/redemptions HTTP/1.1\r\nHost: untrusted.example\r\nContent-Type: application/json\r\nIdempotency-Key: canonical-key\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
licenseAssert(RequestInput::json($request)['product_code'] === 'desktop', 'Real Request JSON parsing failed'); ++$count;
licenseAssert(RequestInput::requestId($request, RequestInput::json($request), true) === 'canonical-key', 'Idempotency header not authoritative'); ++$count;
$nonce = \app\SandLicense\Security\Challenge::issue(time())['nonce'];
$currentBody = json_encode(['product_code'=>'desktop','activation_id'=>'1','challenge'=>$nonce,'request_id'=>'current-1'],JSON_THROW_ON_ERROR);
$current = new Request("POST /api/sand-license/v1/entitlements/current HTTP/1.1\r\nContent-Type: application/json\r\nContent-Length: " . strlen($currentBody) . "\r\n\r\n" . $currentBody);
licenseAssert(RequestInput::signedJson($current)['activation_id'] === '1', 'Current must consume signed JSON business fields'); ++$count;
$getCurrent = new Request("GET /api/sand-license/v1/entitlements/current?challenge=private-nonce&activation_id=1 HTTP/1.1\r\n\r\n");
adapterReject(static fn () => (new LicenseController())->current($getCurrent), 'SAND_LICENSE_REQUEST_INVALID');
$queryCurrent = new Request("POST /api/sand-license/v1/entitlements/current?challenge=private-nonce HTTP/1.1\r\nContent-Type: application/json\r\nContent-Length: " . strlen($currentBody) . "\r\n\r\n" . $currentBody);
adapterReject(static fn () => (new LicenseController())->current($queryCurrent), 'SAND_LICENSE_REQUEST_INVALID');
$installation = sodium_crypto_sign_keypair();
$jwk = ['kty'=>'OKP','crv'=>'Ed25519','x'=>rtrim(strtr(base64_encode(sodium_crypto_sign_publickey($installation)), '+/', '-_'), '=')];
$now = time();
$proof = \Firebase\JWT\JWT::encode([
    'htm'=>'POST','htu'=>'https://license.test/api/sand-license/v1/entitlements/current',
    'iat'=>$now,'jti'=>'current-body-binding','nonce'=>$nonce,
    'body_sha256'=>hash('sha256',$current->rawBody()),'product_code'=>'desktop',
], base64_encode(sodium_crypto_sign_secretkey($installation)), 'EdDSA', null, ['typ'=>'sand-license-proof+jwt']);
$verifier = new InstallationProof();
$verifier->verify($proof,$jwk,'POST','https://license.test/api/sand-license/v1/entitlements/current',$current->rawBody(),$nonce,'desktop',$now); ++$count;
foreach (['activation_id'=>'2','product_code'=>'other-product','challenge'=>'altered-nonce'] as $field=>$value) {
    $changed = json_encode(array_replace(RequestInput::signedJson($current),[$field=>$value]),JSON_THROW_ON_ERROR);
    adapterReject(static fn () => $verifier->verify($proof,$jwk,'POST','https://license.test/api/sand-license/v1/entitlements/current',$changed,$nonce,'desktop',$now), '安装证明');
}
$bad = new Request("POST /api/sand-license/v1/redemptions HTTP/1.1\r\nContent-Type: application/json\r\nContent-Length: 1\r\n\r\n[");
adapterReject(static fn () => RequestInput::json($bad), 'SAND_LICENSE_REQUEST_INVALID');
$text = new Request("POST / HTTP/1.1\r\nContent-Type: text/plain\r\nContent-Length: 2\r\n\r\n{}");
adapterReject(static fn () => RequestInput::json($text), 'SAND_LICENSE_REQUEST_INVALID');
\Webman\Config::load(__DIR__ . '/fixtures/runtime');
licenseAssert(RuntimeFactory::admin() instanceof \app\SandLicense\Logic\AdminLogic, 'Ordinary management initialization should not require signing keys'); ++$count;
licenseAssert(RuntimeFactory::licensing('challenge') instanceof \app\SandLicense\Logic\LicensingLogic, 'Challenge initialization should not read secrets'); ++$count;
licenseAssert(RuntimeFactory::licensing('current') instanceof \app\SandLicense\Logic\LicensingLogic, 'Current entitlement initialization should not read secrets'); ++$count;
adapterReject(static fn () => RuntimeFactory::licensing('redeem'), 'SAND_LICENSE_CONFIGURATION_REQUIRED');
adapterReject(static fn () => RuntimeFactory::licensing('renew'), 'SAND_LICENSE_CONFIGURATION_REQUIRED');
adapterReject(static fn () => (new FulfillmentController())->ingest($request), 'SAND_LICENSE_IDENTITY_REQUIRED');

foreach ([65, 80, 81] as $length) {
    $body = json_encode(['product_code' => str_repeat('p', $length), 'channel_code' => str_repeat('c', $length), 'request_id' => 'bounds-1'], JSON_THROW_ON_ERROR);
    $boundary = new Request("POST /api/sand-license/v1/fulfillments/events HTTP/1.1\r\nAuthorization: Bearer formal-context\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
    foreach (['product_code', 'channel_code'] as $field) {
        if ($length <= 80) {
            licenseAssert(strlen(RequestInput::code(RequestInput::json($boundary), $field)) === $length, 'SQL-compatible code boundary rejected'); ++$count;
        } else {
            adapterReject(static fn () => RequestInput::code(RequestInput::json($boundary), $field), 'SAND_LICENSE_REQUEST_INVALID');
        }
    }
    // Real controller parses codes before failing closed on an unconfigured channel.
    adapterReject(static fn () => (new FulfillmentController())->ingest($boundary), $length <= 80 ? 'SAND_LICENSE_SERVICE_SCOPE_DENIED' : 'SAND_LICENSE_REQUEST_INVALID');
    // No valid key means this exercises middleware parsing without calling Redis.
    adapterReject(static fn () => (new \plugin\SandLicense\app\middleware\ChallengeRateLimit())->process($boundary, static fn () => new \support\Response()), $length <= 80 ? '安装公钥格式无效' : 'SAND_LICENSE_REQUEST_INVALID');
}
adapterReject(static fn () => ApplicationReferenceVerifier::assertApplication('1', '1', []), 'SAND_LICENSE_APPLICATION_REFERENCE_DENIED');
adapterReject(static fn () => ApplicationReferenceVerifier::assertApplication('not-id', '1', ['actor_id' => 1, 'admin_info' => []]), 'SAND_LICENSE_APPLICATION_REFERENCE_DENIED');

// Pure result consumers only: real provider classes are not replaced or mocked.
$channel = $settings['channels']['store'];
$facts = new ProductInvocationFacts($channel, 'app', 'customer-A', 'fixed-key');
$consumeProduct = new ReflectionMethod($facts, 'factsForProduct');
$disabled = ['id' => '1', 'code' => 'app', 'organization_id' => '1', 'application_id' => '2', 'status' => 2, 'delete_time' => null];
licenseAssert($consumeProduct->invoke($facts, $disabled)['application_id'] === 2, 'Disabled product ownership must still authorize historical refunds'); ++$count;
foreach (['organization_id' => '99', 'application_id' => '99', 'id' => '99', 'delete_time' => '2026-01-01'] as $field => $value) {
    adapterReject(static fn () => $consumeProduct->invoke($facts, array_replace($disabled, [$field => $value])), 'SAND_LICENSE_SERVICE_SCOPE_DENIED');
}
$action = 'sand_license.fulfillment.write';
$claims = ['context_id' => 'context-original', 'exp' => 200, 'organization_id' => 1, 'application_id' => 2, 'environment_id' => 3, 'workload_client_id' => 4, 'action_grants' => [$action => ['grant_id' => 5]]];
$makeOperation = new ReflectionMethod($authorization, 'operationId');
$operation = $makeOperation->invoke($authorization, $claims, $action, 'resource', 'business-request');
licenseAssert($operation === $makeOperation->invoke($authorization, $claims, $action, 'resource', 'business-request'), 'Same context retry should share IAM operation'); ++$count;
licenseAssert($operation !== $makeOperation->invoke($authorization, array_replace($claims, ['context_id' => 'context-renewed']), $action, 'resource', 'business-request'), 'Renewed context must not collide with previous IAM fingerprint'); ++$count;
$decision = ['authorization_id' => 10, 'operation_id' => $operation, 'context_id' => 'context-original', 'organization_id' => 1, 'application_id' => 2, 'environment_id' => 3, 'workload_client_id' => 4, 'grant_id' => 5, 'service_code' => 'sand_license', 'action_code' => $action, 'audience' => 'sand-license', 'data_class' => null, 'replayed' => false];
$consumeDecision = new ReflectionMethod($authorization, 'assertDecision');
$consumeDecision->invoke($authorization, $claims, $decision, $action, 'sand-license', $operation, 199); ++$count;
foreach (['application_id' => 99, 'context_id' => 'other', 'grant_id' => 6, 'operation_id' => 'other', 'authorization_id' => 0, 'audience' => 'other', 'replayed' => null] as $field => $value) {
    adapterReject(static fn () => $consumeDecision->invoke($authorization, $claims, array_replace($decision, [$field => $value]), $action, 'sand-license', $operation, 199), 'SAND_LICENSE_SERVICE_SCOPE_DENIED');
}
adapterReject(static fn () => $consumeDecision->invoke($authorization, $claims, $decision, $action, 'sand-license', $operation, 200), 'SAND_LICENSE_SERVICE_SCOPE_DENIED');

// behavior-test-gate: static-rule — port shape check, not IAM authorization success.
$provider = new ReflectionMethod(IdentityContextProvider::class, 'verifyForService');
$authorizer = new ReflectionMethod(ServiceInvocationAuthorizer::class, 'authorizeInvocation');
licenseAssert(array_map(static fn (ReflectionParameter $p): string => $p->getName(), $provider->getParameters()) === ['context', 'expectedServiceCode', 'expectedAudience', 'requiredAction', 'trustedSourceIp', 'requestId'], 'Real IAM context contract changed');
licenseAssert(count($authorizer->getParameters()) === 9, 'Real invocation authorization contract changed');
licenseAssert((new ReflectionMethod(\plugin\SandIam\app\admin\support\AdminOrganizationAccess::class, 'assertApplication'))->isPublic(), 'Real IAM application access port required');
echo '成功：' . $count . " 项真实 HTTP 输入/失败关闭行为检查；正式 IAM 端口形状核对通过。未执行数据库授权或 HTTP 服务验收。\n";
