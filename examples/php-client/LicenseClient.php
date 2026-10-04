<?php

declare(strict_types=1);

namespace SandLicenseExample;

use Closure;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use RuntimeException;
use stdClass;
use Throwable;

/** A safe, fixed message; never put server response bodies, keys, codes or tokens in errors. */
final class ClientFailure extends RuntimeException
{
    public function __construct(string $message, public readonly bool $transient = false)
    {
        parent::__construct($message);
    }
}

/**
 * Framework independent reference client using the already installed JWT dependency.
 * It deliberately never stores or reloads a lease: every process start requires the server.
 */
final class LicenseClient
{
    private readonly Closure $wallClock;
    private readonly Closure $monotonicClock;
    private readonly ?Closure $transport;
    private readonly string $secretKey;
    /** @var array{kty:string,crv:string,x:string} */
    private readonly array $publicJwk;
    private readonly string $thumbprint;
    /** @var array<string,mixed> */
    private array $binding = [];
    /** @var array<string,mixed>|null */
    private ?array $lease = null;
    private float $deadline = 0.0;
    private float $renewAt = 0.0;

    /**
     * Clocks and transport are injectable for isolated tests; production uses time/hrtime/curl.
     *
     * @param Closure(string,string,string,array<string,string>):array{status:int,body:string}|null $transport
     * @param Closure():int|null $wallClock
     * @param Closure():float|null $monotonicClock
     */
    public function __construct(
        private readonly string $origin,
        private readonly string $issuer,
        private readonly string $audience,
        private readonly string $productCode,
        private readonly string $keyFile,
        private readonly string $stateFile,
        private readonly ?string $caFile = null,
        ?Closure $transport = null,
        ?Closure $wallClock = null,
        ?Closure $monotonicClock = null,
    ) {
        if (preg_match('~\Ahttps://[A-Za-z0-9.-]+(?::[0-9]{1,5})?\z~D', $origin) !== 1
            || $issuer === '' || $audience === '' || $productCode === ''
            || $keyFile === $stateFile
            || !class_exists(JWT::class) || !class_exists(JWK::class)
            || !function_exists('sodium_crypto_sign_keypair') || !function_exists('curl_init')
        ) {
            throw new ClientFailure('CLIENT_CONFIGURATION_REQUIRED：检查 HTTPS 地址、产品配置及已有 JWT/Curl/Sodium 依赖');
        }
        $this->wallClock = $wallClock ?? static fn (): int => time();
        $this->monotonicClock = $monotonicClock ?? static fn (): float => hrtime(true) / 1_000_000_000;
        $this->transport = $transport;
        self::checkPrivatePath($keyFile);
        self::checkPrivatePath($stateFile);
        if (!is_file($keyFile)) {
            $pair = sodium_crypto_sign_keypair();
            self::writePrivateFile($keyFile, base64_encode(sodium_crypto_sign_secretkey($pair)), false);
            sodium_memzero($pair);
        }
        $encoded = file_get_contents($keyFile);
        $secret = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new ClientFailure('CLIENT_KEY_INVALID：安装私钥无效；请通过设备恢复流程处理，勿覆盖已有密钥');
        }
        $this->secretKey = $secret;
        $this->publicJwk = [
            'kty' => 'OKP', 'crv' => 'Ed25519',
            'x' => JWT::urlsafeB64Encode(sodium_crypto_sign_publickey_from_secretkey($secret)),
        ];
        $canonical = json_encode(['crv' => 'Ed25519', 'kty' => 'OKP', 'x' => $this->publicJwk['x']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->thumbprint = JWT::urlsafeB64Encode(hash('sha256', $canonical, true));
        if (is_file($stateFile)) {
            $state = json_decode((string) file_get_contents($stateFile), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($state) || ($state['origin'] ?? null) !== $origin
                || ($state['issuer'] ?? null) !== $issuer || ($state['audience'] ?? null) !== $audience
                || ($state['product_code'] ?? null) !== $productCode || ($state['jkt'] ?? null) !== $this->thumbprint
            ) {
                throw new ClientFailure('CLIENT_BINDING_MISMATCH：此安装记录属于其他产品、服务或设备密钥');
            }
            $this->binding = $state;
        }
    }

    /** This always clears the current run before making a fresh online operation. */
    public function start(#[\SensitiveParameter] ?string $code = null): array
    {
        $this->stop();
        if (isset($this->binding['activation_id'], $this->binding['entitlement_id'])) {
            return $this->renew();
        }
        if ($code === null || $code === '') {
            throw new ClientFailure('CLIENT_CODE_REQUIRED：首次安装需要领取的授权码文件；已有安装只需原设备密钥与记录');
        }
        $requestId = $this->binding['redemption_request_id'] ?? bin2hex(random_bytes(24));
        $this->persistBinding(['redemption_request_id' => $requestId]);
        $response = $this->proved('redeem', '/redemptions', [
            'code' => $code, 'installation_public_key' => $this->publicJwk,
            'name' => 'PHP reference installation',
        ], (string) $requestId);
        return $this->acceptOnlineLease($response);
    }

    public function renew(): array
    {
        $this->requireActivation();
        try {
            $response = $this->proved('renew', '/leases/renew', ['activation_id' => $this->binding['activation_id']]);
            return $this->acceptOnlineLease($response);
        } catch (ClientFailure $failure) {
            if (!$failure->transient) $this->stop();
            throw $failure;
        }
    }

    /** Every query uses a new challenge and signs all selectors in a POST body. */
    public function current(): array
    {
        $this->requireActivation();
        try {
            $data = $this->proved('current', '/entitlements/current', ['activation_id' => $this->binding['activation_id']])['data'];
            if (($data['state'] ?? null) !== 'active'
                || ($data['activation_id'] ?? null) !== $this->binding['activation_id']
                || ($data['entitlement_id'] ?? null) !== $this->binding['entitlement_id']
                || !is_array($data['features'] ?? null)
            ) throw new ClientFailure('CLIENT_PROTOCOL_INVALID：当前权益响应与本安装不匹配');
            return $data;
        } catch (ClientFailure $failure) {
            if (!$failure->transient) $this->stop();
            throw $failure;
        }
    }

    /** Explicit release stops this run immediately, even if the reply is lost. */
    public function release(string $requestId): array
    {
        $this->requireActivation();
        $this->stop();
        return $this->proved('release', '/activations/release', [
            'activation_id' => $this->binding['activation_id'], 'issue_enrollment_ticket' => false,
        ], $requestId)['data'];
    }

    public function canRun(): bool
    {
        if ($this->lease === null) return false;
        if (($this->monotonicClock)() >= $this->deadline || ($this->wallClock)() >= $this->lease['exp']) {
            $this->stop();
            return false;
        }
        return true;
    }

    public function renewalDue(): bool
    {
        return $this->canRun() && ($this->monotonicClock)() >= $this->renewAt;
    }

    /** Guard the actual feature operation, not only a startup UI. */
    public function requireFeature(string $feature): mixed
    {
        if (!$this->canRun() || !array_key_exists($feature, $this->lease['features'])
            || in_array($this->lease['features'][$feature], [false, null, 0, '0'], true)
        ) {
            throw new ClientFailure('CLIENT_FEATURE_UNAVAILABLE：租约已停止或当前套餐没有该功能');
        }
        return $this->lease['features'][$feature];
    }

    /** Safe UI/CLI summary; it never exposes lease, proof, key, nonce or redemption code. */
    public function status(): array
    {
        return [
            'can_run' => $this->canRun(),
            'product_code' => $this->productCode,
            'activation_id' => $this->binding['activation_id'] ?? null,
            'entitlement_id' => $this->binding['entitlement_id'] ?? null,
            'lease_expire_time' => $this->lease === null ? null : gmdate('Y-m-d\TH:i:s\Z', $this->lease['exp']),
        ];
    }

    /**
     * Standard JWK/JWT verification plus application claims; no request authorization is
     * established here. Only private acceptOnlineLease can admit the current process run.
     *
     * @param array<string,mixed> $jwks
     * @return array<string,mixed>
     */
    public function verifyLease(string $token, array $jwks, string $entitlementId, string $activationId, int $now): array
    {
        try {
            $publicKeys = [];
            if (!is_array($jwks['keys'] ?? null) || $jwks['keys'] === []) throw new RuntimeException('Empty public key catalog');
            foreach ($jwks['keys'] as $jwk) {
                if (!is_array($jwk) || ($jwk['kty'] ?? null) !== 'OKP' || ($jwk['crv'] ?? null) !== 'Ed25519'
                    || ($jwk['alg'] ?? null) !== 'EdDSA' || ($jwk['use'] ?? null) !== 'sig'
                    || !is_string($jwk['kid'] ?? null) || $jwk['kid'] === ''
                    || !is_string($jwk['x'] ?? null) || array_key_exists('d', $jwk)
                    || isset($publicKeys[$jwk['kid']])
                ) throw new RuntimeException('Invalid public key catalog');
                $raw = base64_decode(strtr($jwk['x'], '-_', '+/') . '=', true);
                if ($raw === false || strlen($raw) !== 32 || JWT::urlsafeB64Encode($raw) !== $jwk['x']) {
                    throw new RuntimeException('Invalid public key');
                }
                $key = JWK::parseKey($jwk, 'EdDSA');
                if ($key === null) throw new RuntimeException('Invalid verification key');
                $publicKeys[$jwk['kid']] = $key;
            }
            $header = new stdClass();
            $payload = JWT::decode($token, $publicKeys, $header);
            $claims = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 16, JSON_THROW_ON_ERROR);
            if (($header->typ ?? null) !== 'JWT' || ($header->alg ?? null) !== 'EdDSA'
                || !is_string($header->kid ?? null) || !isset($publicKeys[$header->kid]) || isset($header->crit)
                || !is_array($claims)
            ) throw new RuntimeException('Invalid JWT contract');
            foreach (['iss', 'aud', 'sub', 'entitlement_id', 'activation_id', 'product_code', 'jti'] as $name) {
                if (!is_string($claims[$name] ?? null) || $claims[$name] === '') throw new RuntimeException('Missing string claim');
            }
            foreach (['iat', 'nbf', 'exp', 'plan_revision'] as $name) {
                if (!is_int($claims[$name] ?? null) || $claims[$name] <= 0) throw new RuntimeException('Missing integer claim');
            }
            foreach (['sub', 'entitlement_id', 'activation_id'] as $name) {
                if (preg_match('/^[1-9][0-9]{0,18}$/D', $claims[$name]) !== 1) throw new RuntimeException('Invalid identifier');
            }
            if ($claims['iss'] !== $this->issuer || $claims['aud'] !== $this->audience
                || $claims['product_code'] !== $this->productCode || $claims['sub'] !== $entitlementId
                || $claims['entitlement_id'] !== $entitlementId || $claims['activation_id'] !== $activationId
                || !is_array($claims['features'] ?? null) || !is_array($claims['cnf'] ?? null)
                || !is_string($claims['cnf']['jkt'] ?? null) || !hash_equals($this->thumbprint, $claims['cnf']['jkt'])
                || $claims['nbf'] > $claims['iat'] || $claims['iat'] > $now || $claims['exp'] <= $now
                || $claims['exp'] <= $claims['iat'] || $claims['exp'] - $claims['iat'] > 900
            ) throw new RuntimeException('Invalid lease binding or interval');
            return $claims;
        } catch (Throwable) {
            throw new ClientFailure('CLIENT_LEASE_INVALID：租约验签或产品、设备、时间绑定失败');
        }
    }

    /** @return array{data:array<string,mixed>,sent_wall:int,sent_monotonic:float} */
    private function proved(string $purpose, string $path, array $input, ?string $requestId = null): array
    {
        $challenge = $this->request('POST', '/challenges', json_encode([
            'product_code' => $this->productCode, 'purpose' => $purpose,
            'installation_public_key' => $this->publicJwk,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $nonce = $challenge['data']['challenge'] ?? null;
        if (!is_string($nonce) || preg_match('/^[A-Za-z0-9_-]{43}$/D', $nonce) !== 1) {
            throw new ClientFailure('CLIENT_PROTOCOL_INVALID：服务端挑战响应不完整');
        }
        $requestId ??= bin2hex(random_bytes(24));
        $body = json_encode($input + ['product_code' => $this->productCode, 'challenge' => $nonce, 'request_id' => $requestId], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $proof = JWT::encode([
            'htm' => 'POST', 'htu' => $this->origin . '/api/sand-license/v1' . $path,
            'iat' => ($this->wallClock)(), 'jti' => bin2hex(random_bytes(24)), 'nonce' => $nonce,
            'body_sha256' => hash('sha256', $body), 'product_code' => $this->productCode,
        ], base64_encode($this->secretKey), 'EdDSA', null, ['typ' => 'sand-license-proof+jwt']);
        return $this->request('POST', $path, $body, [
            'Sand-License-Proof' => $proof, 'Idempotency-Key' => $requestId, 'X-Request-ID' => $requestId,
        ]);
    }

    /** @param array{data:array<string,mixed>,sent_wall:int,sent_monotonic:float} $response */
    private function acceptOnlineLease(array $response): array
    {
        try {
            $data = $response['data'];
            $activation = $data['activation_id'] ?? null;
            $entitlement = $data['entitlement_id'] ?? null;
            if (!is_string($activation) || preg_match('/^[1-9][0-9]{0,18}$/D', $activation) !== 1
                || !is_string($entitlement) || preg_match('/^[1-9][0-9]{0,18}$/D', $entitlement) !== 1
                || !is_string($data['lease'] ?? null) || ($data['state'] ?? null) !== 'active'
                || (isset($this->binding['activation_id']) && $this->binding['activation_id'] !== $activation)
                || (isset($this->binding['entitlement_id']) && $this->binding['entitlement_id'] !== $entitlement)
            ) throw new ClientFailure('CLIENT_PROTOCOL_INVALID：在线许可响应与安装记录不匹配');
            $catalog = $this->request('GET', '/.well-known/jwks.json', '');
            $verifiedWall = ($this->wallClock)();
            $verifiedMonotonic = ($this->monotonicClock)();
            $claims = $this->verifyLease($data['lease'], $catalog['data'], $entitlement, $activation, $verifiedWall);
            // A slow pre-request clock must never exceed the signed lifetime. Consume
            // elapsed request/JWKS time, and also honor the wall clock at verification.
            $elapsed = max(0.0, $verifiedMonotonic - $response['sent_monotonic']);
            $initialBudget = min($claims['exp'] - $response['sent_wall'], $claims['exp'] - $claims['iat']);
            $remainingBudget = max(0.0, min($initialBudget - $elapsed, $claims['exp'] - $verifiedWall));
            $deadline = $verifiedMonotonic + $remainingBudget;
            if (($this->monotonicClock)() >= $deadline) throw new ClientFailure('CLIENT_LEASE_INVALID：租约已到期');
            $this->persistBinding(['activation_id' => $activation, 'entitlement_id' => $entitlement]);
            $this->lease = $claims;
            $this->deadline = $deadline;
            $this->renewAt = min($deadline, $response['sent_monotonic'] + 300);
            return $this->status();
        } catch (Throwable $failure) {
            if ($failure instanceof ClientFailure && $failure->transient) throw $failure;
            $this->stop();
            if ($failure instanceof ClientFailure) throw $failure;
            throw new ClientFailure('CLIENT_PROTOCOL_INVALID：在线许可响应不完整');
        }
    }

    /** @return array{data:array<string,mixed>,sent_wall:int,sent_monotonic:float} */
    private function request(string $method, string $path, string $body, array $headers = []): array
    {
        $sentWall = ($this->wallClock)();
        $sentMonotonic = ($this->monotonicClock)();
        $url = $this->origin . '/api/sand-license/v1' . $path;
        $headers += ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        $result = $this->transport !== null
            ? ($this->transport)($method, $url, $body, $headers)
            : self::httpsRequest($method, $url, $body, $headers, $this->caFile);
        $status = $result['status'];
        if ($status === 429 || $status >= 500) {
            throw new ClientFailure('CLIENT_SERVER_UNAVAILABLE：许可服务暂时不可用，只能等待或在当前租约截止前重试', true);
        }
        if ($status !== 200) throw new ClientFailure('CLIENT_SERVER_REJECTED：许可服务拒绝请求；请检查配置或联系发放方');
        try {
            $payload = json_decode($result['body'], true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new ClientFailure('CLIENT_PROTOCOL_INVALID：许可服务响应格式无效');
        }
        if (!is_array($payload)) throw new ClientFailure('CLIENT_PROTOCOL_INVALID：许可服务响应格式无效');
        if ($path === '/.well-known/jwks.json') {
            $data = $payload;
        } else {
            if (($payload['code'] ?? null) !== 200) {
                $error = is_string($payload['message'] ?? null) && preg_match('/SAND_LICENSE_[A-Z_]+/', $payload['message'], $matches) === 1
                    ? $matches[0] : 'CLIENT_SERVER_REJECTED';
                throw new ClientFailure($error . '：许可服务拒绝请求；请按接入文档处理');
            }
            $data = $payload['data'] ?? null;
        }
        if (!is_array($data)) throw new ClientFailure('CLIENT_PROTOCOL_INVALID：许可服务响应数据缺失');
        return ['data' => $data, 'sent_wall' => $sentWall, 'sent_monotonic' => $sentMonotonic];
    }

    /** @return array{status:int,body:string} */
    public static function httpsRequest(string $method, string $url, string $body, array $headers, ?string $caFile = null, ?int $testConnectionPort = null): array
    {
        $handle = curl_init($url);
        if ($handle === false) throw new ClientFailure('CLIENT_NETWORK_UNAVAILABLE：无法初始化 HTTPS 客户端', true);
        $response = '';
        $options = [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => array_map(
                static fn (string $name, string $value): string => $name . ': ' . $value, array_keys($headers), array_values($headers),
            ),
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function (\CurlHandle $unused, string $bytes) use (&$response): int {
                if (strlen($response) + strlen($bytes) > 65536) return 0;
                $response .= $bytes;
                return strlen($bytes);
            },
        ];
        if ($method === 'POST') $options[CURLOPT_POSTFIELDS] = $body;
        if ($caFile !== null) $options[CURLOPT_CAINFO] = $caFile;
        // Live-test only: keep canonical URI, TLS hostname/CA and proof untouched.
        if ($testConnectionPort !== null) {
            $parts = parse_url($url);
            if (($parts['host'] ?? null) !== '127.0.0.1' || $testConnectionPort < 1 || $testConnectionPort > 65535) {
                curl_close($handle);
                throw new ClientFailure('CLIENT_TEST_TARGET_INVALID：连接故障测试仅允许独占的 loopback 端口');
            }
            $options[CURLOPT_CONNECT_TO] = ['127.0.0.1:' . ($parts['port'] ?? 443) . ':127.0.0.1:' . $testConnectionPort];
        }
        curl_setopt_array($handle, $options);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($handle);
        curl_close($handle);
        if ($ok === false) throw new ClientFailure('CLIENT_NETWORK_UNAVAILABLE：HTTPS 连接失败（curl ' . $error . '）；首次启动不可离线放行', true);
        return ['status' => $status, 'body' => $response];
    }

    private function requireActivation(): void
    {
        if (!isset($this->binding['activation_id'], $this->binding['entitlement_id'])) {
            throw new ClientFailure('CLIENT_INSTALLATION_REQUIRED：请先在线完成首次兑换');
        }
    }

    private function stop(): void
    {
        $this->lease = null;
        $this->deadline = 0.0;
        $this->renewAt = 0.0;
    }

    private function persistBinding(array $facts): void
    {
        $this->binding = [
            'origin' => $this->origin, 'issuer' => $this->issuer, 'audience' => $this->audience,
            'product_code' => $this->productCode, 'jkt' => $this->thumbprint,
        ] + $facts;
        self::writePrivateFile($this->stateFile, json_encode($this->binding, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true);
    }

    /** This reference covers POSIX storage; use an OS key store in a production desktop client. */
    private static function checkPrivatePath(string $path): void
    {
        $directory = dirname($path);
        if (DIRECTORY_SEPARATOR !== '/' || !str_starts_with($path, '/') || is_link($path)
            || realpath($directory) !== $directory || !is_dir($directory)
            || (fileperms($directory) & 0777) !== 0700
            || (function_exists('posix_geteuid') && fileowner($directory) !== posix_geteuid())
            || (file_exists($path) && (!is_file($path) || (fileperms($path) & 0777) !== 0600 || filesize($path) > 8192
                || (function_exists('posix_geteuid') && fileowner($path) !== posix_geteuid())))
        ) throw new ClientFailure('CLIENT_PRIVATE_STORAGE_REQUIRED：使用当前用户独占的绝对目录（0700）与普通文件（0600），禁止链接或共享目录');
    }

    private static function writePrivateFile(string $path, #[\SensitiveParameter] string $bytes, bool $replace): void
    {
        self::checkPrivatePath($path);
        $target = $replace ? dirname($path) . '/.license-write-' . bin2hex(random_bytes(12)) : $path;
        $oldMask = umask(0077);
        try {
            $file = fopen($target, 'x');
        } finally {
            umask($oldMask);
        }
        if ($file === false) throw new ClientFailure('CLIENT_STORAGE_FAILED：无法创建安装文件');
        try {
            if (fwrite($file, $bytes) !== strlen($bytes) || !fflush($file)) {
                throw new ClientFailure('CLIENT_STORAGE_FAILED：安装文件写入失败');
            }
        } catch (Throwable $failure) {
            fclose($file);
            unlink($target);
            throw $failure;
        } finally {
            if (is_resource($file)) fclose($file);
        }
        if ($replace && !rename($target, $path)) {
            unlink($target);
            throw new ClientFailure('CLIENT_STORAGE_FAILED：无法保存安装记录');
        }
    }
}
