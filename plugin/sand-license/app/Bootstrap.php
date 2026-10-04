<?php

declare(strict_types=1);

namespace plugin\SandLicense\app;

use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/** Explicit Webman lifecycle dependency check; no database or secret access. */
final class Bootstrap
{
    public static function load(): void
    {
        self::registerPluginLoader();
        if (!function_exists('base_path')) self::missing();
        $hostVendor = realpath(base_path() . '/vendor');
        if ($hostVendor === false) self::missing();
        $metadata = $hostVendor . '/composer/installed.php';
        if (!is_file($metadata)) self::missing();
        if (is_link($metadata)) self::incompatible();
        // Composer's global InstalledVersions class may legitimately be defined
        // by an earlier plugin. Only this host's installed dataset is authority.
        try { $installed = require $metadata; }
        catch (\Throwable) { self::incompatible(); }
        if (!is_array($installed) || !is_array($installed['versions'] ?? null)) self::incompatible();
        $hostVersions = $installed['versions'];
        $packageRoots = [];
        foreach (['tinywan/jwt', 'firebase/php-jwt'] as $package) {
            $entry = $hostVersions[$package] ?? null;
            if (!is_array($entry) || !is_string($entry['version'] ?? null)
                || !is_string($entry['install_path'] ?? null)) self::incompatible();
            $root = realpath($entry['install_path']);
            $expected = realpath($hostVendor . '/' . $package);
            if ($root === false || $expected === false || $root !== $expected) self::incompatible();
            $packageRoots[$package] = $root;
        }
        $version = $hostVersions['firebase/php-jwt']['version'];
        if (preg_match('/^([67])\.\d+\.\d+(?:\.\d+)?$/D', $version, $matches) !== 1
            || ($matches[1] === '6' && version_compare($version, '6.8.0', '<'))
            || ($matches[1] === '7' && version_compare($version, '7.0.0', '<'))) {
            self::incompatible();
        }
        $types = [
            'Tinywan\\Jwt\\JwtToken' => ['tinywan/jwt', 'src/JwtToken.php', ['generateToken', 'verify']],
            'Firebase\\JWT\\JWT' => ['firebase/php-jwt', 'src/JWT.php', ['encode', 'decode', 'urlsafeB64Encode']],
            'Firebase\\JWT\\Key' => ['firebase/php-jwt', 'src/Key.php', ['getAlgorithm', 'getKeyMaterial']],
            'Firebase\\JWT\\JWK' => ['firebase/php-jwt', 'src/JWK.php', ['parseKey']],
        ];
        foreach ($types as $class => [$package, $relative, $methods]) {
            $root = $packageRoots[$package];
            if (!class_exists($class)) self::incompatible();
            $actual = (new ReflectionClass($class))->getFileName();
            $expected = realpath($root . '/' . $relative);
            if (!is_string($actual) || $expected === false || realpath($actual) !== $expected) self::incompatible();
            foreach ($methods as $method) {
                if (!method_exists($class, $method) || !(new ReflectionMethod($class, $method))->isPublic()) self::incompatible();
            }
        }
        $jwt = new ReflectionClass('Firebase\\JWT\\JWT');
        if (!$jwt->hasProperty('supported_algs')) self::incompatible();
        $algorithms = $jwt->getProperty('supported_algs');
        if (!$algorithms->isPublic() || !$algorithms->isStatic()
            || !isset($algorithms->getValue()['EdDSA'])
            || !function_exists('sodium_crypto_sign_detached')) self::incompatible();
    }

    private static function registerPluginLoader(): void
    {
        static $registered = false;
        if ($registered) return;
        $registered = true;
        $plugin = dirname(__DIR__);
        $application = dirname($plugin, 2) . '/app/SandLicense/';
        // SandPackage does not regenerate the host Composer map. Only our own
        // namespaces are registered; the host owns Tinywan/Firebase class loading.
        spl_autoload_register(static function (string $class) use ($plugin, $application): void {
            foreach (['plugin\\SandLicense\\' => $plugin . '/', 'app\\SandLicense\\' => $application] as $prefix => $root) {
                if (!str_starts_with($class, $prefix)) continue;
                $file = $root . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) require_once $file;
                return;
            }
        });
    }

    private static function incompatible(): never
    {
        throw new RuntimeException('SAND_LICENSE_DEPENDENCY_INCOMPATIBLE: 宿主 Tinywan/Firebase 版本、公开签验 API、EdDSA 能力或实际类来源不兼容');
    }

    private static function missing(): never
    {
        throw new RuntimeException('SAND_LICENSE_DEPENDENCY_MISSING: 需要宿主现有 Tinywan JWT 及其 Firebase 依赖，不提供插件副本');
    }
}
