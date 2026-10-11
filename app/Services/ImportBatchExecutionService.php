<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Evidence;
use App\Models\GoogleSheetConnection;
use App\Models\ImportBatch;
use App\Models\ImportMapping;
use App\Models\ImportReviewCandidate;
use App\Models\ImportRowResult;
use App\Models\Organization;
use App\Models\Questionnaire;
use App\Models\QuestionnaireAnswer;
use App\Models\SaasAssignment;
use App\Models\SaasService;
use App\Models\SecurityControl;
use App\Models\User;
use App\Services\Import\CsvImportSource;
use App\Services\Import\GoogleSheetsImportSource;
use App\Services\Import\ImportFailureMessage;
use App\Services\Import\XlsxImportSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ImportBatchExecutionService
{
    /**
     * @param  'ready'|'queued'  $expectedStatus
     */
    public function execute(int $importBatchId, int $userId, string $expectedStatus): void
    {
        $importBatch = ImportBatch::query()->find($importBatchId);
        $user = User::query()->find($userId);

        if ($importBatch === null || $user === null) {
            return;
        }

        if ($expectedStatus === 'queued' && $importBatch->status !== 'queued') {
            return;
        }

        Gate::forUser($user)->authorize('update', $importBatch);
        $organization = Organization::query()->whereKey($importBatch->organization_id)->firstOrFail();
        app(PlanFeatureService::class)->assertAvailable($organization, 'import_execution', 'Import実行はPremiumプランで利用できます。');

        $mapping = $importBatch->mappings()->where('status', 'ready')->latest()->firstOrFail();
        $this->assertNoSelectedTemplateSampleRows($importBatch, $mapping);
        abort_if($importBatch->reviewCandidates()->whereIn('status', ['pending', 'deferred'])->exists(), 422, '重複候補の判断が残っているため、Importを実行できません。');
        abort_unless(in_array($mapping->target_type, ['employees', 'departments', 'assets', 'saas_services', 'saas_assignments', 'contracts', 'security_controls', 'evidence', 'questionnaire_answers'], true), 422, 'このデータ種別はImport実行に対応していません。');
        $targetFeature = match ($mapping->target_type) {
            'security_controls' => 'security_controls',
            'evidence' => 'evidence',
            'questionnaire_answers' => 'questionnaires',
            default => null,
        };
        if ($targetFeature !== null) {
            app(PlanFeatureService::class)->assertAvailable($organization, $targetFeature, '選択したデータ種別は現在のプランで利用できません。');
        }

        $claimed = ImportBatch::query()
            ->whereKey($importBatch->id)
            ->where('organization_id', $organization->id)
            ->where('status', $expectedStatus)
            ->update(['status' => 'executing', 'started_at' => now(), 'executed_by' => $importBatch->executed_by ?? $user->id, 'updated_at' => now()]) === 1;

        if (! $claimed) {
            if ($expectedStatus === 'queued') {
                return;
            }

            abort(409, 'このImportはすでに実行中、または実行済みです。');
        }

        $importBatch->refresh();
        $sourceRowsStream = null;

        try {
            app(ImpactReportMetricsService::class)->capture($organization, $importBatch, 'import_before');
            [$headers, $sourceRowsStream] = $this->stageSourceRows($importBatch, $mapping->target_type);
            $headerIndexes = [];
            foreach ($headers as $index => $header) {
                $headerIndexes[(string) $header] = $index;
            }
            $columnMap = $mapping->column_map;
            $counts = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'error' => 0];
            $seenKeys = [];
            $acceptedCandidateHashes = $importBatch->reviewCandidates()
                ->where('candidate_type', 'duplicate')
                ->where('status', 'accepted')
                ->get()
                ->map(fn (ImportReviewCandidate $candidate): ?string => $candidate->details_json['key_hash'] ?? null)
                ->filter()
                ->flip()
                ->all();
            $excludedRows = $importBatch->rowResults()
                ->where('is_selected', false)
                ->pluck('source_row_number')
                ->mapWithKeys(fn ($row): array => [(int) $row => true])
                ->all();

            DB::transaction(function () use ($sourceRowsStream, $importBatch, $mapping, $columnMap, $headerIndexes, &$counts, &$seenKeys, $acceptedCandidateHashes, $excludedRows, $organization): void {
                Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();

                foreach ($this->stagedSourceRows($sourceRowsStream) as $sourceRow) {
                    $row = $sourceRow['values'];
                    $sourceRowNumber = $sourceRow['source_row_number'];
                    if (isset($excludedRows[$sourceRowNumber])) {
                        $counts['skipped']++;
                        ImportRowResult::query()->create(['import_batch_id' => $importBatch->id, 'source_row_number' => $sourceRowNumber, 'target_type' => $mapping->target_type, 'action' => 'skipped_selected', 'is_selected' => false]);

                        continue;
                    }

                    $values = [];
                    foreach ($columnMap as $sourceHeader => $targetField) {
                        if ($targetField !== null && $targetField !== '' && isset($headerIndexes[$sourceHeader])) {
                            $values[$targetField] = trim((string) ($row[$headerIndexes[$sourceHeader]] ?? ''));
                        }
                    }
                    $values = $this->normalizeImportValues($mapping->target_type, $values);
                    $duplicateKey = $this->duplicateKey($mapping->target_type, $values);
                    $duplicateKeyHash = $duplicateKey === null ? null : hash('sha256', $duplicateKey);

                    if ($duplicateKeyHash !== null && isset($seenKeys[$duplicateKeyHash])) {
                        if (isset($acceptedCandidateHashes[$duplicateKeyHash])) {
                            $result = ['import_batch_id' => $importBatch->id, 'source_row_number' => $sourceRowNumber, 'target_type' => $mapping->target_type, 'action' => 'merged', 'errors_json' => ['row' => 'merged into the first reviewed candidate row']];
                            $outcome = 'skipped';
                        } else {
                            $result = ['import_batch_id' => $importBatch->id, 'source_row_number' => $sourceRowNumber, 'target_type' => $mapping->target_type, 'action' => 'error', 'errors_json' => ['row' => 'duplicate candidate in the same import batch']];
                            $outcome = 'error';
                        }
                    } else {
                        if ($duplicateKeyHash !== null) {
                            $seenKeys[$duplicateKeyHash] = true;
                        }

                        try {
                            [$result, $outcome] = $this->processImportRow($mapping->target_type, $importBatch, $values, $sourceRowNumber, $organization);
                        } catch (ValidationException $exception) {
                            $result = ['import_batch_id' => $importBatch->id, 'source_row_number' => $sourceRowNumber, 'target_type' => $mapping->target_type, 'action' => 'error', 'errors_json' => ['row' => $exception->errors()['plan_limit'][0] ?? '現在のプランでは登録できません。']];
                            $outcome = 'error';
                        } catch (Throwable) {
                            $result = ['import_batch_id' => $importBatch->id, 'source_row_number' => $sourceRowNumber, 'target_type' => $mapping->target_type, 'action' => 'error', 'errors_json' => ['row' => 'invalid date, number, or related data']];
                            $outcome = 'error';
                        }
                    }

                    $counts[$outcome]++;
                    ImportRowResult::query()->create($result);
                }

                $importBatch->update(['status' => 'completed', 'inserted_rows' => $counts['inserted'], 'updated_rows' => $counts['updated'], 'skipped_rows' => $counts['skipped'], 'error_rows' => $counts['error'], 'completed_at' => now()]);
                app(ImpactReportMetricsService::class)->capture($organization, $importBatch, 'import_after');
                if ($counts['inserted'] + $counts['updated'] > 0) {
                    app(ProductEventRecorder::class)->recordOnce('first_import_completed', $organization);
                }
                app(OnboardingProgressService::class)->recordCompletionIfReady($organization);
            });
        } catch (Throwable $exception) {
            ImportBatch::query()->whereKey($importBatch->id)->where('status', 'executing')->update([
                'status' => 'failed',
                'failure_message' => ImportFailureMessage::safeMessage($exception),
                'completed_at' => now(),
            ]);

            if ($expectedStatus === 'queued') {
                throw new \RuntimeException('Import batch execution failed.');
            }

            throw $exception;
        } finally {
            if (is_resource($sourceRowsStream)) {
                fclose($sourceRowsStream);
            }
        }
    }

    public function assertNoSelectedTemplateSampleRows(ImportBatch $importBatch, ImportMapping $mapping): void
    {
        $sample = config('opsdeck.import_samples.'.$mapping->target_type);
        $columnMap = $mapping->column_map;
        if (! is_array($sample) || ! is_array($sample['headers'] ?? null) || ! is_array($sample['row'] ?? null) || ! is_array($columnMap)) {
            return;
        }

        if (count($sample['headers']) !== count($sample['row'])) {
            return;
        }

        $sampleValues = array_combine($sample['headers'], $sample['row']);
        if (! is_array($sampleValues)) {
            return;
        }

        $expectedValues = [];
        foreach ($sample['headers'] as $sourceHeader) {
            $targetField = $columnMap[$sourceHeader] ?? null;
            if (! is_string($targetField) || $targetField === '') {
                return;
            }

            $expectedValues[$targetField] = trim((string) ($sampleValues[$sourceHeader] ?? ''));
        }
        $expectedValues = $this->normalizeImportValues($mapping->target_type, $expectedValues);
        ksort($expectedValues);

        foreach ($importBatch->rowResults()
            ->where('target_type', $mapping->target_type)
            ->whereIn('action', ['new_candidate', 'update_candidate', 'confirmation_required'])
            ->whereNotNull('after_json')
            ->select('after_json')
            ->cursor() as $rowResult) {
            $actualValues = $rowResult->after_json;
            if (! is_array($actualValues)) {
                continue;
            }

            ksort($actualValues);
            if ($actualValues === $expectedValues) {
                throw ValidationException::withMessages([
                    'sample' => '未変更のサンプル行を検出しました。サンプルCSVはPreview専用です。実データに置き換えるか、サンプル行を除いてからPreviewをやり直してください。',
                ]);
            }
        }
    }

    /** @return array{0: list<string>, 1: resource} */
    private function stageSourceRows(ImportBatch $importBatch, string $targetType): array
    {
        $disk = (string) config('opsdeck.import.disk');
        $stagingStream = tmpfile();
        if (! is_resource($stagingStream)) {
            throw new \RuntimeException('Import実行の一時領域を準備できません。');
        }
        $stagingStreamTransferred = false;

        $stageRow = function (array $row) use ($stagingStream): void {
            $line = Crypt::encryptString(json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)).PHP_EOL;
            if (fwrite($stagingStream, $line) !== strlen($line)) {
                throw new \RuntimeException('Import実行の一時データを書き込めません。');
            }
        };

        try {
            if ($importBatch->source_type === 'google_sheets') {
                $connection = GoogleSheetConnection::query()->where('organization_id', $importBatch->organization_id)->firstOrFail();
                $parsed = app(GoogleSheetsImportSource::class)->parseEach($connection, $importBatch->source_external_id, $importBatch->selected_sheet_name, $stageRow, $targetType === 'contracts');
            } elseif ($importBatch->source_type === 'xlsx') {
                $parsed = app(XlsxImportSource::class)->parseEach(Storage::disk($disk)->path($importBatch->source_file_path), $importBatch->selected_sheet_name, $stageRow);
            } else {
                $sourceStream = Storage::disk($disk)->readStream($importBatch->source_file_path);
                abort_unless(is_resource($sourceStream), 422, 'CSVファイルを読み込めません。');
                try {
                    $parsed = app(CsvImportSource::class)->parseEachStream($sourceStream, $stageRow);
                } finally {
                    fclose($sourceStream);
                }
            }

            rewind($stagingStream);
            $stagingStreamTransferred = true;

            return [$parsed['headers'], $stagingStream];
        } finally {
            if (! $stagingStreamTransferred && is_resource($stagingStream)) {
                fclose($stagingStream);
            }
        }
    }

    /**
     * @param  resource  $stagingStream
     * @return \Generator<int, array{source_row_number: int, values: list<string>}>
     */
    private function stagedSourceRows(mixed $stagingStream): \Generator
    {
        while (($line = fgets($stagingStream)) !== false) {
            $row = json_decode(Crypt::decryptString(trim($line)), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($row)
                || ! is_int($row['source_row_number'] ?? null)
                || $row['source_row_number'] < 1
                || ! is_array($row['values'] ?? null)) {
                throw new \RuntimeException('Import実行の一時データを読み取れません。');
            }

            yield ['source_row_number' => $row['source_row_number'], 'values' => array_map(static fn (mixed $value): string => (string) $value, $row['values'])];
        }

        if (! feof($stagingStream)) {
            throw new \RuntimeException('Import実行の一時データを読み取れません。');
        }
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private function processImportRow(string $targetType, ImportBatch $importBatch, array $values, int $sourceRowNumber, Organization $organization): array
    {
        $result = ['import_batch_id' => $importBatch->id, 'source_row_number' => $sourceRowNumber, 'target_type' => $targetType, 'action' => 'skipped'];
        if ($targetType === 'employees') {
            if (($values['name'] ?? '') === '' || ! filter_var($values['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                $result['action'] = 'error';
                $result['errors_json'] = ['row' => 'name and a valid email are required'];

                return [$result, 'error'];
            }
            $attributes = array_intersect_key($values, array_flip(['employee_number', 'name', 'email', 'employment_status', 'joined_at', 'left_at', 'job_title', 'memo']));
            $attributes['employment_status'] = $attributes['employment_status'] ?? config('opsdeck.employee_status.active');
            foreach (['joined_at', 'left_at'] as $dateField) {
                if (isset($attributes[$dateField]) && $attributes[$dateField] !== '') {
                    $attributes[$dateField] = CarbonImmutable::parse($attributes[$dateField])->toDateString();
                }
            }
            $record = Employee::query()->where('organization_id', $importBatch->organization_id)->where('email', $attributes['email'])->first();
        } elseif ($targetType === 'departments') {
            if (($values['name'] ?? '') === '') {
                $result['action'] = 'error';
                $result['errors_json'] = ['row' => 'name is required'];

                return [$result, 'error'];
            }
            $attributes = array_intersect_key($values, array_flip(['name', 'code', 'is_active']));
            $attributes['is_active'] = ! in_array(strtolower((string) ($attributes['is_active'] ?? 'true')), ['0', 'false', 'no', 'inactive'], true);
            $record = Department::query()->where('organization_id', $importBatch->organization_id)->where('name', $attributes['name'])->first();
        } elseif ($targetType === 'assets') {
            if (($values['asset_number'] ?? '') === '' || ! in_array($values['asset_type'] ?? '', config('opsdeck.asset_types'), true)) {
                $result['action'] = 'error';
                $result['errors_json'] = ['row' => 'asset_number and a valid asset_type are required'];

                return [$result, 'error'];
            }
            $attributes = array_intersect_key($values, array_flip(['asset_number', 'asset_type', 'manufacturer', 'model', 'serial_number', 'os', 'purchased_at', 'purchase_cost', 'warranty_until', 'status', 'location', 'memo']));
            $attributes['status'] = $attributes['status'] ?? config('opsdeck.asset_status.in_stock');
            if (! in_array($attributes['status'], config('opsdeck.asset_statuses'), true) || (isset($attributes['purchase_cost']) && $attributes['purchase_cost'] !== '' && ! is_numeric($attributes['purchase_cost']))) {
                $result['action'] = 'error';
                $result['errors_json'] = ['row' => 'status or purchase_cost is invalid'];

                return [$result, 'error'];
            }
            foreach (['purchased_at', 'warranty_until'] as $dateField) {
                if (isset($attributes[$dateField]) && $attributes[$dateField] !== '') {
                    $attributes[$dateField] = CarbonImmutable::parse($attributes[$dateField])->toDateString();
                }
            }
            $record = Asset::query()->where('organization_id', $importBatch->organization_id)->where('asset_number', $attributes['asset_number'])->first();
        } elseif ($targetType === 'saas_services') {
            if (($values['name'] ?? '') === '' || ! in_array($values['status'] ?? '', config('opsdeck.saas_service_statuses'), true)) {
                $result['action'] = 'error';
                $result['errors_json'] = ['row' => 'name and a valid status are required'];

                return [$result, 'error'];
            }
            $attributes = array_intersect_key($values, array_flip(['name', 'vendor', 'category', 'website_url', 'status', 'memo']));
            if (isset($attributes['website_url']) && $attributes['website_url'] !== '' && filter_var($attributes['website_url'], FILTER_VALIDATE_URL) === false) {
                $result['action'] = 'error';
                $result['errors_json'] = ['row' => 'website_url is invalid'];

                return [$result, 'error'];
            }
            $record = SaasService::query()->where('organization_id', $importBatch->organization_id)->where('name', $attributes['name'])->first();
        } elseif ($targetType === 'saas_assignments') {
            $serviceName = $values['service_name'] ?? '';
            $employeeEmail = $values['employee_email'] ?? '';
            $service = SaasService::query()->where('organization_id', $importBatch->organization_id)->where('name', $serviceName)->first();
            $employee = $employeeEmail === '' ? null : Employee::query()->where('organization_id', $importBatch->organization_id)->where('email', $employeeEmail)->first();
            if ($service === null || ($employeeEmail !== '' && $employee === null) || ! in_array($values['mfa_status'] ?? '', config('opsdeck.saas_assignment_mfa_statuses'), true)) {
                $result['action'] = 'error';
                $result['errors_json'] = ['row' => 'service_name, employee_email and mfa_status must resolve to valid organization data'];

                return [$result, 'error'];
            }
            $attributes = array_intersect_key($values, array_flip(['account_identifier', 'license_name', 'status', 'mfa_status', 'last_reviewed_at', 'assigned_at', 'ended_at', 'memo']));
            $attributes['saas_service_id'] = $service->id;
            $attributes['employee_id'] = $employee?->id;
            foreach (['last_reviewed_at', 'assigned_at', 'ended_at'] as $dateField) {
                if (isset($attributes[$dateField]) && $attributes[$dateField] !== '') {
                    $attributes[$dateField] = CarbonImmutable::parse($attributes[$dateField])->toDateString();
                }
            }
            if (($attributes['assigned_at'] ?? null) !== null && ($attributes['ended_at'] ?? null) !== null && $attributes['ended_at'] < $attributes['assigned_at']) {
                $result['action'] = 'error';
                $result['errors_json'] = ['row' => 'ended_at must be on or after assigned_at'];

                return [$result, 'error'];
            }
            $record = SaasAssignment::query()->where('organization_id', $importBatch->organization_id)->where('saas_service_id', $service->id)->where('employee_id', $employee?->id)->where('account_identifier', $attributes['account_identifier'] ?? null)->first();
        } elseif ($targetType === 'security_controls') {
            if (($values['control_code'] ?? '') === ''
                || ($values['title'] ?? '') === ''
                || ($values['category'] ?? '') === ''
                || ($values['description'] ?? '') === ''
                || ! in_array($values['status'] ?? '', config('opsdeck.security_control_statuses'), true)) {
                $result['action'] = 'error';
                $result['errors_json'] = ['row' => 'control_code, title, category, description and a valid status are required'];

                return [$result, 'error'];
            }
            $attributes = array_intersect_key($values, array_flip(['control_code', 'title', 'category', 'description', 'status', 'review_frequency', 'last_reviewed_at', 'next_review_at', 'memo']));
            foreach (['last_reviewed_at', 'next_review_at'] as $dateField) {
                if (isset($attributes[$dateField]) && $attributes[$dateField] !== '') {
                    $attributes[$dateField] = CarbonImmutable::parse($attributes[$dateField])->toDateString();
                }
            }
            if (($attributes['last_reviewed_at'] ?? null) !== null
                && ($attributes['next_review_at'] ?? null) !== null
                && $attributes['next_review_at'] < $attributes['last_reviewed_at']) {
                $result['action'] = 'error';
                $result['errors_json'] = ['row' => 'next_review_at must be on or after last_reviewed_at'];

                return [$result, 'error'];
            }
            $record = SecurityControl::query()
                ->where('organization_id', $importBatch->organization_id)
                ->where('control_code', $attributes['control_code'])
                ->first();
        } elseif ($targetType === 'questionnaire_answers') {
            $questionnaire = Questionnaire::query()->where('organization_id', $importBatch->organization_id)->where('title', $values['questionnaire_title'] ?? '')->first();
            $question = $questionnaire?->questions()->where('question_text', $values['question_text'] ?? '')->first();
            if ($question === null || ! in_array($values['source_type'] ?? '', config('opsdeck.questionnaire_answer_source_types'), true) || (($values['answer_text'] ?? '') === '' && ($values['answer_choice'] ?? '') === '')) {
                $result['action'] = 'error';
                $result['errors_json'] = ['row' => 'questionnaire/question and a non-empty answer with valid source_type are required'];

                return [$result, 'error'];
            }
            $attributes = ['question_id' => $question->id, 'answer_text' => $values['answer_text'] ?? null, 'answer_choice' => $values['answer_choice'] ?? null, 'answer_status' => 'draft', 'source_type' => $values['source_type']];
            $record = QuestionnaireAnswer::query()->where('question_id', $question->id)->latest('id')->first();
        } elseif ($targetType === 'contracts') {
            if (($values['contract_name'] ?? '') === '' || ($values['vendor_name'] ?? '') === '' || ! in_array($values['billing_cycle'] ?? '', config('opsdeck.contract_billing_cycles'), true) || ! in_array($values['status'] ?? '', config('opsdeck.contract_statuses'), true)) {
                $result['action'] = 'error';
                $result['errors_json'] = ['row' => 'contract_name, vendor_name, billing_cycle and status are required and valid'];

                return [$result, 'error'];
            }
            if (! app(ContractAmountValidator::class)->isValid($values['amount'] ?? null)) {
                $result['action'] = 'error';
                $result['errors_json'] = ['row' => 'amount must be non-negative, have up to two decimal places and fit the supported maximum'];

                return [$result, 'error'];
            }
            $attributes = array_intersect_key($values, array_flip(['vendor_name', 'contract_name', 'contract_type', 'billing_cycle', 'amount', 'currency', 'started_at', 'ends_at', 'auto_renew', 'cancellation_deadline', 'renewal_date', 'license_quantity', 'status', 'memo']));
            $attributes['contract_type'] = $attributes['contract_type'] ?? 'other';
            $attributes['currency'] = $attributes['currency'] ?? 'JPY';
            $attributes['auto_renew'] = ! in_array(strtolower((string) ($attributes['auto_renew'] ?? 'false')), ['0', 'false', 'no'], true);
            foreach (['started_at', 'ends_at', 'cancellation_deadline', 'renewal_date'] as $dateField) {
                if (isset($attributes[$dateField]) && $attributes[$dateField] !== '') {
                    $attributes[$dateField] = CarbonImmutable::parse($attributes[$dateField])->toDateString();
                }
            }
            if (($attributes['started_at'] ?? null) !== null && ($attributes['ends_at'] ?? null) !== null && $attributes['ends_at'] < $attributes['started_at']) {
                $result['action'] = 'error';
                $result['errors_json'] = ['row' => 'ends_at must be on or after started_at'];

                return [$result, 'error'];
            }
            $record = Contract::query()->where('organization_id', $importBatch->organization_id)->where('contract_name', $attributes['contract_name'])->where('vendor_name', $attributes['vendor_name'])->first();
        } else {
            if (($values['title'] ?? '') === '' || ($values['evidence_type'] ?? '') === '') {
                $result['action'] = 'error';
                $result['errors_json'] = ['row' => 'title and evidence_type are required'];

                return [$result, 'error'];
            }
            $attributes = array_intersect_key($values, array_flip(['title', 'evidence_type', 'description', 'valid_from', 'valid_until']));
            foreach (['valid_from', 'valid_until'] as $dateField) {
                if (isset($attributes[$dateField]) && $attributes[$dateField] !== '') {
                    $attributes[$dateField] = CarbonImmutable::parse($attributes[$dateField])->toDateString();
                }
            }
            if (($attributes['valid_from'] ?? null) !== null && ($attributes['valid_until'] ?? null) !== null && $attributes['valid_until'] < $attributes['valid_from']) {
                $result['action'] = 'error';
                $result['errors_json'] = ['row' => 'valid_until must be on or after valid_from'];

                return [$result, 'error'];
            }
            $record = Evidence::query()->where('organization_id', $importBatch->organization_id)->where('title', $attributes['title'])->where('evidence_type', $attributes['evidence_type'])->first();
        }

        if ($record === null) {
            $limitedResource = match ($targetType) {
                'employees', 'assets', 'saas_services' => $targetType,
                default => null,
            };
            if ($limitedResource !== null) {
                app(PlanLimitService::class)->assertCanAdd($organization, $limitedResource);
            }
            $record = match ($targetType) {
                'employees' => Employee::query()->create($attributes + ['organization_id' => $importBatch->organization_id]),
                'departments' => Department::query()->create($attributes + ['organization_id' => $importBatch->organization_id]),
                'assets' => Asset::query()->create($attributes + ['organization_id' => $importBatch->organization_id]),
                'saas_services' => SaasService::query()->create($attributes + ['organization_id' => $importBatch->organization_id]),
                'saas_assignments' => SaasAssignment::query()->create($attributes + ['organization_id' => $importBatch->organization_id]),
                'contracts' => Contract::query()->create($attributes + ['organization_id' => $importBatch->organization_id]),
                'security_controls' => SecurityControl::query()->create($attributes + ['organization_id' => $importBatch->organization_id]),
                'questionnaire_answers' => QuestionnaireAnswer::query()->create($attributes),
                default => Evidence::query()->create($attributes + ['organization_id' => $importBatch->organization_id, 'storage_disk' => 'local', 'storage_path' => 'metadata-only', 'mime_type' => 'application/x-opsdeck-metadata', 'file_size' => 0, 'uploaded_by' => $importBatch->executed_by]),
            };
            $result['action'] = 'inserted';
            $result['target_id'] = $record->id;
            $result['after_json'] = $this->snapshotAttributes($record, array_keys($attributes));

            return [$result, 'inserted'];
        }

        $before = $this->snapshotAttributes($record, array_keys($attributes));
        $record->update($attributes);
        $result['action'] = 'updated';
        $result['target_id'] = $record->id;
        $result['before_json'] = $before;
        $result['after_json'] = $this->snapshotAttributes($record, array_keys($attributes));

        return [$result, 'updated'];
    }

    public function duplicateKey(string $targetType, array $values): ?string
    {
        return match ($targetType) {
            'employees' => ($values['email'] ?? '') !== '' ? 'employee:'.strtolower($values['email']) : null,
            'departments' => ($values['name'] ?? '') !== '' ? 'department:'.mb_strtolower($values['name']) : null,
            'assets' => ($values['asset_number'] ?? '') !== '' ? 'asset:'.mb_strtolower($values['asset_number']) : null,
            'saas_services' => ($values['name'] ?? '') !== '' ? 'saas:'.mb_strtolower($values['name']) : null,
            'saas_assignments' => ($values['service_name'] ?? '').'|'.($values['employee_email'] ?? '').'|'.($values['account_identifier'] ?? ''),
            'contracts' => ($values['vendor_name'] ?? '').'|'.($values['contract_name'] ?? ''),
            'security_controls' => ($values['control_code'] ?? '') !== '' ? 'security-control:'.mb_strtolower($values['control_code']) : null,
            'evidence' => ($values['evidence_type'] ?? '').'|'.($values['title'] ?? ''),
            'questionnaire_answers' => ($values['questionnaire_title'] ?? '').'|'.($values['question_text'] ?? ''),
            default => null,
        };
    }

    public function normalizeImportValues(string $targetType, array $values): array
    {
        foreach ($values as $field => $value) {
            $values[$field] = is_string($value) ? trim($value) : $value;
        }
        foreach (['email', 'employee_email'] as $field) {
            if (isset($values[$field])) {
                $values[$field] = strtolower($values[$field]);
            }
        }
        if ($targetType === 'contracts' && isset($values['amount'])) {
            $values['amount'] = str_replace([',', ' '], '', $values['amount']);
        }
        foreach (['auto_renew', 'is_active'] as $field) {
            if (isset($values[$field])) {
                $values[$field] = match (strtolower((string) $values[$field])) {
                    '1', 'true', 'yes', 'y', '有', 'はい' => 'true',
                    '0', 'false', 'no', 'n', '無', 'いいえ' => 'false',
                    default => $values[$field],
                };
            }
        }
        foreach (config('opsdeck.import_date_fields.'.$targetType, []) as $field) {
            if (! isset($values[$field]) || $values[$field] === '' || is_numeric($values[$field])) {
                continue;
            }
            try {
                $values[$field] = CarbonImmutable::parse($values[$field])->toDateString();
            } catch (Throwable) {
                // Keep invalid dates intact so the preview can mark the row for correction.
            }
        }

        return $values;
    }

    public function sameImportValues(array $previous, array $current): bool
    {
        foreach ($previous as $field => $value) {
            if ((string) ($current[$field] ?? '') !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $fields
     * @return array<string, mixed>
     */
    private function snapshotAttributes(Model $record, array $fields): array
    {
        $snapshot = [];
        foreach ($fields as $field) {
            $snapshot[$field] = $record->getRawOriginal($field);
        }

        return $snapshot;
    }
}
