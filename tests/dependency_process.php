<?php

declare(strict_types=1);

// Independent of tests/bootstrap.php: plugin never supplies a second JWT stack.
[, $mode, $plugin, $host, $oldArchive] = $argv;
$foreign = getenv('SAND_LICENSE_TEST_FOREIGN_HOST_AUTOLOAD') ?: '';
$iam = getenv('SAND_LICENSE_TEST_IAM_FUNCTIONS') ?: dirname($host, 2) . '/plugin/sand-iam/app/functions.php';
$rejectionModes = ['unexpected-classpath', 'mixed-registry', 'foreign-stack', 'metadata-package-missing', 'metadata-foreign-path'];
if (str_starts_with($mode, 'metadata-')) {
    $fixture = tempnam('/private/tmp', 'sand-license-metadata-');
    if ($fixture === false) throw new RuntimeException('Cannot create metadata fixture');
    unlink($fixture);
    mkdir($fixture, 0700); mkdir($fixture . '/vendor', 0700); mkdir($fixture . '/vendor/composer', 0700);
    foreach (['tinywan/jwt', 'firebase/php-jwt'] as $package) {
        mkdir($fixture . '/vendor/' . dirname($package), 0700);
        if (!symlink(dirname($host) . '/' . $package, $fixture . '/vendor/' . $package)) throw new RuntimeException('Cannot bind real metadata fixture package');
    }
    define('BASE_PATH', $fixture);
    if ($mode !== 'metadata-missing') {
        $data = require dirname($host) . '/composer/installed.php';
        if ($mode === 'metadata-package-missing') unset($data['versions']['firebase/php-jwt']);
        else $data['versions']['firebase/php-jwt']['install_path'] = dirname($foreign) . '/firebase/php-jwt';
        file_put_contents($fixture . '/vendor/composer/installed.php', "<?php\nreturn " . var_export($data, true) . ";\n");
    }
    register_shutdown_function(static function () use ($fixture): void {
        if (is_file($fixture . '/vendor/composer/installed.php')) unlink($fixture . '/vendor/composer/installed.php');
        foreach (['tinywan/jwt', 'firebase/php-jwt'] as $package) {
            unlink($fixture . '/vendor/' . $package);
            rmdir($fixture . '/vendor/' . dirname($package));
        }
        rmdir($fixture . '/vendor/composer'); rmdir($fixture . '/vendor'); rmdir($fixture);
    });
    require $host;
}
if ($mode === 'iam-autoload-first') {
    if (!is_file($iam)) throw new RuntimeException('Provide actual installed IAM functions via SAND_LICENSE_TEST_IAM_FUNCTIONS');
    define('BASE_PATH', dirname($host, 2));
    require $host;
    // Exactly the installed Webman autoload file; never preload InstalledVersions.
    require $iam;
    $metadataSource = (new ReflectionClass(\Composer\InstalledVersions::class))->getFileName();
    if (realpath($metadataSource) !== realpath(dirname($iam, 2) . '/vendor/composer/InstalledVersions.php')) {
        throw new RuntimeException('IAM-first fixture did not exercise the real global metadata source');
    }
    echo 'ACTUAL IAM-first Composer metadata source=' . $metadataSource . "\n";
}
if ($mode === 'host-first') require $host;
if ($mode === 'foreign-stack') {
    if (!is_file($foreign) || realpath($foreign) === realpath($host)) throw new RuntimeException('A second real host vendor is required');
    // Match a standard Webman entrypoint in host A, then load host B's whole stack.
    define('BASE_PATH', dirname($host, 2));
    require $foreign;
}
if ($mode === 'mixed-registry') {
    if (!is_file($foreign) || realpath($foreign) === realpath($host)) throw new RuntimeException('A second real host vendor is required');
    require $host;
    class_exists(\Composer\InstalledVersions::class);
    $temporaryRoot = getenv('TMPDIR') ?: '/private/tmp';
    if (!is_dir($temporaryRoot) || !is_writable($temporaryRoot)) $temporaryRoot = '/private/tmp';
    $fixture = tempnam($temporaryRoot, 'sand-license-mixed-registry-');
    if ($fixture === false) throw new RuntimeException('Cannot create real registry fixture');
    unlink($fixture);
    mkdir($fixture, 0700);
    mkdir($fixture . '/composer', 0700);
    $data = require dirname($foreign) . '/composer/installed.php';
    $data['versions'] = ['firebase/php-jwt' => $data['versions']['firebase/php-jwt']];
    file_put_contents($fixture . '/composer/installed.php', "<?php\nreturn " . var_export($data, true) . ";\n");
    $alternate = new \Composer\Autoload\ClassLoader($fixture);
    $alternate->addPsr4('Firebase\\JWT\\', dirname($foreign) . '/firebase/php-jwt/src');
    $alternate->register(true);
    register_shutdown_function(static function () use ($fixture, $alternate): void {
        $alternate->unregister();
        unlink($fixture . '/composer/installed.php');
        rmdir($fixture . '/composer');
        rmdir($fixture);
    });
}
if ($mode === 'unexpected-classpath') {
    $archive = new ZipArchive();
    if ($archive->open($oldArchive) !== true) throw new RuntimeException('An actual earlier Firebase archive is required');
    $source = $archive->getFromName($archive->getNameIndex(0) . 'src/JWT.php');
    $archive->close();
    if (!is_string($source)) throw new RuntimeException('Incompatible source not found');
    $testTemp = getenv('TMPDIR') ?: sys_get_temp_dir();
    if (!is_dir($testTemp) || !is_writable($testTemp)) $testTemp = '/private/tmp';
    $temporary = tempnam($testTemp, 'sand-license-old-jwt-');
    if ($temporary === false) throw new RuntimeException('Cannot create fixture');
    file_put_contents($temporary, $source);
    require $temporary;
    register_shutdown_function(static function () use ($temporary): void { unlink($temporary); });
    require $host;
}
try {
    require $plugin . '/app/functions.php';
} catch (RuntimeException $exception) {
    if (in_array($mode, $rejectionModes, true) && str_contains($exception->getMessage(), 'SAND_LICENSE_DEPENDENCY_INCOMPATIBLE')) {
        echo 'PASS ' . $mode . ": foreign/mixed existing JWT source rejected\n";
        exit(0);
    }
    if (in_array($mode, ['no-host', 'plugin-first', 'metadata-missing'], true) && str_contains($exception->getMessage(), 'SAND_LICENSE_DEPENDENCY_MISSING')) {
        if ($mode === 'plugin-first') {
            require $host;
            \plugin\SandLicense\app\Bootstrap::load();
        } else {
            echo 'PASS ' . $mode . ": missing existing host JWT stack explicitly rejected\n";
            exit(0);
        }
    } else {
        throw $exception;
    }
}
if (in_array($mode, [...$rejectionModes, 'no-host', 'metadata-missing'], true)) {
    throw new RuntimeException('Missing/foreign host stack accepted');
}
foreach (['JWT', 'Key', 'JWK'] as $name) {
    $actual = (new ReflectionClass('Firebase\\JWT\\' . $name))->getFileName();
    $expectedRoot = realpath(\Composer\InstalledVersions::getInstallPath('firebase/php-jwt'));
    if (!is_string($actual) || !is_string($expectedRoot) || !str_starts_with($actual, $expectedRoot . '/')) {
        throw new RuntimeException('JWT source is not the existing host stack: ' . (string) $actual);
    }
}
$actual = (new ReflectionClass(\Firebase\JWT\JWT::class))->getFileName();
$savedLeeway = \Firebase\JWT\JWT::$leeway;
$savedTimestamp = \Firebase\JWT\JWT::$timestamp;
\Firebase\JWT\JWT::$leeway = 60; // Match a prior Tinywan login verification.
register_shutdown_function(static function () use ($savedLeeway, $savedTimestamp): void {
    \Firebase\JWT\JWT::$leeway = $savedLeeway;
    \Firebase\JWT\JWT::$timestamp = $savedTimestamp;
});
$pair = sodium_crypto_sign_keypair();
$private = base64_encode(sodium_crypto_sign_secretkey($pair));
$public = base64_encode(sodium_crypto_sign_publickey($pair));
$token = new \app\SandLicense\Security\LeaseToken('package-test', $private, ['package-test' => $public], 'https://license.test');
$tokenSource = (new ReflectionClass($token))->getFileName();
if (!is_string($tokenSource) || realpath($tokenSource) !== realpath(dirname($plugin, 2) . '/app/SandLicense/Security/LeaseToken.php')) throw new RuntimeException('License kernel did not load from the tested package');
if (!class_exists(\plugin\SandLicense\app\admin\controller\ManagementController::class)) throw new RuntimeException('Package management namespace cannot load');
$now = time();
$claims = [
    'aud' => 'package-audience', 'sub' => '1', 'entitlement_id' => '1',
    'activation_id' => '2', 'product_code' => 'test', 'plan_revision' => 1,
    'features' => ['export' => true], 'cnf' => ['jkt' => str_repeat('a', 43)], 'jti' => 'package-verification',
];
$signed = $token->issue($claims, $now + 600, $now);
$verified = $token->verify($signed, 'package-audience', 'test', '1', '2', str_repeat('a', 43), $now);
if ($verified['exp'] !== $now + 600 || $verified['features']['export'] !== true) throw new RuntimeException('Real EdDSA roundtrip failed');
if (\Firebase\JWT\JWT::$leeway !== 60 || \Firebase\JWT\JWT::$timestamp !== $savedTimestamp) throw new RuntimeException('License changed host JWT login settings');
echo 'PASS ' . $mode . ': real EdDSA roundtrip; Tinywan=' . \Composer\InstalledVersions::getPrettyVersion('tinywan/jwt') . '; Firebase=' . \Composer\InstalledVersions::getPrettyVersion('firebase/php-jwt') . '; JWT source=' . $actual . "\n";
