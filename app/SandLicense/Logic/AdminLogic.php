<?php

declare(strict_types=1);

namespace app\SandLicense\Logic;

use think\facade\Db;

/** Thin management data/actions over the same domain. Empty organizational scope is never global access. */
final class AdminLogic
{
    private const RESOURCES = [
        'product' => 'product', 'plan' => 'plan', 'sku_mapping' => 'sku_mapping', 'code' => 'redemption_code',
        'entitlement' => 'entitlement', 'activation' => 'activation', 'fulfillment' => 'fulfillment',
        'membership' => 'entitlement', 'event' => 'event',
    ];

    public function __construct(private readonly ?CodeLogic $codes = null, private readonly ?LicensingLogic $licensing = null) {}

    public function index(string $resource, array $query, array $scope): array
    {
        $builder = $this->scopedQuery($resource, $scope);
        if (isset($query['product_id']) && $resource !== 'product') {
            $id = Values::id($query['product_id']);
            ProductLogic::scoped($id, $scope);
            if ($resource === 'activation') {
                $builder->whereIn('entitlement_id', Db::table('sand_license_entitlement')->where('product_id', $id)->column('id'));
            } else $builder->where('product_id', $id);
        }
        if (isset($query['state']) && $query['state'] !== '' && in_array($resource, ['product','plan','code','entitlement','activation','fulfillment','membership'], true)) {
            $builder->where('state', Values::text($query['state'], '状态', 30));
        }
        if ($resource === 'plan' && isset($query['kind']) && $query['kind'] !== '') {
            if (!in_array($query['kind'], ['desktop','membership'], true)) Values::fail('SAND_LICENSE_INPUT_INVALID', '套餐类型不正确');
            $builder->where('kind', $query['kind']);
        }
        if (isset($query['keywords']) && $query['keywords'] !== '') {
            $fields = match ($resource) {
                'product', 'plan' => ['name','code'],
                'activation' => ['name'],
                'code' => ['prefix'],
                'entitlement', 'membership' => ['subject_code'],
                'fulfillment' => ['order_id','order_item_id','subject_code'],
                'event' => ['request_id','action'],
                'sku_mapping' => ['channel_code','sku_code'],
            };
            $term = Values::text($query['keywords'], '关键词', 120);
            $builder->where(function (object $search) use ($fields, $term): void {
                foreach ($fields as $field) $search->whereOr($field, 'like', '%' . $term . '%');
                if (ctype_digit($term)) $search->whereOr('id', $term);
            });
        }
        [$page, $perPage] = self::pagination($query);
        $total = (clone $builder)->count();
        $records = $builder->order('id', 'desc')->page($page, $perPage)->select()->toArray();
        $now = time();
        $data = array_map(fn (array $row): array => $this->display($resource, $row, $scope), $records);
        return ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'data' => $data, 'server_time' => Values::iso($now)];
    }

    public function read(string $resource, string $id, array $scope): array
    {
        $row = $this->scopedQuery($resource, $scope)->where('id', Values::id($id))->find();
        if (!is_array($row)) Values::fail('SAND_LICENSE_RESOURCE_UNAVAILABLE', '记录不存在或不可访问');
        $result = $this->display($resource, $row, $scope);
        if (in_array($resource, ['entitlement', 'membership'], true)) {
            $result['grants'] = array_map(fn (array $grant): array => $this->display('grant', $grant, $scope),
                Db::table('sand_license_grant')->where('entitlement_id', $id)->select()->toArray());
            $result['activations'] = array_map(fn (array $activation): array => $this->display('activation', $activation, $scope),
                Db::table('sand_license_activation')->where('entitlement_id', $id)->select()->toArray());
        }
        return $result + ['server_time' => Values::iso(time())];
    }

    public function save(string $resource, array $payload, array $scope, array $actor): array
    {
        if (!in_array($resource, ['product','plan','sku_mapping'], true)) Values::fail('SAND_LICENSE_ACTION_INVALID', '此资源只能通过业务动作变更');
        $now = time();
        return Db::transaction(function () use ($resource, $payload, $scope, $actor, $now): array {
            $existing = null;
            if (isset($payload['id']) && $payload['id'] !== '') {
                $existing = $this->lockConfigurationRecord($resource, Values::id($payload['id']), $scope);
            }
            if ($resource === 'product') {
                $organizationId = (int) ($payload['organization_id'] ?? $existing['organization_id'] ?? 0);
                Values::assertOrganization($scope, $organizationId);
                $record = [
                    'organization_id' => $organizationId,
                    'application_id' => isset($payload['application_id']) && $payload['application_id'] !== '' ? Values::id($payload['application_id']) : ($existing['application_id'] ?? null),
                    'code' => Values::text($payload['code'] ?? $existing['code'] ?? null, '产品代码', 80),
                    'name' => Values::text($payload['name'] ?? $existing['name'] ?? null, '产品名称', 120),
                    'audience' => Values::text($payload['audience'] ?? $existing['audience'] ?? null, '许可受众', 240),
                    'status' => (int) ($payload['status'] ?? $existing['status'] ?? 1),
                    'state' => $existing['state'] ?? 'draft',
                ];
                if ($organizationId <= 0 || !in_array($record['status'], [1,2], true)) Values::fail('SAND_LICENSE_INPUT_INVALID', '组织或状态不正确');
                if ($existing !== null) ProductLogic::assertEditableIdentity($existing, $record);
                if ($record['application_id'] !== null) {
                    \app\SandLicense\Integration\ApplicationReferenceVerifier::assertApplication(
                        (string) $record['application_id'], (string) $record['organization_id'], $scope,
                    );
                }
            } else {
                $productId = Values::id($payload['product_id'] ?? $existing['product_id'] ?? null);
                ProductLogic::scoped($productId, $scope, true);
                if ($existing !== null && (string) $existing['product_id'] !== $productId) Values::fail('SAND_LICENSE_INPUT_INVALID', '不可移动记录到另一产品');
                if ($resource === 'plan') {
                    if ($existing !== null && $existing['state'] === 'published') Values::fail('SAND_LICENSE_PLAN_IMMUTABLE', '已发布套餐不可修改，请创建新版本');
                    $candidate = $payload + ($existing ?? []) + ['seat_limit' => 1, 'revision' => 1, 'features' => []];
                    $snapshot = PlanLogic::snapshot($candidate);
                    $record = $snapshot + ['product_id' => $productId, 'state' => 'draft', 'status' => 1];
                    $record['features'] = json_encode((object) $snapshot['features'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                } else {
                    $planId = Values::id($payload['plan_id'] ?? $existing['plan_id'] ?? null);
                    $plan = Db::table('sand_license_plan')->where('id', $planId)->where('product_id', $productId)->find();
                    if (!is_array($plan) || $plan['state'] !== 'published') Values::fail('SAND_LICENSE_PLAN_UNAVAILABLE', '请选择本产品的已发布套餐');
                    $record = [
                        'product_id' => $productId, 'plan_id' => $planId,
                        'channel_code' => Values::text($payload['channel_code'] ?? $existing['channel_code'] ?? null, '渠道代码', 80),
                        'sku_code' => Values::text($payload['sku_code'] ?? $existing['sku_code'] ?? null, '商品代码', 160),
                        'status' => (int) ($payload['status'] ?? $existing['status'] ?? 1),
                    ];
                    if (!in_array($record['status'], [1,2], true)) Values::fail('SAND_LICENSE_INPUT_INVALID', '状态不正确');
                }
            }
            $actorId = isset($actor['id']) ? Values::id($actor['id']) : ($scope['actor_id'] ?? null);
            $record['updated_by'] = $actorId;
            $record['update_time'] = Values::sqlTime($now);
            if ($existing === null) {
                $record['created_by'] = $actorId; $record['create_time'] = Values::sqlTime($now);
                $id = Db::table('sand_license_' . self::RESOURCES[$resource])->insertGetId($record);
            } else {
                $id = $existing['id'];
                Db::table('sand_license_' . self::RESOURCES[$resource])->where('id', $id)->update($record);
            }
            return ['id' => (string) $id, 'state' => $record['state'] ?? ($record['status'] === 1 ? 'enabled' : 'disabled'), 'server_time' => Values::iso($now)];
        });
    }

    public function publish(string $resource, string $id, array $scope, array $actor): array
    {
        if (!in_array($resource, ['product','plan'], true)) Values::fail('SAND_LICENSE_ACTION_INVALID', '此资源不支持发布');
        return Db::transaction(function () use ($resource, $id, $scope, $actor): array {
            $record = $this->lockConfigurationRecord($resource, Values::id($id), $scope);
            if ($resource === 'plan') {
                ProductLogic::assertPublished(ProductLogic::scoped((string) $record['product_id'], $scope));
                PlanLogic::snapshot($record);
            }
            $now = time();
            Db::table('sand_license_' . self::RESOURCES[$resource])->where('id', $id)->update([
                'state' => 'published', 'published_time' => $record['published_time'] ?? Values::sqlTime($now),
                'updated_by' => $actor['id'] ?? $scope['actor_id'] ?? null, 'update_time' => Values::sqlTime($now),
            ]);
            return ['id' => $id, 'state' => 'published', 'server_time' => Values::iso($now)];
        });
    }

    /** Configuration writes share fulfillment's product -> plan/SKU order, not a generic lock layer. */
    private function lockConfigurationRecord(string $resource, string $id, array $scope): array
    {
        // The hint is authorized but deliberately not locked before its product.
        $hint = $this->scopedQuery($resource, $scope)->where('id', $id)->find();
        if (!is_array($hint)) Values::fail('SAND_LICENSE_RESOURCE_UNAVAILABLE', '记录不存在或不可访问');
        if ($resource !== 'product') ProductLogic::scoped((string) $hint['product_id'], $scope, true);
        $record = $this->scopedQuery($resource, $scope)->where('id', $id)->lock(true)->find();
        if (!is_array($record)) Values::fail('SAND_LICENSE_RESOURCE_UNAVAILABLE', '记录不存在或不可访问');
        if ($resource !== 'product') ProductLogic::assertSameProduct($record, (string) $hint['product_id']);
        return $record;
    }

    public function action(string $resource, string $action, array $payload, array $scope, array $actor): array
    {
        $now = time();
        $scope['actor_ref'] = 'admin:' . (string) ($actor['id'] ?? $scope['actor_id'] ?? '');
        if ($resource === 'code' && in_array($action, ['issue','status','reissue'], true)) {
            if ($this->codes === null) Values::fail('SAND_LICENSE_CONFIG_REQUIRED', '请先配置卡密摘要密钥');
            if ($action === 'issue') return $this->codes->issue($payload, $scope, $now);
            if ($action === 'status') return $this->codes->status(Values::requestId($payload), $scope, $now);
            if ($action === 'reissue') return $this->codes->reissue(Values::id($payload['code_id'] ?? $payload['id'] ?? null), Values::requestId($payload), $scope, $now);
        }
        if ($resource === 'activation' && in_array($action, ['reset','release'], true)) {
            if ($this->licensing === null) Values::fail('SAND_LICENSE_CONFIG_REQUIRED', '请先配置许可运行策略');
            $payload['activation_id'] = $payload['activation_id'] ?? $payload['id'] ?? null;
            return $this->licensing->reset($payload, $scope, $now);
        }
        if ($resource === 'entitlement' && $action === 'ticket') {
            if ($this->licensing === null) Values::fail('SAND_LICENSE_CONFIG_REQUIRED', '请先配置许可运行策略');
            $payload['entitlement_id'] = $payload['entitlement_id'] ?? $payload['id'] ?? null;
            return $this->licensing->adminTicket($payload, $scope, $now);
        }
        if (in_array($resource, ['entitlement','code'], true) && in_array($action, ['suspend','revoke'], true)) {
            Values::text($payload['reason'] ?? null, '操作原因');
            $requestId = Values::requestId($payload);
            return Db::transaction(function () use ($resource, $action, $payload, $scope, $requestId, $now): array {
                $hint = $this->scopedQuery($resource, $scope)->where('id', Values::id($payload['id'] ?? $payload['code_id'] ?? null))->find();
                if (!is_array($hint)) Values::fail('SAND_LICENSE_RESOURCE_UNAVAILABLE', '记录不存在或不可访问');
                $product = ProductLogic::scoped((string) $hint['product_id'], $scope, true);
                $row = $this->scopedQuery($resource, $scope)->where('id', $hint['id'])->lock(true)->find();
                $state = $action === 'suspend' ? 'suspended' : 'revoked';
                if ($resource === 'code') {
                    if ($action !== 'revoke' || $row['state'] !== 'issued') Values::fail('SAND_LICENSE_ACTION_INVALID', '仅未兑换卡密可以撤销；权益需独立操作');
                    if ($row['claim_id'] !== null) Values::fail('SAND_LICENSE_CLAIM_REQUIRED', '领取码请从履约来源撤销');
                }
                Db::table('sand_license_' . self::RESOURCES[$resource])->where('id', $row['id'])->update(['state' => $state, 'update_time' => Values::sqlTime($now)]);
                Records::event($product['id'], $resource . '.' . $action, $resource, $row['id'], $scope['actor_ref'], $requestId, $now, ['reason' => $payload['reason']]);
                return ['id' => (string) $row['id'], 'state' => $state, 'server_time' => Values::iso($now)];
            });
        }
        Values::fail('SAND_LICENSE_ACTION_INVALID', '此资源不支持该操作');
    }

    private function scopedQuery(string $resource, array $scope): object
    {
        if (!isset(self::RESOURCES[$resource])) Values::fail('SAND_LICENSE_RESOURCE_INVALID', '未知管理资源');
        $products = Db::table('sand_license_product')->whereNull('delete_time');
        if (!array_key_exists('organization_ids', $scope)) Values::fail('SAND_LICENSE_SCOPE_FORBIDDEN', '无法确认管理组织范围');
        if ($scope['organization_ids'] === null) {
            if (($scope['is_super_admin'] ?? false) !== true) Values::fail('SAND_LICENSE_SCOPE_FORBIDDEN', '无法确认超级管理员');
        } elseif (is_array($scope['organization_ids']) && $scope['organization_ids'] !== []) {
            $products->whereIn('organization_id', array_map('intval', $scope['organization_ids']));
        } else {
            $products->where('id', -1);
        }
        if ($resource === 'product') return $products;
        $productIds = $products->column('id');
        $query = Db::table('sand_license_' . self::RESOURCES[$resource]);
        if ($resource === 'activation') {
            $entitlementIds = Db::table('sand_license_entitlement')->whereIn('product_id', $productIds)->column('id');
            $query->whereIn('entitlement_id', $entitlementIds);
        } else $query->whereIn('product_id', $productIds);
        if (in_array($resource, ['plan','sku_mapping'], true)) $query->whereNull('delete_time');
        if ($resource === 'membership') $query->where('kind', 'membership');
        return $query;
    }

    private function display(string $resource, array $row, array $scope): array
    {
        $product = null;
        if ($resource === 'activation') {
            $entitlement = Db::table('sand_license_entitlement')->where('id', $row['entitlement_id'])->find();
            if (!is_array($entitlement)) Values::fail('SAND_LICENSE_RESOURCE_UNAVAILABLE', '记录不存在或不可访问');
            $product = ProductLogic::scoped((string) $entitlement['product_id'], $scope);
            $row['product_id'] = (string) $product['id'];
        }
        unset($row['secret_hash'], $row['session_key_hash'], $row['request_hash'], $row['public_key'], $row['proof_jti_hash'], $row['result_ref']);
        foreach ($row as $field => &$value) {
            if (($field === 'id' || str_ends_with($field, '_id')) && $value !== null) $value = (string) $value;
            if (str_ends_with($field, '_time') && $value !== null) $value = Values::iso(Values::time($value));
        }
        unset($value);
        if (isset($row['features'])) $row['features'] = Values::json($row['features']);
        if (isset($row['detail'])) $row['detail'] = Values::json($row['detail']);
        if (isset($row['plan_snapshot'])) {
            $snapshot = Values::json($row['plan_snapshot']);
            $row['plan_name'] = $snapshot['name'] ?? $snapshot['code'] ?? '';
            $row['features'] = $snapshot['features'] ?? [];
            unset($row['plan_snapshot']);
        }
        if (isset($row['product_id'])) {
            $product ??= Db::table('sand_license_product')->where('id', $row['product_id'])->find();
            $row['product_name'] = $product['name'] ?? '';
        }
        if (isset($row['plan_id'])) {
            $plan = Db::table('sand_license_plan')->where('id', $row['plan_id'])->find();
            $row['plan_name'] = $plan['name'] ?? '';
        }
        if ($resource === 'product') $row['product_name'] = $row['name'];
        if ($resource === 'plan') $row['plan_name'] = $row['name'];
        if ($resource === 'activation') {
            // Think's default aggregate coercion is numeric; preserve PostgreSQL timestamps.
            $last = Db::table('sand_license_lease')->where('activation_id', $row['id'])->max('expire_time', false);
            $row['lease_expire_time'] = $last ? Values::iso(Values::time($last)) : null;
        }
        if (!isset($row['state']) && isset($row['status'])) $row['state'] = (int) $row['status'] === 1 ? 'enabled' : 'disabled';
        return $row;
    }

    public static function pagination(array $query): array
    {
        return [
            max(1, min(100000, (int) ($query['page'] ?? $query['current_page'] ?? 1))),
            max(1, min(100, (int) ($query['limit'] ?? $query['page_size'] ?? $query['per_page'] ?? 20))),
        ];
    }
}
