<?php

declare(strict_types=1);

namespace app\SandLicense\Validate;

use plugin\sandadmin\exception\ApiException;
use support\Request;

final class RequestInput
{
    /** Installation-proof endpoints bind every business input in the raw JSON body. */
    public static function signedJson(Request $request): array
    {
        if ($request->method() !== 'POST' || $request->get() !== []) {
            throw new ApiException('SAND_LICENSE_REQUEST_INVALID: 安装证明接口只接受无查询参数的 POST JSON 请求', 400);
        }
        return self::json($request);
    }

    /** SQL/domain product and channel codes share the same 80-byte boundary. */
    public static function code(array $input, string $field): string
    {
        return self::text($input, $field, 80);
    }

    /** @return array<string,mixed> */
    public static function json(Request $request): array
    {
        $raw = $request->rawBody();
        if (strlen($raw) > 32768 || !str_starts_with(strtolower((string) $request->header('content-type', '')), 'application/json')) {
            throw new ApiException('SAND_LICENSE_REQUEST_INVALID: 请提交不超过 32KB 的 JSON 请求', 400);
        }
        try {
            $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new ApiException('SAND_LICENSE_REQUEST_INVALID: JSON 请求格式无效', 400);
        }
        if (!is_array($input) || array_is_list($input)) {
            throw new ApiException('SAND_LICENSE_REQUEST_INVALID: 请求应为 JSON 对象', 400);
        }
        return $input;
    }

    public static function text(array $input, string $field, int $max = 191): string
    {
        $value = $input[$field] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new ApiException('SAND_LICENSE_REQUEST_INVALID: 缺少或无效字段 ' . $field, 400);
        }
        return $value;
    }

    public static function requestId(Request $request, array $input, bool $idempotent = false): string
    {
        $value = $idempotent ? $request->header('idempotency-key', '') : ($input['request_id'] ?? $request->header('x-request-id', ''));
        return self::text(['request_id' => $value], 'request_id', 128);
    }
}
