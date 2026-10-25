<?php

namespace App\Models;

use Database\Factories\ImportBatchFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    /** @use HasFactory<ImportBatchFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id', 'source_type', 'source_name', 'source_file_path', 'source_storage_disk', 'source_file_size_bytes', 'source_file_sha256', 'source_external_id', 'mapping_id',
        'headers_json', 'available_sheets_json', 'selected_sheet_name', 'header_row_number', 'header_end_row_number', 'data_start_row_number', 'status', 'total_rows', 'inserted_rows', 'updated_rows', 'skipped_rows', 'error_rows', 'preview_new_rows', 'preview_updated_rows', 'preview_confirmation_rows', 'preview_error_rows', 'executed_by',
        'started_at', 'completed_at', 'failure_message', 'errors_reviewed_at', 'errors_reviewed_by', 'diff_source_batch_id',
    ];

    protected function casts(): array
    {
        return ['headers_json' => 'array', 'available_sheets_json' => 'array', 'header_row_number' => 'integer', 'header_end_row_number' => 'integer', 'data_start_row_number' => 'integer', 'source_file_size_bytes' => 'integer', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'errors_reviewed_at' => 'datetime'];
    }

    public function sourceStorageDiskName(): string
    {
        return $this->source_storage_disk ?: (string) config('opsdeck.import.legacy_disk');
    }

    public function resolvedHeaderEndRowNumber(): int
    {
        return $this->header_end_row_number ?? $this->header_row_number;
    }

    public function resolvedDataStartRowNumber(): int
    {
        return $this->data_start_row_number ?? $this->resolvedHeaderEndRowNumber() + 1;
    }

    public function scopeStale(Builder $query, DateTimeInterface $cutoff): Builder
    {
        $currentTime = now();
        $staleAgeSeconds = max(0, $currentTime->getTimestamp() - $cutoff->getTimestamp());
        $executionAgeSeconds = max($staleAgeSeconds, max(1, (int) config('opsdeck.import.job_timeout_seconds')));
        $executionCutoff = $currentTime->copy()->subSeconds($executionAgeSeconds);

        return $query->where(function (Builder $query) use ($cutoff, $executionCutoff): void {
            $query->where(function (Builder $queuedQuery) use ($cutoff): void {
                $queuedQuery->whereIn('status', ['queued', 'previewing', 'analyzing', 'retrying', 'rolling_back'])
                    ->where(function (Builder $updatedQuery) use ($cutoff): void {
                        $updatedQuery->whereNull('updated_at')->orWhere('updated_at', '<=', $cutoff);
                    });
            })->orWhere(function (Builder $executingQuery) use ($executionCutoff): void {
                $executingQuery->where('status', 'executing')
                    ->where(function (Builder $startedQuery) use ($executionCutoff): void {
                        $startedQuery->whereNull('started_at')->orWhere('started_at', '<=', $executionCutoff);
                    });
            });
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    public function errorsReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'errors_reviewed_by');
    }

    public function diffSourceBatch(): BelongsTo
    {
        return $this->belongsTo(self::class, 'diff_source_batch_id');
    }

    public function rowResults(): HasMany
    {
        return $this->hasMany(ImportRowResult::class);
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(ImportMapping::class);
    }

    public function reviewCandidates(): HasMany
    {
        return $this->hasMany(ImportReviewCandidate::class);
    }
}
