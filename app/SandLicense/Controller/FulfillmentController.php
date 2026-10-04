<?php

declare(strict_types=1);

namespace app\SandLicense\Controller;

use app\SandLicense\Integration\RuntimeFactory;
use app\SandLicense\Integration\SandIamAuthorization;
use app\SandLicense\Logic\MembershipLogic;
use app\SandLicense\Validate\RequestInput;
use plugin\sandadmin\basic\OpenController;
use plugin\sandadmin\exception\ApiException;
use support\Request;
use support\Response;

final class FulfillmentController extends OpenController
{
    public function ingest(Request $request): Response
    {
        $input = RequestInput::json($request);
        $input['request_id'] = RequestInput::requestId($request, $input);
        $scope = $this->scope($request, $input, 'sand_license.fulfillment.write');
        return $this->privateResponse(RuntimeFactory::fulfillment()->ingest($input, $scope, time()));
    }

    public function read(Request $request, string $id): Response
    {
        $input = $request->get();
        $input['request_id'] = RequestInput::requestId($request, $input);
        $scope = $this->scope($request, $input, 'sand_license.fulfillment.read');
        return $this->privateResponse(RuntimeFactory::fulfillment()->read($id, $scope, time()));
    }

    public function credentialReissue(Request $request, string $id): Response
    {
        $input = RequestInput::json($request);
        $input['request_id'] = RequestInput::requestId($request, $input);
        $scope = $this->scope($request, $input, 'sand_license.fulfillment.write');
        return $this->privateResponse(RuntimeFactory::fulfillment()->refreshClaimCredential($id, $input, $scope, time()));
    }

    public function membership(Request $request): Response
    {
        $input = $request->get();
        $input['request_id'] = RequestInput::requestId($request, $input);
        RequestInput::text($input, 'subject_code');
        $scope = $this->scope($request, $input, 'sand_license.membership.read');
        return $this->privateResponse((new MembershipLogic())->current($input, $scope, time()));
    }

    public function claimStatus(Request $request, string $id): Response
    {
        $input = RequestInput::json($request);
        $input['request_id'] = RequestInput::requestId($request, $input);
        return $this->privateResponse(RuntimeFactory::fulfillment()->status($id, $input, time()));
    }

    public function claim(Request $request, string $id): Response
    {
        $input = RequestInput::json($request);
        $input['request_id'] = RequestInput::requestId($request, $input);
        return $this->privateResponse(RuntimeFactory::fulfillment()->claim($id, $input, time()));
    }

    public function reissue(Request $request, string $id): Response
    {
        $input = RequestInput::json($request);
        $input['request_id'] = RequestInput::requestId($request, $input);
        return $this->privateResponse(RuntimeFactory::fulfillment()->reissue($id, $input, time()));
    }

    private function scope(Request $request, array $input, string $action): array
    {
        $header = trim((string) $request->header('authorization', ''));
        if (!str_starts_with($header, 'Bearer ') || trim(substr($header, 7)) === '') {
            throw new ApiException('SAND_LICENSE_IDENTITY_REQUIRED: 需要正式 SandIAM Bearer 服务上下文', 401);
        }
        return (new SandIamAuthorization(RuntimeFactory::configuration()))->authorize(
            trim(substr($header, 7)), $action,
            RequestInput::code($input, 'channel_code'),
            RequestInput::code($input, 'product_code'),
            isset($input['subject_code']) ? RequestInput::text($input, 'subject_code') : '',
            $request->getRemoteIp(), $input['request_id'],
        );
    }

    private function privateResponse(array $result): Response
    {
        return $this->success($result)->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
    }
}
