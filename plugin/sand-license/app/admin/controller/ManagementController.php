<?php

declare(strict_types=1);

namespace plugin\SandLicense\app\admin\controller;

use app\SandLicense\Integration\RuntimeFactory;
use app\SandLicense\Validate\RequestInput;
use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\sandadmin\app\cache\UserInfoCache;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class ManagementController extends BaseController
{
    #[Permission('软件产品列表', 'sand_license:product:index')]
    public function productIndex(Request $r): Response { return $this->listing('product', $r); }
    #[Permission('软件产品详情', 'sand_license:product:read')]
    public function productRead(Request $r): Response { return $this->reading('product', $r); }
    #[Permission('保存软件产品', 'sand_license:product:save')]
    public function productSave(Request $r): Response { return $this->saving('product', $r); }
    #[Permission('发布软件产品', 'sand_license:product:publish')]
    public function productPublish(Request $r): Response { return $this->publishing('product', $r); }
    #[Permission('套餐列表', 'sand_license:plan:index')]
    public function planIndex(Request $r): Response { return $this->listing('plan', $r); }
    #[Permission('套餐详情', 'sand_license:plan:read')]
    public function planRead(Request $r): Response { return $this->reading('plan', $r); }
    #[Permission('保存套餐', 'sand_license:plan:save')]
    public function planSave(Request $r): Response { return $this->saving('plan', $r); }
    #[Permission('发布套餐', 'sand_license:plan:publish')]
    public function planPublish(Request $r): Response { return $this->publishing('plan', $r); }
    #[Permission('卡密列表', 'sand_license:code:index')]
    public function codeIndex(Request $r): Response { return $this->listing('code', $r); }
    #[Permission('卡密详情', 'sand_license:code:read')]
    public function codeRead(Request $r): Response { return $this->reading('code', $r); }
    #[Permission('手工发码', 'sand_license:code:issue')]
    public function codeIssue(Request $r): Response { return $this->acting('code', 'issue', $r); }
    #[Permission('发码结果查询', 'sand_license:code:issue')]
    public function codeStatus(Request $r): Response { return $this->acting('code', 'status', $r); }
    #[Permission('重新签发卡密', 'sand_license:code:reissue')]
    public function codeReissue(Request $r): Response { return $this->acting('code', 'reissue', $r); }
    #[Permission('撤销卡密', 'sand_license:code:revoke')]
    public function codeRevoke(Request $r): Response { return $this->acting('code', 'revoke', $r); }
    #[Permission('权益列表', 'sand_license:entitlement:index')]
    public function entitlementIndex(Request $r): Response { return $this->listing('entitlement', $r); }
    #[Permission('权益详情', 'sand_license:entitlement:read')]
    public function entitlementRead(Request $r): Response { return $this->reading('entitlement', $r); }
    #[Permission('暂停权益', 'sand_license:entitlement:suspend')]
    public function entitlementSuspend(Request $r): Response { return $this->acting('entitlement', 'suspend', $r); }
    #[Permission('撤销权益', 'sand_license:entitlement:revoke')]
    public function entitlementRevoke(Request $r): Response { return $this->acting('entitlement', 'revoke', $r); }
    #[Permission('签发新设备资格', 'sand_license:entitlement:ticket')]
    public function entitlementTicket(Request $r): Response { return $this->acting('entitlement', 'ticket', $r); }
    #[Permission('设备激活列表', 'sand_license:activation:index')]
    public function activationIndex(Request $r): Response { return $this->listing('activation', $r); }
    #[Permission('设备激活详情', 'sand_license:activation:read')]
    public function activationRead(Request $r): Response { return $this->reading('activation', $r); }
    #[Permission('释放设备', 'sand_license:activation:release')]
    public function activationRelease(Request $r): Response { return $this->acting('activation', 'release', $r); }
    #[Permission('重置设备', 'sand_license:activation:reset')]
    public function activationReset(Request $r): Response { return $this->acting('activation', 'reset', $r); }
    #[Permission('商品映射列表', 'sand_license:sku_mapping:index')]
    public function skuIndex(Request $r): Response { return $this->listing('sku_mapping', $r); }
    #[Permission('商品映射详情', 'sand_license:sku_mapping:read')]
    public function skuRead(Request $r): Response { return $this->reading('sku_mapping', $r); }
    #[Permission('保存商品映射', 'sand_license:sku_mapping:save')]
    public function skuSave(Request $r): Response { return $this->saving('sku_mapping', $r); }
    #[Permission('履约列表', 'sand_license:fulfillment:index')]
    public function fulfillmentIndex(Request $r): Response { return $this->listing('fulfillment', $r); }
    #[Permission('履约详情', 'sand_license:fulfillment:read')]
    public function fulfillmentRead(Request $r): Response { return $this->reading('fulfillment', $r); }
    #[Permission('会员权益列表', 'sand_license:membership:index')]
    public function membershipIndex(Request $r): Response { return $this->listing('membership', $r); }
    #[Permission('会员权益详情', 'sand_license:membership:read')]
    public function membershipRead(Request $r): Response { return $this->reading('membership', $r); }
    #[Permission('操作记录列表', 'sand_license:event:index')]
    public function eventIndex(Request $r): Response { return $this->listing('event', $r); }
    #[Permission('操作记录详情', 'sand_license:event:read')]
    public function eventRead(Request $r): Response { return $this->reading('event', $r); }

    private function listing(string $resource, Request $request): Response
    {
        $scope = $this->scope($request);
        return $this->reply(RuntimeFactory::admin()->index($resource, $request->get(), $scope));
    }

    private function reading(string $resource, Request $request): Response
    {
        $scope = $this->scope($request);
        return $this->reply(RuntimeFactory::admin()->read($resource, RequestInput::text($request->get(), 'id'), $scope));
    }

    private function saving(string $resource, Request $request): Response
    {
        $scope = $this->scope($request);
        return $this->reply(RuntimeFactory::admin()->save($resource, RequestInput::json($request), $scope, $this->actor($request)));
    }

    private function publishing(string $resource, Request $request): Response
    {
        $scope = $this->scope($request);
        return $this->reply(RuntimeFactory::admin()->publish($resource, RequestInput::text(RequestInput::json($request), 'id'), $scope, $this->actor($request)));
    }

    private function acting(string $resource, string $action, Request $request): Response
    {
        $scope = $this->scope($request);
        $input = $request->method() === 'GET' ? $request->get() : RequestInput::json($request);
        return $this->reply(RuntimeFactory::admin($resource, $action)->action($resource, $action, $input, $scope, $this->actor($request)));
    }

    private function scope(Request $request): array
    {
        $checked = $request->header('check_admin');
        $current = function_exists('getCurrentInfo') ? getCurrentInfo() : false;
        if ($request->header('check_login') !== true || !is_array($checked) || !is_array($current)
            || ($checked['plat'] ?? '') !== 'sandadmin' || ($current['plat'] ?? '') !== 'sandadmin'
            || (string) ($checked['id'] ?? '') !== (string) ($current['id'] ?? '')) {
            throw new ApiException('SAND_LICENSE_ADMIN_LOGIN_REQUIRED: 请重新登录后台', 401);
        }
        $id = filter_var($checked['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($id) || !class_exists(AdminOrganizationAccess::class)) {
            throw new ApiException('SAND_LICENSE_ADMIN_SCOPE_UNAVAILABLE: 管理组织范围不可验证，请联系平台管理员', 401);
        }
        $info = UserInfoCache::getUserInfo($id);
        $access = new AdminOrganizationAccess($id, $info);
        $super = $access->isSuperAdmin();
        return ['organization_ids' => $super ? null : $access->organizationIds(), 'is_super_admin' => $super, 'actor_id' => $id, 'admin_info' => $info];
    }

    private function actor(Request $request): array
    {
        return ['id' => (string) $request->header('check_admin')['id'], 'request_id' => (string) $request->header('x-request-id', '')];
    }

    private function reply(array $result): Response
    {
        $result['server_time'] ??= gmdate('Y-m-d\TH:i:s\Z');
        return $this->success($result)->withHeader('Cache-Control', 'no-store');
    }
}
