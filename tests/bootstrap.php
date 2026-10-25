<?php

declare(strict_types=1);
use Illuminate\Support\Facades\DB;

$testEnvironment = [
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',
    'BROADCAST_CONNECTION' => 'null',
    'CACHE_STORE' => 'array',
    'DB_CONNECTION' => 'sqlite',
    'DB_URL' => '',
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
    'STRIPE_SECRET' => '',
    'STRIPE_WEBHOOK_SECRET' => '',
];

foreach ($testEnvironment as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

$databaseConnection = strtolower((string) getenv('DB_CONNECTION'));

if ($databaseConnection === 'sqlite') {
    $databasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'opsdeck-phpunit-'.bin2hex(random_bytes(16)).'.sqlite';
    if (! touch($databasePath)) {
        throw new RuntimeException('Unable to initialize isolated SQLite test database.');
    }

    foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $databasePath] as $name => $value) {
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    register_shutdown_function(static function () use ($databasePath): void {
        try {
            DB::purge('sqlite');
        } catch (Throwable) {
            // Tests should still finish if the database facade was never initialized.
        }

        foreach ([$databasePath, $databasePath.'-wal', $databasePath.'-shm'] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    });
}

require dirname(__DIR__).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';
