<?php

declare(strict_types=1);

namespace app\SandLicense\Logic;

use think\facade\Db;

final class ProductLogic
{
    public static function byCode(string $code, bool $lock = false, bool $allowInactive = false): array
    {
        $row = Db::table('sand_license_product')->where('code', Values::text($code, '产品代码', 80))
            ->whereNull('delete_time')->lock($lock)->find();
        if (!is_array($row)) {
            Values::fail('SAND_LICENSE_PRODUCT_UNAVAILABLE', '产品未启用');
        }
        if (!$allowInactive) self::assertPublished($row);
        return $row;
    }

    public static function scoped(string $id, array $scope, bool $lock = false): array
    {
        $row = Db::table('sand_license_product')->where('id', Values::id($id))->whereNull('delete_time')->lock($lock)->find();
        if (!is_array($row)) Values::fail('SAND_LICENSE_RESOURCE_UNAVAILABLE', '记录不存在或不可访问');
        Values::assertOrganization($scope, (int) $row['organization_id']);
        if (isset($scope['product_id']) && (string) $scope['product_id'] !== (string) $row['id']) {
            Values::fail('SAND_LICENSE_SCOPE_FORBIDDEN', '产品不在当前调用范围');
        }
        return $row;
    }

    public static function assertPublished(array $product): void
    {
        if (($product['state'] ?? '') !== 'published' || (int) ($product['status'] ?? 0) !== 1) {
            Values::fail('SAND_LICENSE_PRODUCT_UNAVAILABLE', '产品尚未发布或已经停用');
        }
    }

    public static function assertSameProduct(array $record, string $expectedProductId): void
    {
        if ((string) ($record['product_id'] ?? '') !== $expectedProductId) {
            Values::fail('SAND_LICENSE_SCOPE_FORBIDDEN', '记录所属产品已变化，请重新读取后操作');
        }
    }

    public static function assertEditableIdentity(array $existing, array $candidate): void
    {
        if (($existing['state'] ?? '') !== 'published') return;
        foreach (['organization_id','application_id','code','audience'] as $field) {
            if ((string) ($candidate[$field] ?? '') !== (string) ($existing[$field] ?? '')) Values::fail('SAND_LICENSE_PRODUCT_IMMUTABLE', '已发布产品组织、应用、代码及许可受众不可变更');
        }
    }
}
