<?php

declare(strict_types=1);
use Illuminate\Support\Facades\DB;

$databaseConnection = strtolower((string) getenv('DB_CONNECTION'));

if ($databaseConnection === 'sqlite') {
    $databasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'opsdeck-phpunit-'.bin2hex(random_bytes(16)).'.sqlite';


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
