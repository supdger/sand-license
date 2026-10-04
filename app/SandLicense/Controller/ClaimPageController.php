<?php

declare(strict_types=1);

namespace app\SandLicense\Controller;

use plugin\sandadmin\exception\ApiException;
use support\Request;
use support\Response;

final class ClaimPageController
{
    public function index(Request $request): Response { return $this->asset('index.html', 'text/html; charset=utf-8'); }
    public function css(Request $request): Response { return $this->asset('claim.css', 'text/css; charset=utf-8'); }
    public function javascript(Request $request): Response { return $this->asset('claim.js', 'application/javascript; charset=utf-8'); }

    private function asset(string $file, string $type): Response
    {
        $path = base_path() . '/plugin/sand-license/public/claim/' . $file;
        if (!is_file($path)) throw new ApiException('SAND_LICENSE_RESOURCE_UNAVAILABLE: 领取页面资源缺失，请联系发放方', 400);
        return (new Response(200, ['Content-Type' => $type], (string) file_get_contents($path)))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
    }
}
