<?php

namespace App\Services\Import;

use App\Models\Asset;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\GoogleSheetConnection;
use App\Models\ImportBatch;
use App\Models\ImportMapping;
use App\Models\ImportReviewCandidate;
use App\Models\ImportRowResult;
use App\Models\SaasAssignment;
use App\Models\SaasService;
use App\Models\SecurityControl;
use App\Services\ContractAmountValidator;
use App\Services\ImportBatchExecutionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImportMappingAnalysisService
{
    public function __construct(
        private CsvImportSource $csvImportSource,
        private XlsxImportSource $xlsxImportSource,
        private GoogleSheetsImportSource $googleSheetsImportSource,
        private ImportBatchExecutionService $executionService,
        private ContractAmountValidator $contractAmountValidator,
        private ImportNormalizationSuggestionService $normalizationService,
    ) {}

    public function analyze(int $importBatchId, int $mappingId): void
    {
        $importBatch = ImportBatch::query()->whereKey($importBatchId)->where('status', 'analyzing')->first();
        $mapping = ImportMapping::query()
            ->whereKey($mappingId)
            ->where('import_batch_id', $importBatchId)
            ->where('status', 'analyzing')
            ->first();

        if ($importBatch === null || $mapping === null || (string) $importBatch->mapping_id !== (string) $mapping->id) {
            return;
        }

        $columnMap = $mapping->column_map ?? [];
        $rowResultStream = tmpfile();
        if (! is_resource($rowResultStream)) {
            throw new RuntimeException('マッピング分析の一時領域を準備できません。');
        }
        $candidateStream = tmpfile();
        if (! is_resource($candidateStream)) {
            fclose($rowResultStream);
            throw new RuntimeException('マッピング分析の一時領域を準備できません。');
        }

        $chunkSize = max(1, (int) config('opsdeck.import.preview_insert_chunk_size'));
        $counts = ['new' => 0, 'updated' => 0, 'confirmation' => 0, 'error' => 0];
        $sourceRows = [];
        $seenKeys = [];
        $firstRowsByKey = [];
        $existingCandidateHashes = $this->existingDuplicateCandidateHashes($importBatch);
        $timestamp = now()->toDateTimeString();

        try {
            $processRows = function () use (&$sourceRows, &$counts, &$seenKeys, &$firstRowsByKey, &$existingCandidateHashes, $importBatch, $mapping, $columnMap, $rowResultStream, $candidateStream, $timestamp): void {
                if ($sourceRows === []) {
                    return;
                }

                $sourceRowChunk = $sourceRows;
                $sourceRows = [];
                $mappedRows = $this->mapRows($sourceRowChunk, $columnMap, $mapping->target_type, $mapping->normalization_rules ?? []);
                $existingTargetHashes = $this->existingImportTargetHashes($importBatch, $mapping->target_type, $mappedRows);
                $knownRelationshipReferences = $this->knownRelationshipReferencesForRows($importBatch, $mapping->target_type, $columnMap, $mappedRows);
                $rowResultIds = $this->rowResultIdsForRows($importBatch, $mappedRows);
                [$chunkCounts, $rowResultUpdates] = $this->classifyRows($importBatch, $mapping->target_type, $mappedRows, $existingTargetHashes, $knownRelationshipReferences, $rowResultIds, $seenKeys, $timestamp);
                foreach ($chunkCounts as $countType => $count) {
                    $counts[$countType] += $count;
                }
                $this->stageRows($rowResultStream, $rowResultUpdates);

                $candidateRecords = $this->duplicateCandidateRecords($importBatch, $mapping->target_type, $columnMap, $mapping->normalization_rules ?? [], $sourceRowChunk, $existingCandidateHashes, $firstRowsByKey, $timestamp);
                $this->stageRows($candidateStream, $candidateRecords);
            };

            $this->forEachSourceRow($importBatch, $mapping->target_type, function (array $row, array $headers) use (&$sourceRows, $processRows, $chunkSize): void {
                $sourceValues = ['__source_row_number' => (int) ($row['source_row_number'] ?? 0)];
                foreach ($headers as $columnIndex => $header) {
                    $sourceValues[$header] = (string) ($row['values'][$columnIndex] ?? '');
                }
                $sourceRows[] = $sourceValues;

                if (count($sourceRows) >= $chunkSize) {
                    $processRows();
                }
            });
            $processRows();

            DB::transaction(function () use ($importBatch, $mapping, $counts, $rowResultStream, $candidateStream, $chunkSize): void {
                $lockedBatch = ImportBatch::query()->whereKey($importBatch->id)->lockForUpdate()->first();
                $lockedMapping = ImportMapping::query()->whereKey($mapping->id)->lockForUpdate()->first();
                if ($lockedBatch === null
                    || $lockedBatch->status !== 'analyzing'
                    || (string) $lockedBatch->mapping_id !== (string) $mapping->id
                    || $lockedMapping === null
                    || $lockedMapping->import_batch_id !== $lockedBatch->id
                    || $lockedMapping->status !== 'analyzing') {
                    return;
                }

                $candidateChunk = [];
                foreach ($this->stagedRows($candidateStream) as $candidate) {
                    $candidateChunk[] = $candidate;
                    if (count($candidateChunk) === $chunkSize) {
                        ImportReviewCandidate::query()->insert($candidateChunk);
                        $candidateChunk = [];
                    }
                }
                if ($candidateChunk !== []) {
                    ImportReviewCandidate::query()->insert($candidateChunk);
                }

                $rowResultChunk = [];
                foreach ($this->stagedRows($rowResultStream) as $rowResult) {
                    $rowResultChunk[] = $rowResult;
                    if (count($rowResultChunk) === $chunkSize) {
                        ImportRowResult::query()->upsert($rowResultChunk, ['id'], ['target_type', 'action', 'after_json', 'errors_json', 'updated_at']);
                        $rowResultChunk = [];
                    }
                }
                if ($rowResultChunk !== []) {
                    ImportRowResult::query()->upsert($rowResultChunk, ['id'], ['target_type', 'action', 'after_json', 'errors_json', 'updated_at']);
                }

                $lockedMapping->update(['status' => 'ready']);
                $lockedBatch->update([
                    'preview_new_rows' => $counts['new'],
                    'preview_updated_rows' => $counts['updated'],
                    'preview_confirmation_rows' => $counts['confirmation'],
                    'preview_error_rows' => $counts['error'],
                    'status' => 'ready',
                    'completed_at' => now(),
                ]);
            });
        } finally {
            fclose($rowResultStream);
            fclose($candidateStream);
        }
    }

    /**
     * @param  callable(array{source_row_number: int, values: list<string>}, list<string>): void  $onRow
     */
    private function forEachSourceRow(ImportBatch $importBatch, string $targetType, callable $onRow): void
    {
        if ($importBatch->source_type === 'xlsx' && $importBatch->source_file_path !== null && $importBatch->selected_sheet_name !== null) {
            $this->xlsxImportSource->parseEach(
                Storage::disk(config('opsdeck.import.disk'))->path($importBatch->source_file_path),
                $importBatch->selected_sheet_name,
                $onRow,
                headerRowNumber: (int) $importBatch->header_row_number,
                dataStartRowNumber: $importBatch->resolvedDataStartRowNumber(),
                headerEndRowNumber: $importBatch->resolvedHeaderEndRowNumber(),
            );

            return;
        }

        if ($importBatch->source_type === 'google_sheets'
            && $importBatch->source_external_id !== null
            && $importBatch->selected_sheet_name !== null) {
            $connection = GoogleSheetConnection::query()->where('organization_id', $importBatch->organization_id)->first();
            if ($connection !== null) {
                $this->googleSheetsImportSource->parseEach(
                    $connection,
                    $importBatch->source_external_id,
                    $importBatch->selected_sheet_name,
                    $onRow,
                    $targetType === 'contracts',
                    headerRowNumber: (int) $importBatch->header_row_number,
                    dataStartRowNumber: $importBatch->resolvedDataStartRowNumber(),
                    headerEndRowNumber: $importBatch->resolvedHeaderEndRowNumber(),
                );
            }

            return;
        }

        if ($importBatch->source_type === 'csv' && $importBatch->source_file_path !== null) {
            $disk = Storage::disk(config('opsdeck.import.disk'));
            if ($disk->exists($importBatch->source_file_path)) {
                $sourceStream = $disk->readStream($importBatch->source_file_path);
                if (! is_resource($sourceStream)) {
                    throw new RuntimeException('CSVを読み込めません。');
                }

                try {
                    $this->csvImportSource->parseEachStream($sourceStream, $onRow, headerRowNumber: (int) $importBatch->header_row_number, dataStartRowNumber: $importBatch->resolvedDataStartRowNumber(), headerEndRowNumber: $importBatch->resolvedHeaderEndRowNumber());
                } finally {
                    fclose($sourceStream);
                }
            }
        }
    }

    /**
     * @param  resource  $stagingStream
     * @param  list<array<string, mixed>>  $rows
     */
    private function stageRows($stagingStream, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $line = Crypt::encryptString(json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)).PHP_EOL;
        if (fwrite($stagingStream, $line) !== strlen($line)) {
            throw new RuntimeException('マッピング分析の一時データを書き込めません。');
        }
    }

    /**
     * @param  resource  $stagingStream
     * @return \Generator<int, array<string, mixed>>
     */
    private function stagedRows($stagingStream): \Generator
    {
        rewind($stagingStream);
        while (($line = fgets($stagingStream)) !== false) {
            $rows = json_decode(Crypt::decryptString(trim($line)), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($rows)) {
                throw new RuntimeException('マッピング分析の一時データを読み取れません。');
            }
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw new RuntimeException('マッピング分析の一時データを読み取れません。');
                }

                yield $row;
            }
        }
        if (! feof($stagingStream)) {
            throw new RuntimeException('マッピング分析の一時データを読み取れません。');
        }
    }

    /**
     * @param  list<array{source_row_number: int, values: array<string, string>, duplicate_key: ?string}>  $mappedRows
     * @return Collection<int, ImportRowResult>
     */
    private function rowResultIdsForRows(ImportBatch $importBatch, array $mappedRows): Collection
    {
        $sourceRowNumbers = array_values(array_unique(array_column($mappedRows, 'source_row_number')));
        if ($sourceRowNumbers === []) {
            return collect();
        }

        return $importBatch->rowResults()
            ->select(['id', 'source_row_number'])
            ->whereIn('source_row_number', $sourceRowNumbers)
            ->orderByDesc('id')
            ->get()
            ->unique('source_row_number')
            ->keyBy('source_row_number');
    }

    /**
     * @param  array<string, string|null>  $columnMap
     * @param  list<array{source_row_number: int, values: array<string, string>, duplicate_key: ?string}>  $mappedRows
     * @return array{department_names: array<string, true>, service_names: array<string, true>, employee_emails: array<string, true>}
     */
    private function knownRelationshipReferencesForRows(ImportBatch $importBatch, string $targetType, array $columnMap, array $mappedRows): array
    {
        $references = ['department_names' => [], 'service_names' => [], 'employee_emails' => []];
        $referenceFields = match ($targetType) {
            'employees' => ['department_name' => 'department_names'],
            'contracts', 'saas_assignments' => ['service_name' => 'service_names', 'employee_email' => 'employee_emails'],
            'security_controls' => ['owner_email' => 'employee_emails'],
            default => [],
        };

        foreach ($referenceFields as $field => $referenceType) {
            if (! in_array($field, $columnMap, true)) {
                continue;
            }

            $values = collect($mappedRows)
                ->map(static fn (array $row): string => mb_strtolower(trim((string) ($row['values'][$field] ?? ''))))
                ->filter()
                ->unique()
                ->values();
            if ($values->isEmpty()) {
                continue;
            }

            if ($referenceType === 'department_names') {
                $references[$referenceType] = Department::query()
                    ->where('organization_id', $importBatch->organization_id)
                    ->whereIn(DB::raw('LOWER(name)'), $values)
                    ->pluck('name')
                    ->mapWithKeys(static fn (string $name): array => [mb_strtolower(trim($name)) => true])
                    ->all();
            } elseif ($referenceType === 'service_names') {
                $references[$referenceType] = SaasService::query()
                    ->where('organization_id', $importBatch->organization_id)
                    ->whereIn(DB::raw('LOWER(name)'), $values)
                    ->pluck('name')
                    ->mapWithKeys(static fn (string $name): array => [mb_strtolower(trim($name)) => true])
                    ->all();
            } else {
                $employeeEmailMatches = Employee::query()
                    ->where('organization_id', $importBatch->organization_id)
                    ->whereIn(DB::raw('LOWER(email)'), $values)
                    ->get(['email']);
                foreach ($employeeEmailMatches->groupBy(static fn (Employee $employee): string => mb_strtolower(trim((string) $employee->email))) as $email => $matches) {
                    if ($matches->count() === 1) {
                        $references[$referenceType][$email] = true;
                    }
                }
            }
        }

        return $references;
    }

    /**
     * @param  list<array<string, string|int>>  $rows
     * @param  array<string, string|null>  $columnMap
     * @return list<array{source_row_number: int, values: array<string, string>, duplicate_key: ?string}>
     */
    private function mapRows(array $rows, array $columnMap, string $targetType, array $normalizationRules): array
    {
        $mappedRows = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($columnMap as $sourceHeader => $targetField) {
                if (is_string($targetField) && $targetField !== '' && isset($row[$sourceHeader])) {
                    $values[$targetField] = trim((string) $row[$sourceHeader]);
                }
            }
            $values = $this->normalizationService->apply($targetType, $values, $normalizationRules);
            $values = $this->executionService->normalizeImportValues($targetType, $values);
            $mappedRows[] = [
                'source_row_number' => (int) ($row['__source_row_number'] ?? 0),
                'values' => $values,
                'duplicate_key' => $this->executionService->duplicateKey($targetType, $values),
            ];
        }

        return $mappedRows;
    }

    /** @return array<string, true> */
    private function existingDuplicateCandidateHashes(ImportBatch $importBatch): array
    {
        $hashes = [];
        foreach ($importBatch->reviewCandidates()->where('candidate_type', 'duplicate')->get(['details_json']) as $candidate) {
            $keyHash = $candidate->details_json['key_hash'] ?? null;
            if (is_string($keyHash) && $keyHash !== '') {
                $hashes[$keyHash] = true;
            }
        }

        return $hashes;
    }

    /**
     * @param  array<string, string|null>  $columnMap
     * @param  list<array<string, string|int>>  $rows
     * @param  array<string, true>  $existingHashes
     * @param  array<string, int>  $firstRows
     * @return list<array<string, mixed>>
     */
    private function duplicateCandidateRecords(ImportBatch $importBatch, string $targetType, array $columnMap, array $normalizationRules, array $rows, array &$existingHashes, array &$firstRows, string $timestamp): array
    {
        $candidateRecords = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($columnMap as $sourceHeader => $targetField) {
                if (is_string($targetField) && $targetField !== '' && isset($row[$sourceHeader])) {
                    $values[$targetField] = trim((string) $row[$sourceHeader]);
                }
            }
            $values = $this->normalizationService->apply($targetType, $values, $normalizationRules);
            $key = $this->executionService->duplicateKey($targetType, $this->executionService->normalizeImportValues($targetType, $values));
            $sourceRow = (int) ($row['__source_row_number'] ?? 0);
            if ($key === null || $sourceRow < 1) {
                continue;
            }
            if (! isset($firstRows[$key])) {
                $firstRows[$key] = $sourceRow;

                continue;
            }

            $keyHash = hash('sha256', $key);
            if (! isset($existingHashes[$keyHash])) {
                $candidateRecords[] = [
                    'import_batch_id' => $importBatch->id,
                    'candidate_type' => 'duplicate',
                    'source_row_numbers_json' => json_encode([$firstRows[$key], $sourceRow], JSON_THROW_ON_ERROR),
                    'confidence' => 0.94,
                    'details_json' => json_encode(['target_type' => $targetType, 'key_hash' => $keyHash], JSON_THROW_ON_ERROR),
                    'status' => 'pending',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
                $existingHashes[$keyHash] = true;
            }
        }

        return $candidateRecords;
    }

    /**
     * @param  list<array{source_row_number: int, values: array<string, string>, duplicate_key: ?string}>  $mappedRows
     * @param  array<string, true>  $existingTargetHashes
     * @return array{array{new: int, updated: int, confirmation: int, error: int}, list<array<string, mixed>>}
     */
    /** @param Collection<int, ImportRowResult> $rowResultIds */
    private function classifyRows(ImportBatch $importBatch, string $targetType, array $mappedRows, array $existingTargetHashes, array $knownRelationshipReferences, Collection $rowResultIds, array &$seen, string $timestamp): array
    {
        $counts = ['new' => 0, 'updated' => 0, 'confirmation' => 0, 'error' => 0];
        $rowResultUpdates = [];
        foreach ($mappedRows as $mappedRow) {
            $values = $mappedRow['values'];
            $key = $mappedRow['duplicate_key'];
            $sourceRowNumber = $mappedRow['source_row_number'];
            $action = 'new_candidate';
            $errors = null;
            if ($this->previewRowHasError($targetType, $values, $knownRelationshipReferences)) {
                $counts['error']++;
                $action = 'error';
                $errors = ['row' => $this->hasInvalidImportDateValues($targetType, $values)
                    ? 'date fields are invalid or out of order'
                    : '入力値または関連データを確認してください'];
            } elseif ($key !== null && isset($seen[$key])) {
                $counts['confirmation']++;
                $action = 'confirmation_required';
            } else {
                if ($key !== null) {
                    $seen[$key] = true;
                }
                $targetHash = $this->importTargetIdentityHash($targetType, $values);
                if ($targetHash !== null && isset($existingTargetHashes[$targetHash])) {
                    $counts['updated']++;
                    $action = 'update_candidate';
                } else {
                    $counts['new']++;
                }
            }

            $rowResultId = $rowResultIds->get($sourceRowNumber)?->id;
            if ($sourceRowNumber > 0 && $rowResultId !== null) {
                $rowResultUpdates[] = [
                    'id' => $rowResultId,
                    'import_batch_id' => $importBatch->id,
                    'source_row_number' => $sourceRowNumber,
                    'target_type' => $targetType,
                    'action' => $action,
                    'after_json' => json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'errors_json' => $errors === null ? null : json_encode($errors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'updated_at' => $timestamp,
                ];
            }
        }

        return [$counts, $rowResultUpdates];
    }

    /** @param list<array{source_row_number: int, values: array<string, string>, duplicate_key: ?string}> $mappedRows
     * @return array<string, true>
     */
    private function existingImportTargetHashes(ImportBatch $importBatch, string $targetType, array $mappedRows): array
    {
        if ($targetType === 'saas_assignments') {
            return $this->existingSaasAssignmentHashes($importBatch, $mappedRows);
        }

        $identities = [];
        foreach ($mappedRows as $mappedRow) {
            $identity = $this->importTargetIdentity($targetType, $mappedRow['values']);
            if ($identity !== null) {
                $identities[$this->importTargetIdentityHash($targetType, $mappedRow['values'])] = $identity;
            }
        }
        if ($identities === []) {
            return [];
        }

        $existingHashes = [];
        $chunkSize = max(1, (int) config('opsdeck.import.preview_insert_chunk_size'));
        foreach (array_chunk($identities, $chunkSize, true) as $identityChunk) {
            if ($targetType === 'contracts') {
                $records = Contract::query()->where('organization_id', $importBatch->organization_id)
                    ->where(function (Builder $query) use ($identityChunk): void {
                        foreach ($identityChunk as [$vendorName, $contractName]) {
                            $query->orWhere(fn (Builder $pairQuery) => $pairQuery->where('vendor_name', $vendorName)->where('contract_name', $contractName));
                        }
                    })->get(['vendor_name', 'contract_name']);
            } else {
                $column = match ($targetType) {
                    'employees' => 'email',
                    'departments', 'saas_services' => 'name',
                    'assets' => 'asset_number',
                    'security_controls' => 'control_code',
                    default => null,
                };
                if ($column === null) {
                    continue;
                }
                $identifiers = array_map(static fn (array $identity): string => $identity[0], array_values($identityChunk));
                $records = match ($targetType) {
                    'employees' => Employee::query()->where('organization_id', $importBatch->organization_id)->whereIn(DB::raw('LOWER(email)'), $identifiers)->get([$column]),
                    'departments' => Department::query()->where('organization_id', $importBatch->organization_id)->whereIn($column, $identifiers)->get([$column]),
                    'assets' => Asset::query()->where('organization_id', $importBatch->organization_id)->whereIn($column, $identifiers)->get([$column]),
                    'saas_services' => SaasService::query()->where('organization_id', $importBatch->organization_id)->whereIn($column, $identifiers)->get([$column]),
                    'security_controls' => SecurityControl::query()->where('organization_id', $importBatch->organization_id)->whereIn($column, $identifiers)->get([$column]),
                    default => collect(),
                };
            }
            foreach ($records as $record) {
                $values = $targetType === 'contracts' ? ['vendor_name' => $record->vendor_name, 'contract_name' => $record->contract_name] : [$column => $record->{$column}];
                if ($targetType === 'employees') {
                    $values['email'] = strtolower((string) $record->email);
                }
                $hash = $this->importTargetIdentityHash($targetType, $values);
                if ($hash !== null) {
                    $existingHashes[$hash] = true;
                }
            }
        }

        return $existingHashes;
    }

    /**
     * @param  list<array{source_row_number: int, values: array<string, string>, duplicate_key: ?string}>  $mappedRows
     * @return array<string, true>
     */
    private function existingSaasAssignmentHashes(ImportBatch $importBatch, array $mappedRows): array
    {
        $serviceNames = collect($mappedRows)
            ->map(static fn (array $row): string => mb_strtolower(trim((string) ($row['values']['service_name'] ?? ''))))
            ->filter()
            ->unique()
            ->values();
        if ($serviceNames->isEmpty()) {
            return [];
        }

        $employeeEmails = collect($mappedRows)
            ->map(static fn (array $row): string => strtolower(trim((string) ($row['values']['employee_email'] ?? ''))))
            ->filter()
            ->unique()
            ->values();
        $services = SaasService::query()
            ->where('organization_id', $importBatch->organization_id)
            ->whereIn(DB::raw('LOWER(name)'), $serviceNames)
            ->get(['id', 'name'])
            ->keyBy(static fn (SaasService $service): string => mb_strtolower(trim($service->name)));
        $employees = $employeeEmails->isEmpty()
            ? collect()
            : Employee::query()
                ->where('organization_id', $importBatch->organization_id)
                ->whereIn(DB::raw('LOWER(email)'), $employeeEmails)
                ->get(['id', 'email'])
                ->keyBy(static fn (Employee $employee): string => strtolower(trim((string) $employee->email)));
        $tuples = [];
        foreach ($mappedRows as $mappedRow) {
            $values = $mappedRow['values'];
            $service = $services->get(mb_strtolower(trim((string) ($values['service_name'] ?? ''))));
            $employeeEmail = strtolower(trim((string) ($values['employee_email'] ?? '')));
            $employee = $employeeEmail === '' ? null : $employees->get($employeeEmail);
            if ($service === null || ($employeeEmail !== '' && $employee === null)) {
                continue;
            }

            $tuple = [
                'service_id' => $service->id,
                'employee_id' => $employee?->id,
                'account_identifier' => trim((string) ($values['account_identifier'] ?? '')),
            ];
            $tuples[json_encode($tuple, JSON_THROW_ON_ERROR)] = $tuple;
        }
        if ($tuples === []) {
            return [];
        }

        $serviceNamesById = $services->mapWithKeys(static fn (SaasService $service): array => [(int) $service->id => mb_strtolower(trim($service->name))]);
        $employeeEmailsById = $employees->mapWithKeys(static fn (Employee $employee): array => [(int) $employee->id => strtolower(trim((string) $employee->email))]);
        $existingHashes = [];
        foreach (array_chunk(array_values($tuples), 200) as $tupleChunk) {
            $assignments = SaasAssignment::query()
                ->where('organization_id', $importBatch->organization_id)
                ->where(function (Builder $query) use ($tupleChunk): void {
                    foreach ($tupleChunk as $tuple) {
                        $query->orWhere(function (Builder $tupleQuery) use ($tuple): void {
                            $tupleQuery->where('saas_service_id', $tuple['service_id']);
                            if ($tuple['employee_id'] === null) {
                                $tupleQuery->whereNull('employee_id');
                            } else {
                                $tupleQuery->where('employee_id', $tuple['employee_id']);
                            }

                            if ($tuple['account_identifier'] === '') {
                                $tupleQuery->where(static fn (Builder $accountQuery): Builder => $accountQuery->whereNull('account_identifier')->orWhere('account_identifier', ''));
                            } else {
                                $tupleQuery->where('account_identifier', $tuple['account_identifier']);
                            }
                        });
                    }
                })
                ->get(['saas_service_id', 'employee_id', 'account_identifier']);

            foreach ($assignments as $assignment) {
                $identity = [
                    'service_name' => $serviceNamesById->get((int) $assignment->saas_service_id, ''),
                    'employee_email' => $assignment->employee_id === null ? '' : $employeeEmailsById->get((int) $assignment->employee_id, ''),
                    'account_identifier' => (string) ($assignment->account_identifier ?? ''),
                ];
                $hash = $this->importTargetIdentityHash('saas_assignments', $identity);
                if ($hash !== null) {
                    $existingHashes[$hash] = true;
                }
            }
        }

        return $existingHashes;
    }

    /** @param array<string, string> $values
     * @return list<string>|null
     */
    private function importTargetIdentity(string $targetType, array $values): ?array
    {
        return match ($targetType) {
            'employees' => [(string) ($values['email'] ?? '')],
            'departments', 'saas_services' => [(string) ($values['name'] ?? '')],
            'assets' => [(string) ($values['asset_number'] ?? '')],
            'saas_assignments' => [mb_strtolower(trim((string) ($values['service_name'] ?? ''))), strtolower(trim((string) ($values['employee_email'] ?? ''))), trim((string) ($values['account_identifier'] ?? ''))],
            'contracts' => [(string) ($values['vendor_name'] ?? ''), (string) ($values['contract_name'] ?? '')],
            'security_controls' => [(string) ($values['control_code'] ?? '')],
            default => null,
        };
    }

    /** @param array<string, string> $values */
    private function importTargetIdentityHash(string $targetType, array $values): ?string
    {
        $identity = $this->importTargetIdentity($targetType, $values);
        $connection = DB::connection();
        $collation = config('database.connections.'.$connection->getName().'.collation');
        if ($identity !== null && $connection->getDriverName() === 'mysql' && is_string($collation) && str_ends_with(mb_strtolower($collation), '_ci')) {
            $identity = array_map(static fn (string $value): string => mb_strtolower($value), $identity);
        }

        return $identity === null ? null : hash('sha256', json_encode([$targetType, $identity], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, string> $values */
    private function previewRowHasError(string $targetType, array $values, array $knownRelationshipReferences = []): bool
    {
        if ($this->hasInvalidImportDateValues($targetType, $values)) {
            return true;
        }

        return match ($targetType) {
            'employees' => ($values['name'] ?? '') === ''
                || filter_var($values['email'] ?? '', FILTER_VALIDATE_EMAIL) === false
                || (($values['department_name'] ?? '') !== '' && ! isset($knownRelationshipReferences['department_names'][mb_strtolower(trim($values['department_name']))])),
            'departments' => ($values['name'] ?? '') === '',
            'assets' => ($values['asset_number'] ?? '') === '' || ! in_array($values['asset_type'] ?? '', config('opsdeck.asset_types'), true),
            'saas_services' => ($values['name'] ?? '') === '' || ! in_array($values['status'] ?? '', config('opsdeck.saas_service_statuses'), true),
            'contracts' => $this->contractPreviewHasError($values)
                || (($values['service_name'] ?? '') !== '' && ! isset($knownRelationshipReferences['service_names'][mb_strtolower(trim($values['service_name']))]))
                || (($values['employee_email'] ?? '') !== '' && ! isset($knownRelationshipReferences['employee_emails'][mb_strtolower(trim($values['employee_email']))])),
            'saas_assignments' => ($values['service_name'] ?? '') === ''
                || ! isset($knownRelationshipReferences['service_names'][mb_strtolower(trim($values['service_name']))])
                || (($values['employee_email'] ?? '') !== '' && ! isset($knownRelationshipReferences['employee_emails'][mb_strtolower(trim($values['employee_email']))]))
                || ! in_array($values['mfa_status'] ?? '', config('opsdeck.saas_assignment_mfa_statuses'), true),
            'security_controls' => $this->securityControlPreviewHasError($values)
                || (($values['owner_email'] ?? '') !== '' && ! isset($knownRelationshipReferences['employee_emails'][mb_strtolower(trim($values['owner_email']))])),
            default => false,
        };
    }

    /** @param array<string, string> $values */
    private function securityControlPreviewHasError(array $values): bool
    {
        return ($values['control_code'] ?? '') === ''
            || ($values['title'] ?? '') === ''
            || ($values['category'] ?? '') === ''
            || ($values['description'] ?? '') === ''
            || ! in_array($values['status'] ?? '', config('opsdeck.security_control_statuses'), true);
    }

    /** @param array<string, mixed> $values */
    private function contractPreviewHasError(array $values): bool
    {
        return ($values['vendor_name'] ?? '') === ''
            || ($values['contract_name'] ?? '') === ''
            || ! in_array($values['billing_cycle'] ?? '', config('opsdeck.contract_billing_cycles'), true)
            || ! in_array($values['status'] ?? '', config('opsdeck.contract_statuses'), true)
            || ! $this->contractAmountValidator->isValid($values['amount'] ?? null);
    }

    /** @param array<string, string> $values */
    private function hasInvalidImportDateValues(string $targetType, array $values): bool
    {
        foreach (config('opsdeck.import_date_fields.'.$targetType, []) as $dateField) {
            $value = $values[$dateField] ?? '';
            if ($value !== '' && (! is_string($value) || is_numeric($value) || ! $this->isValidDate($value))) {
                return true;
            }
        }
        foreach (config('opsdeck.import_date_ranges.'.$targetType, []) as [$startField, $endField]) {
            $start = $values[$startField] ?? '';
            $end = $values[$endField] ?? '';
            if ($start !== '' && $end !== '' && $end < $start) {
                return true;
            }
        }

        return false;
    }

    private function isValidDate(string $value): bool
    {
        try {
            CarbonImmutable::parse($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
