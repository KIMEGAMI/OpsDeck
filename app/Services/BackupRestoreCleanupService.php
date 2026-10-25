<?php

namespace App\Services;

use Illuminate\Database\Schema\Builder;
use Throwable;

class BackupRestoreCleanupService
{
    public function clean(Builder $schema): bool
    {
        $cleanupSucceeded = true;

        try {
            $schema->dropAllViews();
        } catch (Throwable) {
            $cleanupSucceeded = false;
        }

        try {
            $schema->dropAllTables();
        } catch (Throwable) {
            $cleanupSucceeded = false;
        }

        return $cleanupSucceeded;
    }
}
