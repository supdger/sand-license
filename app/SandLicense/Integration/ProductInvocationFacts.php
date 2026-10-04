<?php

declare(strict_types=1);

namespace app\SandLicense\Integration;

use plugin\SandIam\app\runtime\ServiceInvocationFactResolver;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

/** Provider-owned facts loaded from fixed channel config and live product records. */
final readonly class ProductInvocationFacts implements ServiceInvocationFactResolver
{
    /** @param array<string,mixed> $channel */
    public function __construct(
        private array $channel,
        private string $productCode,
        private string $subjectCode,
        private string $resourceKey,
    ) {}

    public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array
    {
        if ($serviceCode !== 'sand_license' || !hash_equals($this->resourceKey, $resourceKey)
            || !in_array($actionCode, ['sand_license.fulfillment.write', 'sand_license.fulfillment.read', 'sand_license.membership.read'], true)) {
            $this->deny();
        }
        $product = Db::table('sand_license_product')->where('id', $this->channel['product_id'])
            ->whereNull('delete_time')->find();
        return $this->factsForProduct($product);
    }

    /** Ownership remains authoritative even after sales have been disabled. */
    private function factsForProduct(mixed $product): array
    {
        if (!is_array($product) || (string) $product['code'] !== $this->productCode
            || (string) $product['id'] !== (string) $this->channel['product_id']
            || ($product['delete_time'] ?? null) !== null
            || (string) $product['organization_id'] !== (string) $this->channel['organization_id']
            || (string) $product['application_id'] !== (string) $this->channel['application_id']) $this->deny();
        if ($this->subjectCode !== ''
            && !in_array($this->subjectCode, $this->channel['subject_codes'] ?? [], true)) $this->deny();
        return [
            'organization_id' => (int) $this->channel['organization_id'],
            'application_id' => (int) $this->channel['application_id'],
            'environment_id' => (int) $this->channel['environment_id'],
            'workload_client_id' => (int) $this->channel['workload_client_id'],
            'data_class' => $this->channel['data_class'] ?? null,
            'resource_type' => 'sand_license_product',
            'resource_ref' => (string) $this->channel['product_id'],
        ];
    }

    private function deny(): never
    {
        throw new ApiException('SAND_LICENSE_SERVICE_SCOPE_DENIED: 当前渠道无权处理目标产品或会员主体', 401);
    }
}
