<?php

declare(strict_types=1);

namespace app\SandLicense\Integration;

use plugin\SandIam\app\runtime\IdentityContextProvider;
use plugin\SandIam\app\runtime\ServiceInvocationAuthorizer;
use plugin\SandIam\app\runtime\ServiceInvocationFactResolverRegistry;
use plugin\sandadmin\exception\ApiException;

final class SandIamAuthorization
{
    /** @param array<string,mixed> $configuration */
    public function __construct(private readonly array $configuration) {}

    /** @return array<string,mixed> */
    public function authorize(
        string $context, string $action, string $channelCode, string $productCode,
        string $subjectCode, string $trustedSourceIp, string $requestId,
    ): array {
        if ($context === '' || $requestId === '' || filter_var($trustedSourceIp, FILTER_VALIDATE_IP) === false) {
            throw new ApiException('SAND_LICENSE_IDENTITY_REQUIRED: 需要正式 SandIAM 服务上下文及可信请求来源', 401);
        }
        foreach ([IdentityContextProvider::class, ServiceInvocationAuthorizer::class, ServiceInvocationFactResolverRegistry::class] as $port) {
            if (!class_exists($port)) {
                throw new ApiException('SAND_LICENSE_IAM_UNAVAILABLE: 正式 SandIAM 服务授权端口不可用', 401);
            }
        }
        if (!method_exists(IdentityContextProvider::class, 'verifyForService')
            || !method_exists(ServiceInvocationAuthorizer::class, 'authorizeInvocation')) {
            throw new ApiException('SAND_LICENSE_IAM_UNAVAILABLE: 正式 SandIAM 服务授权端口版本不兼容', 401);
        }
        $channel = $this->configuration['channels'][$channelCode] ?? null;
        if (!is_array($channel) || ($channel['product_code'] ?? null) !== $productCode) $this->deny();
        foreach (['organization_id', 'application_id', 'environment_id', 'workload_client_id', 'product_id'] as $field) {
            if (filter_var($channel[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) $this->deny();
        }
        if ($subjectCode !== '' && !in_array($subjectCode, $channel['subject_codes'] ?? [], true)) $this->deny();
        $resolverCode = str_starts_with($action, 'sand_license.membership.') ? 'sand_license.membership' : 'sand_license.fulfillment';
        $resourceKey = hash('sha256', json_encode([$channelCode, $productCode, $subjectCode], JSON_THROW_ON_ERROR));
        $resolver = new ProductInvocationFacts($channel, $productCode, $subjectCode, $resourceKey);
        $registry = new ServiceInvocationFactResolverRegistry([$resolverCode => $resolver]);
        $provider = new IdentityContextProvider();
        $audience = (string) ($this->configuration['audience'] ?? '');
        if ($audience === '') $this->deny();
        try {
            $claims = $provider->verifyForService($context, 'sand_license', $audience, $action, $trustedSourceIp, $this->auditId($requestId));
        } catch (\Throwable) {
            throw new ApiException('SAND_LICENSE_SERVICE_AUTHORIZATION_DENIED: 服务上下文验证失败，请重新取得有效授权', 401);
        }
        foreach (['organization_id', 'application_id', 'environment_id', 'workload_client_id'] as $field) {
            if ((string) ($claims[$field] ?? '') !== (string) $channel[$field]) $this->deny();
        }
        $operationId = $this->operationId($claims, $action, $resourceKey, $requestId);
        try {
            $decision = (new ServiceInvocationAuthorizer(contexts: $provider, factResolvers: $registry))->authorizeInvocation(
                $context, 'sand_license', $audience, $action, $resolverCode,
                $resourceKey, $trustedSourceIp, $operationId, $this->auditId($requestId),
            );
        } catch (\Throwable) {
            throw new ApiException('SAND_LICENSE_SERVICE_AUTHORIZATION_DENIED: 当前服务动作或资源范围未获授权，请联系授权管理员', 401);
        }
        $this->assertDecision($claims, $decision, $action, $audience, $operationId, time());
        return [
            'organization_id' => (string) $channel['organization_id'],
            'application_id' => (string) $channel['application_id'],
            'environment_id' => (string) $channel['environment_id'],
            'product_id' => (string) $channel['product_id'],
            'channel_code' => $channelCode,
            'subject_code' => $subjectCode,
            'actor_ref' => 'workload_client:' . (string) $claims['workload_client_id'],
            'request_id' => $requestId,
        ];
    }

    private function deny(): never
    {
        throw new ApiException('SAND_LICENSE_SERVICE_SCOPE_DENIED: 当前服务无权处理目标渠道、产品或会员主体', 401);
    }

    private function operationId(array $claims, string $action, string $resourceKey, string $requestId): string
    {
        if (!is_string($claims['context_id'] ?? null) || $claims['context_id'] === '') $this->deny();
        // IAM binds its fingerprint to context_id; renewed contexts get a new IAM
        // operation while the original domain request_id still controls business dedup.
        return hash('sha256', json_encode([$claims['context_id'], $action, $resourceKey, $requestId], JSON_THROW_ON_ERROR));
    }

    /** Verify the official persisted-authorization result, not a truthy substitute. */
    private function assertDecision(array $claims, array $decision, string $action, string $audience, string $operationId, int $now): void
    {
        if (!is_int($claims['exp'] ?? null) || $claims['exp'] <= $now
            || !is_int($decision['authorization_id'] ?? null) || $decision['authorization_id'] <= 0
            || !is_int($decision['grant_id'] ?? null) || $decision['grant_id'] <= 0
            || !is_bool($decision['replayed'] ?? null)) $this->deny();
        foreach (['organization_id', 'application_id', 'environment_id', 'workload_client_id', 'context_id'] as $field) {
            if ((string) ($decision[$field] ?? '') !== (string) ($claims[$field] ?? '')) $this->deny();
        }
        foreach (['service_code' => 'sand_license', 'action_code' => $action, 'audience' => $audience, 'operation_id' => $operationId] as $field => $expected) {
            if (($decision[$field] ?? null) !== $expected) $this->deny();
        }
        if ((string) $decision['grant_id'] !== (string) ($claims['action_grants'][$action]['grant_id'] ?? '')) $this->deny();
    }

    private function auditId(string $requestId): string
    {
        return substr($requestId, 0, 32) . ':license:' . bin2hex(random_bytes(16));
    }
}
