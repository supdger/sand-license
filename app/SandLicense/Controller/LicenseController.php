<?php

declare(strict_types=1);

namespace app\SandLicense\Controller;

use app\SandLicense\Integration\RuntimeFactory;
use app\SandLicense\Validate\RequestInput;
use plugin\sandadmin\basic\OpenController;
use plugin\sandadmin\exception\ApiException;
use support\Request;
use support\Response;

final class LicenseController extends OpenController
{
    public function challenge(Request $request): Response
    {
        $input = RequestInput::json($request);
        return $this->privateResponse(RuntimeFactory::licensing('challenge')->challenge($input, time()));
    }

    public function redeem(Request $request): Response { return $this->invoke($request, 'redeem', '/redemptions', true); }
    public function renew(Request $request): Response { return $this->invoke($request, 'renew', '/leases/renew'); }
    public function current(Request $request): Response { return $this->invoke($request, 'current', '/entitlements/current'); }
    public function release(Request $request): Response { return $this->invoke($request, 'release', '/activations/release', true); }
    public function ticket(Request $request): Response { return $this->invoke($request, 'ticket', '/enrollment-tickets', true); }
    public function enroll(Request $request): Response { return $this->invoke($request, 'enroll', '/activations/enroll', true); }

    public function jwks(Request $request): Response
    {
        $keys = RuntimeFactory::configuration()['public_keys'] ?? [];
        if (!is_array($keys)) throw new ApiException('SAND_LICENSE_CONFIGURATION_REQUIRED: 公钥目录尚未配置', 400);
        $public = [];
        foreach ($keys as $kid => $key) {
            if (!is_array($key) || isset($key['d']) || ($key['kty'] ?? '') !== 'OKP'
                || ($key['crv'] ?? '') !== 'Ed25519' || !is_string($key['x'] ?? null)
                || !is_string($kid) || $kid === '') {
                throw new ApiException('SAND_LICENSE_CONFIGURATION_REQUIRED: 公钥目录格式无效', 400);
            }
            $public[] = ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => $key['x'], 'kid' => $kid, 'alg' => 'EdDSA', 'use' => 'sig'];
        }
        return json(['keys' => $public])->withHeader('Cache-Control', 'public, max-age=300');
    }

    private function invoke(Request $request, string $action, string $path, bool $idempotent = false): Response
    {
        $input = RequestInput::signedJson($request);
        $input['request_id'] = RequestInput::requestId($request, $input, $idempotent);
        $facts = [
            'method' => $request->method(),
            'configured_url' => RuntimeFactory::origin() . '/api/sand-license/v1' . $path,
            'raw_body' => $request->rawBody(),
            'proof' => (string) $request->header('sand-license-proof', ''),
        ];
        $logic = RuntimeFactory::licensing($action);
        $now = time();
        $result = match ($action) {
            'redeem' => $logic->redeem($input, $facts, $now),
            'renew' => $logic->renew($input, $facts, $now),
            'current' => $logic->current($input, $facts, $now),
            'release' => $logic->release($input, $facts, $now),
            'ticket' => $logic->ticket($input, $facts, $now),
            'enroll' => $logic->enroll($input, $facts, $now),
        };
        return $this->privateResponse($result);
    }

    private function privateResponse(array $result): Response
    {
        return $this->success($result)->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
    }
}
