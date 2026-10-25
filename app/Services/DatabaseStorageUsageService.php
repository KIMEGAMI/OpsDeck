<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

class DatabaseStorageUsageService
{
    private const BYTES_PER_MEBIBYTE = 1048576;

    private const MEBIBYTES_PER_GIBIBYTE = 1024;

    /** @return array{status: string, table_count: int|null, size_bytes: int|null, size_display: string} */
    public function summary(): array
    {
        try {
            $connection = DB::connection();
            if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                return [
                    'status' => 'not_applicable',
                    'table_count' => null,
                    'size_bytes' => null,
                    'size_display' => '対象外（MySQL/MariaDBのみ計測）',
                ];
            }

            $usage = $connection->selectOne(
                'SELECT COUNT(*) AS table_count, COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS size_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ?',
                ['BASE TABLE'],
            );
        } catch (Throwable) {
            return [
                'status' => 'unavailable',
                'table_count' => null,
                'size_bytes' => null,
                'size_display' => '未計測',
            ];
        }

        if ($usage === null) {
            return [
                'status' => 'unavailable',
                'table_count' => null,
                'size_bytes' => null,
                'size_display' => '未計測',
            ];
        }

        $tableCount = max(0, (int) $usage->table_count);
        $sizeBytes = max(0, (int) $usage->size_bytes);

        return [
            'status' => 'measured',
            'table_count' => $tableCount,
            'size_bytes' => $sizeBytes,
            'size_display' => $this->formatBytes($sizeBytes),
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= self::BYTES_PER_MEBIBYTE * self::MEBIBYTES_PER_GIBIBYTE) {
            return number_format($bytes / (self::BYTES_PER_MEBIBYTE * self::MEBIBYTES_PER_GIBIBYTE), 1).' GiB';
        }

        if ($bytes >= self::BYTES_PER_MEBIBYTE) {
            return number_format($bytes / self::BYTES_PER_MEBIBYTE, 1).' MiB';
        }

        return number_format($bytes).' B';
    }
}
