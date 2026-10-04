<?php

declare(strict_types=1);

namespace plugin\SandLicense\app\middleware;

use app\SandLicense\Security\InstallationProof;
use app\SandLicense\Validate\RequestInput;
use plugin\sandadmin\exception\ApiException;
use support\Redis;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/** Atomic shared Redis counters; unavailable limiter denies issuance. */
final class ChallengeRateLimit implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $input = RequestInput::json($request);
        $product = RequestInput::code($input, 'product_code');
        $key = $input['installation_public_key'] ?? null;
        if (!is_array($key)) throw new ApiException('SAND_LICENSE_REQUEST_INVALID: 安装公钥格式无效', 400);
        $thumbprint = InstallationProof::thumbprint($key);
        $source = $request->getRemoteIp();
        if (filter_var($source, FILTER_VALIDATE_IP) === false) {
            throw new ApiException('SAND_LICENSE_REQUEST_INVALID: 无法确认请求来源', 401);
        }
        $window = (string) intdiv(time(), 60);
        $prefix = 'sand_license:challenge:' . $window . ':';
        $keys = [$prefix . 'ip:' . hash('sha256', $source), $prefix . 'installation:' . $thumbprint, $prefix . 'product:' . hash('sha256', $product)];
        $script = <<<'LUA'
local allowed = 1
for i,key in ipairs(KEYS) do
  local count = redis.call('INCR', key)
  if count == 1 then redis.call('EXPIRE', key, 120) end
  if count > tonumber(ARGV[i]) then allowed = 0 end
end
return allowed
LUA;
        try {
            $allowed = Redis::eval($script, 3, $keys[0], $keys[1], $keys[2], 60, 12, 600);
        } catch (\Throwable) {
            throw new ApiException('SAND_LICENSE_RATE_LIMIT_UNAVAILABLE: 请求限制服务暂不可用，请稍后重试', 400);
        }
        if ((int) $allowed !== 1) throw new ApiException('SAND_LICENSE_RATE_LIMITED: 挑战请求过于频繁，请一分钟后重试', 400);
        return $handler($request);
    }
}
