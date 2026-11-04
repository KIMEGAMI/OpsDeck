<?php

declare(strict_types=1);
use Illuminate\Support\Facades\DB;

$requestedDatabaseConnection = strtolower(trim((string) getenv('DB_CONNECTION')));
$testDatabaseDriver = strtolower(trim((string) getenv('OPSDECK_TEST_DATABASE_DRIVER')));
$databaseConnection = 'sqlite';

if ($testDatabaseDriver !== '') {
    $isSupportedTestDatabaseDriver = in_array($testDatabaseDriver, ['mysql', 'mariadb'], true);
    $isDedicatedGitHubActionsDatabase = getenv('GITHUB_ACTIONS') === 'true'
        && $isSupportedTestDatabaseDriver
        && $requestedDatabaseConnection === $testDatabaseDriver
        && getenv('DB_HOST') === '127.0.0.1'
        && getenv('OPSDECK_TEST_DATABASE_NAME') === 'opsdeck_test'
        && getenv('DB_USERNAME') === 'opsdeck_test'
        && getenv('DB_URL') === '';

    if (! $isDedicatedGitHubActionsDatabase) {
        throw new RuntimeException('External database tests require the dedicated GitHub Actions test database.');
    }

    $databaseConnection = $testDatabaseDriver;
}

$sqliteDatabasePath = $databaseConnection === 'sqlite'
    ? sys_get_temp_dir().DIRECTORY_SEPARATOR.'opsdeck-phpunit-'.bin2hex(random_bytes(16)).'.sqlite'
    : ':memory:';

if ($databaseConnection === 'sqlite' && ! touch($sqliteDatabasePath)) {
    throw new RuntimeException('Unable to initialize isolated SQLite test database.');
}

$testEnvironment = [
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',
    'BROADCAST_CONNECTION' => 'null',
    'CACHE_STORE' => 'array',
    'DB_CONNECTION' => $databaseConnection,
    'DB_URL' => '',
    'DB_SQLITE_DATABASE' => $sqliteDatabasePath,
    'MAIL_MAILER' => 'array',
    'MAIL_PASSWORD' => '',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false',
    'OPENAI_API_KEY' => '',
    'OPSDECK_AI_ENABLED' => 'true',
    'OPSDECK_AI_PROVIDER' => 'openai',
    'OPSDECK_RELEASE_GOOGLE_SHEETS' => 'true',
    'OPSDECK_RELEASE_IMPACT_REPORT' => 'true',
    'OPSDECK_RELEASE_REFERRALS' => 'true',
    'OPSDECK_RELEASE_STORAGE_ADDON' => 'true',
    'OPSDECK_STRIPE_ENVIRONMENT' => 'test',
    'OPSDECK_STRIPE_TEST_SECRET_KEY' => '',
    'OPSDECK_STRIPE_TEST_PRICE_PREMIUM' => '',
    'OPSDECK_STRIPE_TEST_PRICE_STORAGE_ADDON_GIB' => '',
    'OPSDECK_STRIPE_TEST_WEBHOOK_SECRET' => '',
    'OPSDECK_STRIPE_LIVE_SECRET_KEY' => '',
    'OPSDECK_STRIPE_LIVE_PRICE_PREMIUM' => '',
    'OPSDECK_STRIPE_LIVE_PRICE_STORAGE_ADDON_GIB' => '',
    'OPSDECK_STRIPE_LIVE_WEBHOOK_SECRET' => '',
];

foreach ($testEnvironment as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

if ($databaseConnection === 'sqlite') {
    foreach ([
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $sqliteDatabasePath,
        'DB_SQLITE_DATABASE' => $sqliteDatabasePath,
    ] as $name => $value) {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    register_shutdown_function(static function () use ($sqliteDatabasePath): void {
        try {
            DB::purge('sqlite');
        } catch (Throwable) {
            // Tests should still finish if the database facade was never initialized.
        }

        foreach ([$sqliteDatabasePath, $sqliteDatabasePath.'-wal', $sqliteDatabasePath.'-shm'] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    });
}

require dirname(__DIR__).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';
