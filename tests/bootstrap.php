<?php

declare(strict_types=1);

// Real Webman / Think runtime dependencies, without loading config or touching DB.
$host = getenv('SAND_LICENSE_TEST_HOST_AUTOLOAD') ?: '';
if (!is_file($host)) {
    throw new RuntimeException('Set SAND_LICENSE_TEST_HOST_AUTOLOAD to an installed SandAdmin vendor/autoload.php');
}
require_once $host;
$source = dirname(__DIR__);
$server = dirname($host, 2);
$iamFunctions = getenv('SAND_LICENSE_TEST_IAM_FUNCTIONS') ?: $server . '/plugin/sand-iam/app/functions.php';
$iamRoot = dirname($iamFunctions, 2);
spl_autoload_register(static function (string $class) use ($source, $server, $iamRoot): void {
    $roots = [
        'plugin\\sandadmin\\' => $server . '/plugin/sandadmin/',
        'plugin\\SandIam\\' => $iamRoot . '/',
        'app\\SandLicense\\' => $source . '/app/SandLicense/',
        'plugin\\SandLicense\\' => $source . '/plugin/sand-license/',
        'plugin\\sandpackage\\' => $server . '/plugin/sandpackage/',
    ];
    foreach ($roots as $prefix => $root) {
        if (!str_starts_with($class, $prefix)) continue;
        $path = $root . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) require_once $path;
        return;
    }
}, true, true);
require_once dirname(__DIR__) . '/plugin/sand-license/app/functions.php';
if (!class_exists(\plugin\sandadmin\exception\ApiException::class)
    || !class_exists(\support\Response::class) || !class_exists(\support\Request::class)) {
    throw new RuntimeException('Real SandAdmin/Webman exception and HTTP dependency required');
}

function licenseAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
