<?php

namespace App\Http\Controllers;

use App\Exceptions\StorageQuotaExceededException;
use App\Http\Requests\ImportBatch\ExecuteImportBatchRequest;
use App\Http\Requests\ImportBatch\ResolveImportBatchErrorsRequest;
use App\Http\Requests\ImportBatch\RetryImportBatchRequest;
use App\Http\Requests\ImportBatch\ReviewImportCandidateRequest;
use App\Http\Requests\ImportBatch\RollbackImportBatchRequest;
use App\Http\Requests\ImportBatch\SelectGoogleSpreadsheetRequest;
use App\Http\Requests\ImportBatch\SelectImportSheetRequest;
use App\Http\Requests\ImportBatch\StoreAdditionalSheetImportRequest;
use App\Http\Requests\ImportBatch\StoreImportBatchRequest;
use App\Http\Requests\ImportBatch\StoreImportMappingRequest;
use App\Http\Requests\ImportBatch\UpdateImportRowSelectionRequest;
use App\Jobs\ExecuteImportBatch;
use App\Jobs\PrepareGoogleSheetDiffPreview;
use App\Jobs\PrepareImportFileDiffPreview;
use App\Jobs\PrepareImportMappingAnalysis;
use App\Jobs\PrepareImportPreview;
use App\Jobs\PrepareImportRetryBatch;
use App\Jobs\RollbackImportBatch;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\GoogleSheetConnection;
use App\Models\ImportBatch;
use App\Models\ImportProfile;
use App\Models\ImportReviewCandidate;
use App\Models\ImportRowResult;
use App\Models\Organization;
use App\Models\SaasService;
use App\Models\SecurityControl;
use App\Services\Ai\AiUsageBudgetService;
use App\Services\Ai\ImportMappingSuggestionService;
use App\Services\ContractAmountValidator;
use App\Services\Import\CsvImportSource;
use App\Services\Import\GoogleSheetsImportSource;
use App\Services\Import\ImportErrorReportExport;
use App\Services\Import\ImportFileLocalStager;
use App\Services\Import\ImportNormalizationSuggestionService;
use App\Services\Import\ImportProfileService;
use App\Services\Import\ImportSourceIntegrityService;
use App\Services\Import\XlsxImportSource;
use App\Services\ImportBatchExecutionService;
use App\Services\ImportBatchRollbackService;
use App\Services\OrganizationStorageQuotaService;
use App\Services\PlanFeatureService;
use App\Services\PrivateUploadStorageService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ImportBatchController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $this->organization($request);
        $this->authorize('viewAny', ImportBatch::class);
        $capabilities = $this->importCapabilities($organization);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(config('opsdeck.import_batch_statuses'))],
            'has_errors' => ['nullable', 'boolean'],
            'unreviewed' => ['nullable', 'boolean'],
            'created_after' => ['nullable', 'date'],
            'created_before' => ['nullable', 'date', 'after_or_equal:created_after'],
        ]);
        $importBatches = ImportBatch::query()
            ->where('organization_id', $organization->id)
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when((bool) ($filters['has_errors'] ?? false), function (Builder $query): void {
                $query->where(function (Builder $batchQuery): void {
                    $batchQuery->where('status', 'failed')->orWhere('error_rows', '>', 0);
                });
            })
            ->when((bool) ($filters['unreviewed'] ?? false), fn (Builder $query) => $query->whereNull('errors_reviewed_at'))
            ->when($filters['created_after'] ?? null, fn (Builder $query, string $createdAfter) => $query->where('created_at', '>=', CarbonImmutable::parse($createdAfter, $organization->timezone)->startOfDay()->utc()->format('Y-m-d H:i:s')))
            ->when($filters['created_before'] ?? null, fn (Builder $query, string $createdBefore) => $query->where('created_at', '<', CarbonImmutable::parse($createdBefore, $organization->timezone)->startOfDay()->addDay()->utc()->format('Y-m-d H:i:s')))
            ->latest()
            ->orderByDesc('id')
            ->paginate(config('opsdeck.pagination.per_page'))
            ->withQueryString();
        $importBatchStatusLabels = config('opsdeck.import_batch_status_labels', []);
        $importSourceTypeLabels = config('opsdeck.import_source_type_labels', []);

        return view('imports.index', compact('importBatches', 'organization', 'filters', 'capabilities', 'importBatchStatusLabels', 'importSourceTypeLabels'));
    }

    public function create(Request $request): View
    {
        $organization = $this->organization($request);
        $this->authorize('create', [ImportBatch::class, $organization]);
        $capabilities = $this->importCapabilities($organization);
        $canManageBilling = $request->user()->can('manageBilling', $organization);
        $diffSourceBatch = null;
        $diffSourceMapping = null;
        $requestedDiffSourceBatchId = $request->query('diff_source_batch_id');
        if ($requestedDiffSourceBatchId !== null) {
            abort_unless(filter_var($requestedDiffSourceBatchId, FILTER_VALIDATE_INT) !== false, 404);
            $diffSourceBatch = ImportBatch::query()
                ->where('organization_id', $organization->id)
                ->whereKey((int) $requestedDiffSourceBatchId)
                ->whereIn('source_type', ['csv', 'xlsx'])
                ->where('status', 'completed')
                ->firstOrFail();
            $this->authorize('view', $diffSourceBatch);
            $diffSourceMapping = $diffSourceBatch->mappings()->where('status', 'ready')->latest('id')->firstOrFail();
        }
        $requestedTargetType = $diffSourceMapping?->target_type ?? $request->query('target_type');
        $targetType = is_string($requestedTargetType) && in_array($requestedTargetType, config('opsdeck.import_target_types', []), true)
            ? $requestedTargetType
            : null;
        $targetTypeLabel = $targetType === null ? null : config('opsdeck.import_target_labels.'.$targetType, $targetType);
        $samplePreviewTargetType = is_string($targetType) && array_key_exists($targetType, config('opsdeck.import_samples', []))
            ? $targetType
            : 'employees';

        $storageQuota = app(OrganizationStorageQuotaService::class)->summary($organization);

        return view('imports.create', compact('organization', 'capabilities', 'canManageBilling', 'targetType', 'targetTypeLabel', 'diffSourceBatch', 'samplePreviewTargetType', 'storageQuota'));
    }

    public function sample(Request $request, string $targetType): StreamedResponse
    {
        $this->organization($request);
        abort_unless(array_key_exists($targetType, config('opsdeck.import_samples', [])), 404);
        $sample = config('opsdeck.import_samples.'.$targetType);

        return response()->streamDownload(static function () use ($sample): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $sample['headers'], ',', '"', '');
            fputcsv($handle, $sample['row'], ',', '"', '');
            fclose($handle);
        }, 'opsdeck-sample-'.$targetType.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function samplePreview(Request $request, string $targetType): View
    {
        $organization = $this->organization($request);
        $this->authorize('create', [ImportBatch::class, $organization]);
        abort_unless(array_key_exists($targetType, config('opsdeck.import_samples', [])), 404);

        $sample = config('opsdeck.import_samples.'.$targetType);
        $aliases = config('opsdeck.import_column_aliases.'.$targetType, []);
        $targetLabel = config('opsdeck.import_target_labels.'.$targetType, 'データ');
        $columns = [];
        foreach ($sample['headers'] as $index => $header) {
            $field = $aliases[$header] ?? null;
            $columns[] = [
                'header' => $header,
                'target_field' => $field,
                'field' => $field === null
                    ? null
                    : config('opsdeck.import_field_labels_by_target.'.$targetType.'.'.$field, config('opsdeck.import_field_labels.'.$field, $field))
                        .(in_array($field, config('opsdeck.import_required_mapping_fields.'.$targetType, []), true) ? '（必須）' : ''),
                'value' => $sample['row'][$index] ?? '',
            ];
        }

        $importValueLabels = config('opsdeck.import_value_labels_by_target.'.$targetType, []);

        return view('imports.samples.preview', compact('sample', 'targetLabel', 'targetType', 'columns', 'importValueLabels'));
    }

    public function googleSheets(Request $request): View|RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('view', $organization);
        $this->assertFeatureAvailable($organization, 'google_sheets', 'Google Sheets連携はPremiumプランで利用できます。');
        $connection = GoogleSheetConnection::query()->where('organization_id', $organization->id)->first();
        if ($connection === null || $connection->revoked_at !== null || ! $connection->refresh_token) {
            return to_route('google-sheets.connection')->with('status', '先にGoogle Sheetsを接続してください。');
        }
        $spreadsheets = app(GoogleSheetsImportSource::class)->spreadsheets($connection);

        return view('imports.google-sheets', compact('organization', 'spreadsheets'));
    }

    public function selectGoogleSpreadsheet(SelectGoogleSpreadsheetRequest $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);
        $this->assertFeatureAvailable($organization, 'google_sheets', 'Google Sheets連携はPremiumプランで利用できます。');
        $connection = GoogleSheetConnection::query()->where('organization_id', $organization->id)->firstOrFail();
        $sheets = app(GoogleSheetsImportSource::class)->sheets($connection, $request->string('spreadsheet_id')->toString());
        abort_if($sheets === [], 422, 'Google Sheetsにシートがありません。');
        $batch = ImportBatch::query()->create([
            'organization_id' => $organization->id,
            'source_type' => 'google_sheets',
            'source_name' => $request->string('source_name')->toString(),
            'source_external_id' => $request->string('spreadsheet_id')->toString(),
            'available_sheets_json' => $sheets,
            'status' => 'sheet_selection',
            'executed_by' => $request->user()->id,
            'started_at' => now(),
        ]);

        return to_route('imports.show', $batch)->with('status', 'Google Sheetsのシートを選択してください。');
    }

    public function selectGoogleSheet(SelectImportSheetRequest $request, ImportBatch $importBatch): RedirectResponse
    {
        $importBatch = $this->scopedBatch($this->organization($request), $importBatch);
        $this->authorize('update', $importBatch);
        $this->assertFeatureAvailable($this->organization($request), 'google_sheets', 'Google Sheets連携はPremiumプランで利用できます。');
        abort_unless($importBatch->status === 'sheet_selection' && $importBatch->source_type === 'google_sheets', 422, 'Google Sheetsのシート選択が必要なバッチではありません。');
        abort_unless(in_array($request->string('sheet_name')->toString(), $importBatch->available_sheets_json ?? [], true), 422, '選択したシートが存在しません。');
        GoogleSheetConnection::query()->where('organization_id', $importBatch->organization_id)->firstOrFail();
        $headerRowNumber = (int) $request->validated('header_row_number');
        $headerEndRowNumber = (int) $request->validated('header_end_row_number');
        $dataStartRowNumber = $request->validated('data_start_row_number');
        DB::transaction(function () use ($importBatch, $request, $headerRowNumber, $headerEndRowNumber, $dataStartRowNumber): void {
            $claimed = ImportBatch::query()->whereKey($importBatch->id)->where('status', 'sheet_selection')->update([
                'selected_sheet_name' => $request->string('sheet_name')->toString(),
                'header_row_number' => $headerRowNumber,
                'header_end_row_number' => $headerEndRowNumber,
                'data_start_row_number' => $dataStartRowNumber,
                'headers_json' => null,
                'status' => 'previewing',
                'total_rows' => 0,
                'failure_message' => null,
                'started_at' => now(),
                'completed_at' => null,
            ]);
            abort_unless($claimed === 1, 409, 'このシート選択はすでに処理されています。');
            if ($importBatch->diff_source_batch_id !== null) {
                PrepareImportFileDiffPreview::dispatch((int) $importBatch->diff_source_batch_id, $importBatch->id)->afterCommit();
            } else {
                PrepareImportPreview::dispatch($importBatch->id)->afterCommit();
            }
        });

        return to_route('imports.show', $importBatch)->with('status', 'シートを選択しました。Previewをバックグラウンドで準備します。');
    }

    public function syncGoogleSheet(Request $request, ImportBatch $importBatch): RedirectResponse
    {
        $importBatch = $this->scopedBatch($this->organization($request), $importBatch);
        $this->authorize('update', $importBatch);
        $this->assertFeatureAvailable($this->organization($request), 'google_sheets', 'Google Sheets連携はPremiumプランで利用できます。');
        abort_unless($importBatch->source_type === 'google_sheets' && $importBatch->status === 'completed' && $importBatch->source_external_id !== null && $importBatch->selected_sheet_name !== null, 422, '完了済みGoogle Sheets Importだけ再同期できます。');
        $mapping = $importBatch->mappings()->where('status', 'ready')->latest()->firstOrFail();
        $retryBatch = DB::transaction(function () use ($importBatch, $request): ImportBatch {
            $retryBatch = $importBatch->replicate(['created_at', 'updated_at']);
            $retryBatch->status = 'previewing';
            $retryBatch->mapping_id = null;
            $retryBatch->diff_source_batch_id = $importBatch->id;
            $retryBatch->total_rows = 0;
            $retryBatch->inserted_rows = 0;
            $retryBatch->updated_rows = 0;
            $retryBatch->skipped_rows = 0;
            $retryBatch->error_rows = 0;
            $retryBatch->started_at = now();
            $retryBatch->completed_at = null;
            $retryBatch->failure_message = null;
            $retryBatch->executed_by = $request->user()->id;
            $retryBatch->headers_json = null;
            $retryBatch->save();
            PrepareGoogleSheetDiffPreview::dispatch($importBatch->id, $retryBatch->id)->afterCommit();

            return $retryBatch;
        });

        return to_route('imports.show', $retryBatch)->with('status', 'Google Sheetsの差分Previewをバックグラウンドで準備します。削除候補は自動反映されません。');
    }

    public function store(StoreImportBatchRequest $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('create', [ImportBatch::class, $organization]);
        $targetType = $request->validated('target_type');
        $diffSourceBatch = null;
        $diffSourceMapping = null;
        $diffSourceBatchId = $request->validated('diff_source_batch_id');
        if ($diffSourceBatchId !== null) {
            $diffSourceBatch = ImportBatch::query()
                ->where('organization_id', $organization->id)
                ->whereKey((int) $diffSourceBatchId)
                ->whereIn('source_type', ['csv', 'xlsx'])
                ->where('status', 'completed')
                ->firstOrFail();
            $this->authorize('view', $diffSourceBatch);
            $diffSourceMapping = $diffSourceBatch->mappings()->where('status', 'ready')->latest('id')->firstOrFail();
            abort_unless($targetType === null || $targetType === $diffSourceMapping->target_type, 422, '差分取込の対象種別は前回のImportと一致させてください。');
            $targetType = $diffSourceMapping->target_type;
        }
        $headerRowNumber = (int) ($request->validated('header_row_number') ?? config('opsdeck.import.default_header_row_number'));
        $headerEndRowNumber = (int) ($request->validated('header_end_row_number') ?? $headerRowNumber);
        $dataStartRowNumber = $request->validated('data_start_row_number');
        $showRouteParameters = $targetType === null ? [] : ['target_type' => $targetType];
        $disk = config('opsdeck.import.disk');
        $file = $request->file('file');
        $sourceType = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
        if ($diffSourceBatch !== null) {
            abort_unless($sourceType === $diffSourceBatch->source_type, 422, '差分取込では前回と同じファイル形式を選択してください。');
        }
        $sourceFileSha256 = app(ImportSourceIntegrityService::class)->hashUploadedFile($file);
        if (strtolower($file->getClientOriginalExtension()) === 'xlsx') {
            $this->assertFeatureAvailable($organization, 'xlsx_preview', 'XLSXプレビューはPremiumプランで利用できます。');
        }
        try {
            return app(OrganizationStorageQuotaService::class)->storeWithinQuota(
                $organization,
                (int) $file->getSize(),
                function () use ($request, $organization, $diffSourceBatch, $headerRowNumber, $headerEndRowNumber, $dataStartRowNumber, $showRouteParameters, $disk, $file, $sourceFileSha256): RedirectResponse {
                    $path = app(PrivateUploadStorageService::class)->store(
                        $file,
                        'imports/'.$organization->id,
                        $disk,
                        config('opsdeck.import.allowed_extensions'),
                    );
                    if (strtolower($file->getClientOriginalExtension()) === 'xlsx') {
                        try {
                            $sheets = app(ImportFileLocalStager::class)->withLocalCopy(
                                $disk,
                                $path,
                                fn (string $localPath): array => app(XlsxImportSource::class)->sheets($localPath),
                            );
                            abort_if($sheets === [], 422, 'XLSXにシートがありません。');
                            $selectedSheetName = $diffSourceBatch !== null && in_array($diffSourceBatch->selected_sheet_name, $sheets, true)
                                ? $diffSourceBatch->selected_sheet_name
                                : null;
                            $batch = ImportBatch::query()->create([
                                'organization_id' => $organization->id, 'source_type' => 'xlsx',
                                'source_name' => $request->string('source_name')->toString(), 'source_file_path' => $path,
                                'source_storage_disk' => $disk,
                                'source_file_size_bytes' => $file->getSize(),
                                'source_file_sha256' => $sourceFileSha256,
                                'diff_source_batch_id' => $diffSourceBatch?->id,
                                'header_row_number' => $headerRowNumber,
                                'header_end_row_number' => $headerEndRowNumber,
                                'data_start_row_number' => $dataStartRowNumber,
                                'available_sheets_json' => $sheets, 'selected_sheet_name' => $selectedSheetName,
                                'status' => $selectedSheetName === null ? 'sheet_selection' : 'previewing', 'executed_by' => $request->user()->id,
                                'started_at' => now(),
                            ]);
                            if ($selectedSheetName !== null && $diffSourceBatch !== null) {
                                PrepareImportFileDiffPreview::dispatch($diffSourceBatch->id, $batch->id)->afterCommit();
                            }

                            return to_route('imports.show', [$batch, ...$showRouteParameters])->with('status', $selectedSheetName === null ? 'シートを選択してください。' : 'ファイルを受け付けました。前回データとの差分Previewを準備します。');
                        } catch (\Throwable $exception) {
                            $this->cleanupFailedImportUpload($disk, $path);
                            throw $exception;
                        }
                    }

                    $batch = null;
                    try {
                        $batch = ImportBatch::query()->create([
                            'organization_id' => $organization->id,
                            'source_type' => 'csv',
                            'source_name' => $request->string('source_name')->toString(),
                            'source_file_path' => $path,
                            'source_storage_disk' => $disk,
                            'source_file_size_bytes' => $file->getSize(),
                            'source_file_sha256' => $sourceFileSha256,
                            'diff_source_batch_id' => $diffSourceBatch?->id,
                            'header_row_number' => $headerRowNumber,
                            'header_end_row_number' => $headerEndRowNumber,
                            'data_start_row_number' => $dataStartRowNumber,
                            'status' => 'previewing',
                            'executed_by' => $request->user()->id,
                            'started_at' => now(),
                        ]);
                        if ($diffSourceBatch !== null) {
                            PrepareImportFileDiffPreview::dispatch($diffSourceBatch->id, $batch->id)->afterCommit();
                        } else {
                            PrepareImportPreview::dispatch($batch->id)->afterCommit();
                        }
                    } catch (\Throwable $exception) {
                        if ($batch === null) {
                            $this->cleanupFailedImportUpload($disk, $path);
                        } else {
                            $this->markPreviewDispatchFailed($batch->id);
                        }
                        throw $exception;
                    }

                    return to_route('imports.show', [$batch, ...$showRouteParameters])->with('status', 'ファイルを受け付けました。Previewをバックグラウンドで準備します。');
                },
            );
        } catch (StorageQuotaExceededException $exception) {
            return back()->withErrors(['file' => $exception->getMessage()]);
        }
    }

    public function show(Request $request, ImportBatch $importBatch): View
    {
        $organization = $this->organization($request);
        $importBatch = $this->scopedBatch($organization, $importBatch);
        $this->authorize('view', $importBatch);
        $canManageImport = $request->user()->can('update', $importBatch);
        $staleAfterMinutes = max(1, (int) config('opsdeck.import.stale_after_minutes'));
        $isImportBatchStale = ImportBatch::query()
            ->whereKey($importBatch->id)
            ->stale(now()->subMinutes($staleAfterMinutes))
            ->exists();
        $capabilities = $this->importCapabilities($organization);
        $availableAdditionalSheets = [];
        $workbookImports = collect();
        if ($importBatch->source_type === 'xlsx' && $importBatch->source_file_path !== null) {
            $workbookImports = ImportBatch::query()
                ->where('organization_id', $organization->id)
                ->where('source_type', 'xlsx')
                ->where('source_file_path', $importBatch->source_file_path)
                ->whereNotNull('selected_sheet_name')
                ->orderBy('id')
                ->get(['id', 'selected_sheet_name', 'status']);
            if ($importBatch->selected_sheet_name !== null) {
                $availableAdditionalSheets = array_values(array_diff(
                    $importBatch->available_sheets_json ?? [],
                    $workbookImports->pluck('selected_sheet_name')->all(),
                ));
            }
        }
        $latestRowResultIds = $importBatch->rowResults()
            ->selectRaw('MAX(import_row_results.id)')
            ->groupBy('source_row_number');
        $rowResults = $importBatch->rowResults()
            ->whereIn('id', $latestRowResultIds)
            ->orderBy('source_row_number')
            ->orderBy('id')
            ->paginate(config('opsdeck.pagination.per_page'), ['*'], 'row_results_page')
            ->withQueryString();
        $selectableRowResults = $rowResults->getCollection()->whereIn('action', ['new_candidate', 'update_candidate', 'confirmation_required', 'error', 'preview']);
        $hasErrorRows = $importBatch->rowResults()->whereIn('id', $latestRowResultIds)->where('action', 'error')->exists();
        $reviewCandidates = $importBatch->reviewCandidates()
            ->orderBy('id')
            ->paginate(config('opsdeck.pagination.per_page'), ['*'], 'review_candidates_page')
            ->withQueryString();
        $candidateSourceRows = $reviewCandidates->getCollection()
            ->flatMap(static fn (ImportReviewCandidate $candidate): array => $candidate->source_row_numbers_json ?? [])
            ->map(static fn (mixed $sourceRowNumber): int => (int) $sourceRowNumber)
            ->filter(static fn (int $sourceRowNumber): bool => $sourceRowNumber > 0)
            ->unique()
            ->values();
        $candidateRowResultsByNumber = $candidateSourceRows->isEmpty()
            ? collect()
            : $importBatch->rowResults()
                ->whereIn('source_row_number', $candidateSourceRows->all())
                ->orderByDesc('id')
                ->get(['id', 'source_row_number', 'after_json'])
                ->unique('source_row_number')
                ->keyBy('source_row_number');
        $mapping = $importBatch->mapping_id === null
            ? null
            : $importBatch->mappings()->whereKey($importBatch->mapping_id)->first();
        $importTargetLabel = config('opsdeck.import_target_labels.'.($mapping?->target_type ?? ''), '業務');
        $importFieldLabels = config('opsdeck.import_field_labels', []);
        $importValueLabels = config('opsdeck.import_value_labels_by_target.'.($mapping?->target_type ?? ''), []);
        $importActionLabels = config('opsdeck.import_action_labels', []);
        $importErrorLabels = config('opsdeck.import_error_labels', []);
        $importBatchStatusLabels = config('opsdeck.import_batch_status_labels', []);
        $importSourceTypeLabels = config('opsdeck.import_source_type_labels', []);
        $importCandidateTypeLabels = config('opsdeck.import_candidate_type_labels', []);
        $importCandidateStatusLabels = config('opsdeck.import_candidate_status_labels', []);
        $requestedTargetType = $request->query('target_type');
        $targetTypeSuggestion = is_string($requestedTargetType) && in_array($requestedTargetType, config('opsdeck.import_target_types', []), true)
            ? $requestedTargetType
            : null;

        return view('imports.show', compact('organization', 'importBatch', 'rowResults', 'selectableRowResults', 'hasErrorRows', 'reviewCandidates', 'candidateRowResultsByNumber', 'capabilities', 'availableAdditionalSheets', 'workbookImports', 'mapping', 'importTargetLabel', 'importFieldLabels', 'importValueLabels', 'importActionLabels', 'importErrorLabels', 'importBatchStatusLabels', 'importSourceTypeLabels', 'importCandidateTypeLabels', 'importCandidateStatusLabels', 'targetTypeSuggestion', 'isImportBatchStale', 'staleAfterMinutes', 'canManageImport'));
    }

    public function downloadErrors(Request $request, ImportBatch $importBatch, ImportErrorReportExport $export): StreamedResponse
    {
        $organization = $this->organization($request);
        $importBatch = $this->scopedBatch($organization, $importBatch);
        $this->authorize('view', $importBatch);
        abort_unless($importBatch->rowResults()->where('action', 'error')->exists(), 404);

        return $export->download($importBatch);
    }

    public function selectSheet(SelectImportSheetRequest $request, ImportBatch $importBatch): RedirectResponse
    {
        $organization = $this->organization($request);
        $importBatch = $this->scopedBatch($organization, $importBatch);
        $this->authorize('update', $importBatch);
        $this->assertFeatureAvailable($organization, 'xlsx_preview', 'XLSXプレビューはPremiumプランで利用できます。');
        abort_unless($importBatch->status === 'sheet_selection' && $importBatch->source_type === 'xlsx', 422, 'シート選択が必要なXLSXバッチではありません。');
        abort_unless(in_array($request->string('sheet_name')->toString(), $importBatch->available_sheets_json ?? [], true), 422, '選択したシートが存在しません。');
        DB::transaction(function () use ($importBatch, $request): void {
            $claimed = ImportBatch::query()->whereKey($importBatch->id)->where('status', 'sheet_selection')->update([
                'selected_sheet_name' => $request->string('sheet_name')->toString(),
                'headers_json' => null,
                'status' => 'previewing',
                'total_rows' => 0,
                'started_at' => now(),
                'completed_at' => null,
            ]);
            abort_unless($claimed === 1, 409, 'このシート選択はすでに処理されています。');
            PrepareImportPreview::dispatch($importBatch->id)->afterCommit();
        });

        return to_route('imports.show', $importBatch)->with('status', 'シートを選択しました。Previewをバックグラウンドで準備します。');
    }

    public function storeAdditionalSheet(StoreAdditionalSheetImportRequest $request, ImportBatch $importBatch): RedirectResponse
    {
        $organization = $this->organization($request);
        $importBatch = $this->scopedBatch($organization, $importBatch);
        $this->authorize('create', [ImportBatch::class, $organization]);
        $this->assertFeatureAvailable($organization, 'xlsx_preview', 'XLSXプレビューはPremiumプランで利用できます。');
        abort_unless(
            $importBatch->source_type === 'xlsx'
                && $importBatch->source_file_path !== null
                && $importBatch->selected_sheet_name !== null
                && is_array($importBatch->available_sheets_json),
            422,
            '別シートを作成できるXLSX Importではありません。',
        );

        $sheetName = $request->string('sheet_name')->toString();
        abort_unless(in_array($sheetName, $importBatch->available_sheets_json, true), 422, '選択したシートが存在しません。');
        abort_unless(
            Storage::disk($importBatch->sourceStorageDiskName())->exists($importBatch->source_file_path),
            404,
            '取込元ファイルが見つかりません。再アップロードしてください。',
        );
        $headerRowNumber = (int) ($request->validated('header_row_number') ?? config('opsdeck.import.default_header_row_number'));
        $headerEndRowNumber = (int) ($request->validated('header_end_row_number') ?? $headerRowNumber);
        $dataStartRowNumber = $request->validated('data_start_row_number');

        try {
            $additionalBatch = DB::transaction(function () use ($importBatch, $organization, $sheetName, $headerRowNumber, $headerEndRowNumber, $dataStartRowNumber, $request): ImportBatch {
                $sourceBatch = ImportBatch::query()
                    ->where('organization_id', $organization->id)
                    ->where('source_type', 'xlsx')
                    ->where('source_file_path', $importBatch->source_file_path)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless(in_array($sheetName, $sourceBatch->available_sheets_json ?? [], true), 422, '選択したシートが存在しません。');
                $alreadySelected = ImportBatch::query()
                    ->where('organization_id', $organization->id)
                    ->where('source_type', 'xlsx')
                    ->where('source_file_path', $sourceBatch->source_file_path)
                    ->where('selected_sheet_name', $sheetName)
                    ->exists();
                abort_if($alreadySelected, 409, 'このファイルのシートはすでに別のImportバッチで使用されています。');

                return ImportBatch::query()->create([
                    'organization_id' => $organization->id,
                    'source_type' => 'xlsx',
                    'source_name' => $sourceBatch->source_name,
                    'source_file_path' => $sourceBatch->source_file_path,
                    'source_storage_disk' => $sourceBatch->sourceStorageDiskName(),
                    'source_file_size_bytes' => $sourceBatch->source_file_size_bytes,
                    'source_file_sha256' => $sourceBatch->source_file_sha256,
                    'available_sheets_json' => $sourceBatch->available_sheets_json,
                    'selected_sheet_name' => $sheetName,
                    'header_row_number' => $headerRowNumber,
                    'header_end_row_number' => $headerEndRowNumber,
                    'data_start_row_number' => $dataStartRowNumber,
                    'status' => 'previewing',
                    'executed_by' => $request->user()->id,
                    'started_at' => now(),
                ]);
            });

            PrepareImportPreview::dispatch($additionalBatch->id)->afterCommit();
        } catch (\Throwable $exception) {
            if (isset($additionalBatch)) {
                $this->markPreviewDispatchFailed($additionalBatch->id);
            }
            throw $exception;
        }

        return to_route('imports.show', $additionalBatch)->with('status', '別シートのImportを作成しました。既存バッチとは別にPreviewとマッピングを確認してください。');
    }

    public function mapping(Request $request, ImportBatch $importBatch): View
    {
        $organization = $this->organization($request);
        $importBatch = $this->scopedBatch($organization, $importBatch);
        $this->authorize('view', $importBatch);
        abort_if($importBatch->diff_source_batch_id !== null, 409, '差分Previewは前回Import時のマッピングを固定しています。列対応を変更する場合は通常のImport Previewを作成してください。');
        abort_unless(in_array($importBatch->status, ['preview', 'ready'], true), 422, 'Preview準備が完了してからマッピングを設定できます。');

        $targetTypes = config('opsdeck.import_target_types');
        $suggestions = [];
        $mappingFieldOptions = [];
        $aiSuggestions = [];
        $aiMappingFeedbackToken = null;
        $aiMappingPlanAvailable = app(PlanFeatureService::class)->isAvailable($organization, 'ai_column_mapping');
        $aiMappingOrganizationEnabled = (bool) $organization->ai_enabled;
        $aiMappingMonthlyUsage = app(AiUsageBudgetService::class)->monthlySummary($organization);
        $aiApiKey = config('opsdeck.ai.api_key');
        $aiMappingServiceConfigured = (bool) config('opsdeck.ai.enabled')
            && config('opsdeck.ai.provider') === 'openai'
            && is_string($aiApiKey)
            && trim($aiApiKey) !== '';
        $aiTargetType = $request->string('ai_target_type')->toString();
        $suggestedTargetType = $request->string('target_type')->toString();
        if (! in_array($suggestedTargetType, $targetTypes, true)) {
            $suggestedTargetType = in_array($aiTargetType, $targetTypes, true) ? $aiTargetType : '';
        }
        $profileService = app(ImportProfileService::class);
        $sourceSignature = $profileService->sourceSignature($importBatch->selected_sheet_name, $importBatch->headers_json ?? [], (int) $importBatch->header_row_number, $importBatch->resolvedDataStartRowNumber(), $importBatch->resolvedHeaderEndRowNumber());
        $profiles = $profileService->matchingProfiles($organization, $sourceSignature);
        $targetTypesWithDifferentLayoutProfiles = array_values(array_diff(
            $profileService->targetTypesWithDifferentLayoutProfiles($organization, $importBatch->selected_sheet_name, $sourceSignature, $targetTypes),
            $profiles->pluck('target_type')->all(),
        ));
        $hasDifferentLayoutProfile = in_array($suggestedTargetType, $targetTypesWithDifferentLayoutProfiles, true);
        $selectedProfile = null;
        if ($request->filled('profile_id')) {
            $selectedProfile = $profiles->firstWhere('id', $request->integer('profile_id'));
            abort_if($selectedProfile === null, 404);
            $suggestedTargetType = $selectedProfile->target_type;
        }
        $initialColumnMap = $selectedProfile?->column_map ?? [];
        $normalizationSuggestions = [];
        foreach ($targetTypes as $targetType) {
            $aliases = config('opsdeck.import_column_aliases.'.$targetType, []);
            $suggestions[$targetType] = array_map(static fn (string $header): ?string => $aliases[trim($header)] ?? null, $importBatch->headers_json ?? []);
            $mappingFieldOptions[$targetType] = collect(array_merge(config('opsdeck.import_mapping_fields.'.$targetType, []), array_values($aliases)))
                ->filter(static fn (mixed $field): bool => is_string($field) && $field !== '')
                ->unique()
                ->map(static fn (string $field): array => [
                    'value' => $field,
                    'label' => config('opsdeck.import_field_labels_by_target.'.$targetType.'.'.$field, config('opsdeck.import_field_labels.'.$field, $field))
                        .(in_array($field, config('opsdeck.import_required_mapping_fields.'.$targetType, []), true) ? '（必須）' : ''),
                ])
                ->values()
                ->all();
        }
        if ($request->isMethod('POST') && $request->boolean('ai') && in_array($aiTargetType, $targetTypes, true)) {
            $this->authorize('update', $importBatch);
            $targetFields = array_values(array_unique(array_filter(array_merge(
                config('opsdeck.import_mapping_fields.'.$aiTargetType, []),
                array_values(config('opsdeck.import_column_aliases.'.$aiTargetType, [])),
            ), 'is_string')));
            $aiSuggestions = app(ImportMappingSuggestionService::class)->suggest($organization, $importBatch->headers_json ?? [], $aiTargetType, $targetFields, $request->user()->id);
            $aiCandidateSuggestions = [];
            foreach ($aiSuggestions as $header => $suggestion) {
                if (in_array($suggestion['source'] ?? null, ['ai', 'ai_escalated'], true) && is_string($suggestion['field'] ?? null)) {
                    $aiCandidateSuggestions[$this->aiMappingHeaderKey($header)] = $suggestion['field'];
                }
                $index = array_search($header, $importBatch->headers_json ?? [], true);
                if ($index !== false && $suggestions[$aiTargetType][$index] === null) {
                    $suggestions[$aiTargetType][$index] = $suggestion['field'];
                }
            }
            if ($aiCandidateSuggestions !== []) {
                $aiMappingFeedbackToken = bin2hex(random_bytes(32));
                $request->session()->put($this->aiMappingFeedbackSessionKey($organization, $importBatch, $aiMappingFeedbackToken), [
                    'user_id' => (int) $request->user()->id,
                    'target_type' => $aiTargetType,
                    'headers_fingerprint' => $this->aiMappingHeadersFingerprint($importBatch->headers_json ?? []),
                    'suggestions' => $aiCandidateSuggestions,
                ]);
            }
        }
        $normalizationTargetType = in_array($suggestedTargetType, $targetTypes, true)
            ? $suggestedTargetType
            : (in_array($aiTargetType, $targetTypes, true) ? $aiTargetType : ($targetTypes[0] ?? ''));
        $valueMappingSuggestions = [];
        if (in_array($normalizationTargetType, $targetTypes, true)) {
            $aliases = config('opsdeck.import_column_aliases.'.$normalizationTargetType, []);
            $columnMap = $initialColumnMap;
            foreach ($importBatch->headers_json ?? [] as $header) {
                if (! isset($columnMap[$header]) && isset($aliases[$header])) {
                    $columnMap[$header] = $aliases[$header];
                }
            }
            $mappingSampleRows = $this->sourceRowsForMapping($importBatch, $normalizationTargetType);
            $normalizationService = app(ImportNormalizationSuggestionService::class);
            $normalizationSuggestions = $normalizationService->suggest($normalizationTargetType, $importBatch->headers_json ?? [], $mappingSampleRows, $columnMap);
            $valueMappingSuggestions = $normalizationService->suggestValueMappings($normalizationTargetType, $mappingSampleRows, $columnMap);
        }
        $suggestionByField = collect($normalizationSuggestions)->keyBy('field');
        $normalizationRuleOptions = collect($targetTypes)->flatMap(function (string $targetType) use ($normalizationTargetType, $suggestionByField): array {
            $suggestionsForTarget = $targetType === $normalizationTargetType ? $suggestionByField : collect();

            return collect(config('opsdeck.import_normalization_rules.'.$targetType, []))
                ->map(fn (string $rule, string $field): array => [
                    'target_type' => $targetType,
                    'field' => $field,
                    'rule' => $rule,
                    'label' => config('opsdeck.import_normalization_rule_labels.'.$rule, $rule),
                    'choices' => collect(config('opsdeck.import_normalization_rule_options.'.$targetType.'.'.$field, [$rule]))
                        ->map(fn (string $choice): array => [
                            'rule' => $choice,
                            'label' => config('opsdeck.import_normalization_rule_labels.'.$choice, $choice),
                        ])
                        ->values()
                        ->all(),
                    'suggestion' => $suggestionsForTarget->get($field),
                ])
                ->values()
                ->all();
        });

        $valueMappingFields = collect($targetTypes)->mapWithKeys(function (string $targetType) use ($normalizationTargetType, $valueMappingSuggestions): array {
            return [$targetType => collect(config('opsdeck.import_value_mapping_fields.'.$targetType, []))
                ->map(fn (string $field): array => [
                    'field' => $field,
                    'label' => config('opsdeck.import_field_labels.'.$field, $field),
                    'suggestions' => $targetType === $normalizationTargetType ? ($valueMappingSuggestions[$field] ?? []) : [],
                ])
                ->values()
                ->all()];
        })->all();

        return view('imports.mapping', compact('importBatch', 'targetTypes', 'suggestions', 'mappingFieldOptions', 'aiTargetType', 'suggestedTargetType', 'aiSuggestions', 'aiMappingFeedbackToken', 'normalizationSuggestions', 'normalizationRuleOptions', 'normalizationTargetType', 'profiles', 'selectedProfile', 'initialColumnMap', 'valueMappingFields', 'hasDifferentLayoutProfile', 'targetTypesWithDifferentLayoutProfiles', 'aiMappingPlanAvailable', 'aiMappingOrganizationEnabled', 'aiMappingServiceConfigured', 'aiMappingMonthlyUsage'));
    }

    public function saveMapping(StoreImportMappingRequest $request, ImportBatch $importBatch): RedirectResponse
    {
        $importBatch = $this->scopedBatch($this->organization($request), $importBatch);
        $organization = $this->organization($request);
        $this->authorize('update', $importBatch);
        abort_if($importBatch->diff_source_batch_id !== null, 409, '差分Previewは前回Import時のマッピングを固定しています。列対応を変更する場合は通常のImport Previewを作成してください。');
        abort_unless(in_array($importBatch->status, ['preview', 'ready'], true), 422, 'Preview準備が完了してからマッピングを保存できます。');
        $targetType = $request->string('target_type')->toString();
        $columnMap = $request->validated('column_map');
        $normalizationRules = app(ImportNormalizationSuggestionService::class)->validateRules(
            $targetType,
            $columnMap,
            $request->validated('normalization_rules') ?? [],
        );
        $valueMappings = app(ImportNormalizationSuggestionService::class)->validateValueMappings(
            $targetType,
            $columnMap,
            $request->validated('value_mappings') ?? [],
        );
        $aiMappingFeedbackToken = $request->validated('ai_mapping_feedback_token');
        $aiMappingFeedbackSessionKey = is_string($aiMappingFeedbackToken)
            ? $this->aiMappingFeedbackSessionKey($organization, $importBatch, $aiMappingFeedbackToken)
            : null;
        $aiMappingFeedbackPayload = $aiMappingFeedbackSessionKey === null ? null : $request->session()->get($aiMappingFeedbackSessionKey);

        try {
            DB::transaction(function () use ($importBatch, $request, $organization, $targetType, $columnMap, $normalizationRules, $valueMappings, $aiMappingFeedbackPayload): void {
                $lockedBatch = ImportBatch::query()->whereKey($importBatch->id)->lockForUpdate()->firstOrFail();
                abort_unless(in_array($lockedBatch->status, ['preview', 'ready'], true), 409, 'このバッチはすでに処理中です。');
                $aiMappingFeedback = $this->aiMappingFeedbackForSubmission(
                    $aiMappingFeedbackPayload,
                    $lockedBatch,
                    $targetType,
                    (int) $request->user()->id,
                    $columnMap,
                );
                $profileService = app(ImportProfileService::class);
                $sourceSignature = $profileService->sourceSignature($lockedBatch->selected_sheet_name, $lockedBatch->headers_json ?? [], (int) $lockedBatch->header_row_number, $lockedBatch->resolvedDataStartRowNumber(), $lockedBatch->resolvedHeaderEndRowNumber());
                $profile = null;
                if ($request->filled('profile_id')) {
                    $profile = ImportProfile::query()
                        ->where('organization_id', $organization->id)
                        ->where('source_signature', $sourceSignature)
                        ->where('target_type', $targetType)
                        ->findOrFail((int) $request->validated('profile_id'));
                }

                if ($request->boolean('save_profile')) {
                    $profile = $profileService->saveVersion(
                        $organization,
                        $profile?->id,
                        $sourceSignature,
                        $lockedBatch->selected_sheet_name,
                        (int) $lockedBatch->header_row_number,
                        $lockedBatch->resolvedDataStartRowNumber(),
                        $lockedBatch->resolvedHeaderEndRowNumber(),
                        $lockedBatch->headers_json ?? [],
                        $targetType,
                        $request->string('profile_name')->toString(),
                        $columnMap,
                        $normalizationRules,
                        $valueMappings,
                        (int) $request->user()->id,
                    );
                }

                $mapping = $lockedBatch->mappings()->create([
                    'target_type' => $targetType,
                    'column_map' => $columnMap,
                    'normalization_rules' => $normalizationRules,
                    'value_mappings' => $valueMappings,
                    'import_profile_id' => $profile?->id,
                    'import_profile_version' => $profile?->version,
                    'ai_suggestion_count' => $aiMappingFeedback['reviewed'] > 0 ? $aiMappingFeedback['reviewed'] : null,
                    'ai_suggestion_accepted_count' => $aiMappingFeedback['reviewed'] > 0 ? $aiMappingFeedback['approved'] : null,
                    'status' => 'analyzing',
                    'created_by' => $request->user()->id,
                ]);
                $lockedBatch->update([
                    'mapping_id' => (string) $mapping->id,
                    'status' => 'analyzing',
                    'started_at' => now(),
                    'completed_at' => null,
                    'preview_new_rows' => 0,
                    'preview_updated_rows' => 0,
                    'preview_confirmation_rows' => 0,
                    'preview_error_rows' => 0,
                ]);
                PrepareImportMappingAnalysis::dispatch($lockedBatch->id, $mapping->id)->afterCommit();
            });
            if ($aiMappingFeedbackSessionKey !== null) {
                $request->session()->forget($aiMappingFeedbackSessionKey);
            }
        } catch (\Throwable $exception) {
            $this->markMappingAnalysisDispatchFailed($importBatch->id);
            throw $exception;
        }

        return to_route('imports.show', $importBatch)->with('status', '列マッピングを受け付けました。重複候補と件数をバックグラウンドで分析します。');
    }

    /** @return array{reviewed: int, approved: int} */
    private function aiMappingFeedbackForSubmission(mixed $payload, ImportBatch $importBatch, string $targetType, int $userId, array $columnMap): array
    {
        if (! is_array($payload)
            || ($payload['user_id'] ?? null) !== $userId
            || ($payload['target_type'] ?? null) !== $targetType
            || ! is_string($payload['headers_fingerprint'] ?? null)
            || ! hash_equals($this->aiMappingHeadersFingerprint($importBatch->headers_json ?? []), $payload['headers_fingerprint'])
            || ! is_array($payload['suggestions'] ?? null)) {
            return ['reviewed' => 0, 'approved' => 0];
        }

        $suggestions = $payload['suggestions'];
        $submittedFieldsByHeaderKey = [];
        foreach ($columnMap as $header => $field) {
            if (is_string($header) && is_string($field)) {
                $submittedFieldsByHeaderKey[$this->aiMappingHeaderKey($header)] = $field;
            }
        }

        $approved = 0;
        foreach ($suggestions as $headerKey => $suggestedField) {
            if (is_string($headerKey) && is_string($suggestedField) && ($submittedFieldsByHeaderKey[$headerKey] ?? null) === $suggestedField) {
                $approved++;
            }
        }

        return ['reviewed' => count($suggestions), 'approved' => $approved];
    }

    private function aiMappingFeedbackSessionKey(Organization $organization, ImportBatch $importBatch, string $token): string
    {
        return 'opsdeck.ai_mapping_feedback.'.$organization->id.'.'.$importBatch->id.'.'.$token;
    }

    private function aiMappingHeaderKey(string $header): string
    {
        return hash_hmac('sha256', $header, (string) config('app.key'));
    }

    private function aiMappingHeadersFingerprint(array $headers): string
    {
        return hash_hmac('sha256', json_encode(array_values($headers), JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function reviewCandidate(ReviewImportCandidateRequest $request, ImportBatch $importBatch, ImportReviewCandidate $candidate): RedirectResponse
    {
        $importBatch = $this->scopedBatch($this->organization($request), $importBatch);
        $this->authorize('update', $importBatch);
        abort_unless($candidate->import_batch_id === $importBatch->id, 404);
        $status = $request->validated('status');

        DB::transaction(function () use ($request, $importBatch, $candidate, $status): void {
            $lockedCandidate = ImportReviewCandidate::query()
                ->where('import_batch_id', $importBatch->id)
                ->lockForUpdate()
                ->findOrFail($candidate->id);
            $lockedCandidate->update(['status' => $status, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

            AuditLog::query()->create([
                'organization_id' => $importBatch->organization_id,
                'actor_user_id' => $request->user()->id,
                'entity_type' => ImportReviewCandidate::class,
                'entity_id' => $lockedCandidate->id,
                'action' => AuditLog::ImportCandidateReviewed,
                'after_json' => ['status' => $status],
            ]);
        });

        return to_route('imports.show', $importBatch)->with('status', '重複候補の判断を保存しました。Importの自動統合は行っていません。');
    }

    public function updateRowSelection(UpdateImportRowSelectionRequest $request, ImportBatch $importBatch): RedirectResponse
    {
        $organization = $this->organization($request);
        $importBatch = $this->scopedBatch($organization, $importBatch);
        $this->authorize('update', $importBatch);
        $selected = array_map('intval', $request->validated('selected_row_numbers', []));
        $visible = array_map('intval', $request->validated('visible_row_numbers', []));

        DB::transaction(function () use ($importBatch, $organization, $selected, $visible): void {
            $lockedBatch = ImportBatch::query()
                ->whereKey($importBatch->id)
                ->where('organization_id', $organization->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(in_array($lockedBatch->status, ['preview', 'ready'], true), 422, 'Preview中またはready状態のバッチだけ行選択を変更できます。');

            if ($visible === []) {
                return;
            }

            $selectableActions = ['new_candidate', 'update_candidate', 'confirmation_required', 'preview'];
            $lockedBatch->rowResults()
                ->whereIn('action', $selectableActions)
                ->whereIn('source_row_number', $visible)
                ->update(['is_selected' => false, 'updated_at' => now()]);

            if ($selected !== []) {
                $lockedBatch->rowResults()
                    ->whereIn('action', $selectableActions)
                    ->whereIn('source_row_number', $visible)
                    ->whereIn('source_row_number', $selected)
                    ->update(['is_selected' => true, 'updated_at' => now()]);
            }
        });

        $redirectParameters = ['importBatch' => $importBatch];
        $rowResultsPage = $request->integer('row_results_page', 1);
        if ($rowResultsPage > 1) {
            $redirectParameters['row_results_page'] = $rowResultsPage;
        }

        return to_route('imports.show', $redirectParameters)->with('status', 'Import対象行を更新しました。');
    }

    public function execute(ExecuteImportBatchRequest $request, ImportBatch $importBatch): RedirectResponse
    {
        $organization = $this->organization($request);
        $importBatch = $this->scopedBatch($organization, $importBatch);
        $this->authorize('update', $importBatch);
        $this->assertImportSourceFeatureAvailable($organization, $importBatch->source_type);
        $this->assertFeatureAvailable($organization, 'import_execution', 'このプランではCSVデータ取込を利用できません。');
        abort_unless($importBatch->status === 'ready', 422, 'ready状態のバッチだけ実行できます。');
        abort_unless(($importBatch->source_type === 'google_sheets' && $importBatch->source_external_id !== null) || (in_array($importBatch->source_type, ['csv', 'xlsx'], true) && $importBatch->source_file_path !== null), 422, '実行可能なImportファイルがありません。');
        $mapping = $importBatch->mappings()->where('status', 'ready')->latest()->firstOrFail();
        abort_if($importBatch->reviewCandidates()->whereIn('status', ['pending', 'deferred'])->exists(), 422, '重複候補の判断が残っているため、Importを実行できません。');
        abort_unless(in_array($mapping->target_type, ['employees', 'departments', 'assets', 'asset_assignments', 'saas_services', 'saas_assignments', 'contracts', 'security_controls', 'evidence', 'questionnaire_answers'], true), 422, 'このデータ種別はImport実行に対応していません。');
        $this->assertImportTargetFeatureAvailable($organization, $mapping->target_type);
        $asyncThreshold = max(1, (int) config('opsdeck.import.async_threshold_rows'));
        if ($importBatch->total_rows >= $asyncThreshold) {
            app(ImportBatchExecutionService::class)->assertNoSelectedTemplateSampleRows($importBatch, $mapping);

            try {
                DB::transaction(function () use ($importBatch, $organization, $request): void {
                    $queued = ImportBatch::query()
                        ->whereKey($importBatch->id)
                        ->where('organization_id', $organization->id)
                        ->where('status', 'ready')
                        ->update(['status' => 'queued', 'executed_by' => $importBatch->executed_by ?? $request->user()->id, 'updated_at' => now()]) === 1;
                    abort_unless($queued, 409, 'このImportはすでに実行中、または実行済みです。');

                    ExecuteImportBatch::dispatch($importBatch->id, $request->user()->id)->afterCommit();
                });
            } catch (\Throwable $exception) {
                ImportBatch::query()->whereKey($importBatch->id)->where('status', 'queued')->update(['status' => 'failed', 'completed_at' => now()]);

                throw $exception;
            }

            return to_route('imports.show', $importBatch)->with('status', '取込を受け付けました。処理が始まるまで順番待ちです。状態が変わらない場合は管理者にお問い合わせください。重ねて実行しないでください。');
        }

        app(ImportBatchExecutionService::class)->execute($importBatch->id, $request->user()->id, 'ready');

        return to_route('imports.show', $importBatch->refresh())->with('status', 'ファイルを取り込みました。');
    }

    private function duplicateKey(string $targetType, array $values): ?string
    {
        return app(ImportBatchExecutionService::class)->duplicateKey($targetType, $values);
    }

    private function normalizeImportValues(string $targetType, array $values): array
    {
        return app(ImportBatchExecutionService::class)->normalizeImportValues($targetType, $values);
    }

    public function rollback(RollbackImportBatchRequest $request, ImportBatch $importBatch): RedirectResponse
    {
        $organization = $this->organization($request);
        $importBatch = $this->scopedBatch($organization, $importBatch);
        $this->authorize('update', $importBatch);
        abort_unless($importBatch->status === 'completed', 422, 'completed状態のバッチだけ取消できます。');
        $rollbackRows = $importBatch->rowResults()->whereIn('action', ['inserted', 'updated'])->count();
        if ($rollbackRows >= max(1, (int) config('opsdeck.import.async_threshold_rows'))) {
            $markedRollingBack = false;

            try {
                DB::transaction(function () use ($organization, $importBatch, $request, &$markedRollingBack): void {
                    $lockedBatch = ImportBatch::query()
                        ->where('organization_id', $organization->id)
                        ->whereKey($importBatch->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    abort_unless($lockedBatch->status === 'completed', 409, 'このImportはすでに取消処理中です。');
                    $lockedBatch->update(['status' => 'rolling_back', 'failure_message' => null]);
                    $markedRollingBack = true;
                    RollbackImportBatch::dispatch((int) $lockedBatch->id, (int) $request->user()->id)->afterCommit();
                });
            } catch (\Throwable $exception) {
                if ($markedRollingBack) {
                    ImportBatch::query()
                        ->where('organization_id', $organization->id)
                        ->whereKey($importBatch->id)
                        ->where('status', 'rolling_back')
                        ->update([
                            'status' => 'completed',
                            'failure_message' => 'Importの取消を受け付けられませんでした。現在のデータを確認してから再試行してください。',
                        ]);
                }

                throw $exception;
            }

            return to_route('imports.show', $importBatch)->with('status', '大容量Importの取消を受け付けました。処理中はこの画面を更新して状態を確認してください。');
        }

        try {
            DB::transaction(function () use ($organization, $importBatch, $request): void {
                $lockedBatch = ImportBatch::query()
                    ->where('organization_id', $organization->id)
                    ->whereKey($importBatch->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                abort_unless($lockedBatch->status === 'completed', 409, 'このImportはすでに取消処理中です。');
                $lockedBatch->update(['status' => 'rolling_back', 'failure_message' => null]);
                app(ImportBatchRollbackService::class)->rollback((int) $lockedBatch->id, (int) $request->user()->id);
            });
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            return to_route('imports.show', $importBatch)->withErrors(['rollback' => $exception->getMessage()]);
        }

        return to_route('imports.show', $importBatch)->with('status', 'Importを安全に取消しました。');
    }

    public function retry(RetryImportBatchRequest $request, ImportBatch $importBatch): RedirectResponse
    {
        $organization = $this->organization($request);
        $importBatch = $this->scopedBatch($organization, $importBatch);
        $this->authorize('update', $importBatch);
        abort_unless($importBatch->status === 'failed', 422, 'failed状態のバッチだけ再実行できます。');
        $this->assertImportSourceFeatureAvailable($organization, $importBatch->source_type);
        if ($importBatch->diff_source_batch_id !== null) {
            $diffSourceBatch = ImportBatch::query()->where('organization_id', $organization->id)->whereKey($importBatch->diff_source_batch_id)->firstOrFail();
            DB::transaction(function () use ($importBatch, $request): void {
                $lockedBatch = ImportBatch::query()->whereKey($importBatch->id)->lockForUpdate()->firstOrFail();
                abort_unless($lockedBatch->status === 'failed' && $lockedBatch->diff_source_batch_id !== null, 409, 'この差分Previewはすでに再試行中です。');
                $sourceBatchId = (int) $lockedBatch->diff_source_batch_id;
                $lockedBatch->rowResults()->delete();
                $lockedBatch->mappings()->delete();
                $lockedBatch->reviewCandidates()->delete();
                $lockedBatch->update([
                    'mapping_id' => null,
                    'headers_json' => null,
                    'total_rows' => 0,
                    'status' => 'previewing',
                    'started_at' => now(),
                    'completed_at' => null,
                    'executed_by' => $request->user()->id,
                ]);
                $sourceType = ImportBatch::query()->whereKey($sourceBatchId)->value('source_type');
                if ($sourceType === 'google_sheets') {
                    PrepareGoogleSheetDiffPreview::dispatch($sourceBatchId, $lockedBatch->id)->afterCommit();
                } else {
                    PrepareImportFileDiffPreview::dispatch($sourceBatchId, $lockedBatch->id)->afterCommit();
                }
            });

            return to_route('imports.show', $importBatch)->with('status', '差分Previewの再試行を受け付けました。');
        }
        $failedMapping = $importBatch->mapping_id === null
            ? null
            : $importBatch->mappings()->whereKey($importBatch->mapping_id)->where('status', 'failed')->first();
        if ($failedMapping !== null) {
            DB::transaction(function () use ($importBatch, $failedMapping): void {
                $lockedBatch = ImportBatch::query()->whereKey($importBatch->id)->lockForUpdate()->firstOrFail();
                $lockedMapping = $lockedBatch->mappings()->whereKey($failedMapping->id)->lockForUpdate()->firstOrFail();
                abort_unless($lockedBatch->status === 'failed' && $lockedMapping->status === 'failed' && (string) $lockedBatch->mapping_id === (string) $lockedMapping->id, 409, 'このマッピング分析はすでに再試行中です。');

                $lockedMapping->update(['status' => 'analyzing']);
                $lockedBatch->update([
                    'status' => 'analyzing',
                    'started_at' => now(),
                    'completed_at' => null,
                    'preview_new_rows' => 0,
                    'preview_updated_rows' => 0,
                    'preview_confirmation_rows' => 0,
                    'preview_error_rows' => 0,
                ]);
                PrepareImportMappingAnalysis::dispatch($lockedBatch->id, $lockedMapping->id)->afterCommit();
            });

            return to_route('imports.show', $importBatch)->with('status', 'マッピング分析の再試行を受け付けました。');
        }
        $hasMappings = $importBatch->mappings()->exists();
        $hasPreviewSource = $importBatch->source_file_path !== null || $importBatch->source_external_id !== null;
        if (! $hasMappings && $hasPreviewSource && in_array($importBatch->source_type, ['csv', 'xlsx', 'google_sheets'], true)) {
            DB::transaction(function () use ($importBatch, $request): void {
                $lockedBatch = ImportBatch::query()->whereKey($importBatch->id)->lockForUpdate()->firstOrFail();
                abort_unless($lockedBatch->status === 'failed', 409, 'このPreviewはすでに再試行中です。');
                $lockedBatch->rowResults()->delete();
                $lockedBatch->reviewCandidates()->delete();
                $lockedBatch->update([
                    'headers_json' => null,
                    'total_rows' => 0,
                    'error_rows' => 0,
                    'status' => 'previewing',
                    'started_at' => now(),
                    'completed_at' => null,
                    'errors_reviewed_at' => null,
                    'errors_reviewed_by' => null,
                    'failure_message' => null,
                    'executed_by' => $request->user()->id,
                ]);
                PrepareImportPreview::dispatch($lockedBatch->id)->afterCommit();
            });

            return to_route('imports.show', $importBatch)->with('status', 'Previewの再試行を受け付けました。処理状況はこの画面で確認できます。');
        }

        $this->assertFeatureAvailable($organization, 'import_execution', 'このプランではCSVデータ取込を利用できません。');
        $asyncThreshold = max(1, (int) config('opsdeck.import.async_threshold_rows'));
        if ($importBatch->total_rows >= $asyncThreshold) {
            $retryBatchId = null;

            try {
                $retryBatch = DB::transaction(function () use ($importBatch, $organization, $request, &$retryBatchId): ImportBatch {
                    $sourceBatch = ImportBatch::query()
                        ->where('organization_id', $organization->id)
                        ->whereKey($importBatch->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    abort_unless($sourceBatch->status === 'failed', 409, 'このImportはすでに再試行中です。');

                    $sourceBatch->update(['status' => 'retrying']);
                    $retryBatch = $sourceBatch->replicate(['created_at', 'updated_at']);
                    $retryBatch->status = 'retrying';
                    $retryBatch->mapping_id = null;
                    $retryBatch->total_rows = $sourceBatch->total_rows;
                    $retryBatch->inserted_rows = 0;
                    $retryBatch->updated_rows = 0;
                    $retryBatch->skipped_rows = 0;
                    $retryBatch->error_rows = 0;
                    $retryBatch->started_at = now();
                    $retryBatch->completed_at = null;
                    $retryBatch->failure_message = null;
                    $retryBatch->executed_by = $request->user()->id;
                    $retryBatch->save();
                    $retryBatchId = (int) $retryBatch->id;

                    PrepareImportRetryBatch::dispatch($sourceBatch->id, $retryBatch->id, $request->user()->id)->afterCommit();

                    return $retryBatch;
                });
            } catch (\Throwable $exception) {
                if ($retryBatchId !== null) {
                    ImportBatch::query()->whereKey($importBatch->id)->where('status', 'retrying')->update(['status' => 'failed']);
                    ImportBatch::query()->whereKey($retryBatchId)->where('status', 'retrying')->update(['status' => 'failed', 'failure_message' => '再試行の受付に失敗しました。', 'completed_at' => now()]);
                }

                throw $exception;
            }

            return to_route('imports.show', $retryBatch)->with('status', '大容量Importの再試行準備を受け付けました。準備中はこの画面を更新して状態を確認してください。');
        }

        $retryBatch = app(PrepareImportRetryService::class)->createSynchronously($importBatch, $request->user()->id);

        return to_route('imports.show', $retryBatch)->with('status', '再実行用のImportバッチを作成しました。');
    }

    public function reviewErrors(ResolveImportBatchErrorsRequest $request, ImportBatch $importBatch): RedirectResponse
    {
        $organization = $this->organization($request);
        $importBatch = $this->scopedBatch($organization, $importBatch);
        $this->authorize('update', $importBatch);

        DB::transaction(function () use ($importBatch, $organization, $request): void {
            $lockedBatch = ImportBatch::query()
                ->where('organization_id', $organization->id)
                ->whereKey($importBatch->id)
                ->lockForUpdate()
                ->firstOrFail();
            $hasRowErrors = in_array($lockedBatch->status, ['completed', 'cancelled'], true) && $lockedBatch->error_rows > 0;
            abort_unless($lockedBatch->status === 'failed' || $hasRowErrors, 422, '確認対象のImportエラーまたは実行失敗がありません。');
            abort_if($lockedBatch->errors_reviewed_at !== null, 409, 'このImportエラーはすでに確認済みです。');

            $lockedBatch->update(['errors_reviewed_at' => now(), 'errors_reviewed_by' => $request->user()->id]);
            AuditLog::query()->create([
                'organization_id' => $organization->id,
                'actor_user_id' => $request->user()->id,
                'entity_type' => ImportBatch::class,
                'entity_id' => $lockedBatch->id,
                'action' => AuditLog::ImportErrorsReviewed,
                'after_json' => ['status_reviewed' => $lockedBatch->status, 'error_rows_reviewed' => $lockedBatch->error_rows],
            ]);
        });

        return to_route('imports.show', $importBatch)->with('status', 'Importエラーまたは実行失敗を確認済みとして記録しました。');
    }

    private function organization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');

        return $organization;
    }

    private function cleanupFailedImportUpload(string $disk, string $path): void
    {
        try {
            if (Storage::disk($disk)->delete($path)) {
                return;
            }
        } catch (\Throwable) {
        }

        try {
            Log::warning('Import upload cleanup failed after batch preparation failed.');
        } catch (\Throwable) {
        }
    }

    private function markPreviewDispatchFailed(int $importBatchId): void
    {
        ImportBatch::query()
            ->whereKey($importBatchId)
            ->where('status', 'previewing')
            ->update(['status' => 'failed', 'completed_at' => now()]);
    }

    private function markMappingAnalysisDispatchFailed(int $importBatchId): void
    {
        DB::transaction(function () use ($importBatchId): void {
            $importBatch = ImportBatch::query()->whereKey($importBatchId)->where('status', 'analyzing')->lockForUpdate()->first();
            if ($importBatch === null) {
                return;
            }

            $importBatch->mappings()->whereKey($importBatch->mapping_id)->where('status', 'analyzing')->update(['status' => 'failed', 'updated_at' => now()]);
            $importBatch->update(['status' => 'failed', 'completed_at' => now()]);
        });
    }

    /** @return array{xlsx_preview: bool, google_sheets: bool, import_execution: bool} */
    private function importCapabilities(Organization $organization): array
    {
        $features = app(PlanFeatureService::class);

        return [
            'xlsx_preview' => $features->isAvailable($organization, 'xlsx_preview'),
            'google_sheets' => $features->isAvailable($organization, 'google_sheets'),
            'import_execution' => $features->isAvailable($organization, 'import_execution'),
        ];
    }

    private function assertFeatureAvailable(Organization $organization, string $feature, string $message): void
    {
        app(PlanFeatureService::class)->assertAvailable($organization, $feature, $message);
    }

    private function assertImportSourceFeatureAvailable(Organization $organization, string $sourceType): void
    {
        if ($sourceType === 'xlsx') {
            $this->assertFeatureAvailable($organization, 'xlsx_preview', 'XLSXプレビューはPremiumプランで利用できます。');
        } elseif ($sourceType === 'google_sheets') {
            $this->assertFeatureAvailable($organization, 'google_sheets', 'Google Sheets連携はPremiumプランで利用できます。');
        }
    }

    private function assertImportTargetFeatureAvailable(Organization $organization, string $targetType): void
    {
        $feature = match ($targetType) {
            'security_controls' => 'security_controls',
            'evidence' => 'evidence',
            'questionnaire_answers' => 'questionnaires',
            default => null,
        };
        if ($feature !== null) {
            $this->assertFeatureAvailable($organization, $feature, '選択したデータ種別は現在のプランで利用できません。');
        }
    }

    /** @return list<array<string, string|int>> */
    private function sourceRowsForMapping(ImportBatch $importBatch, ?string $targetType = null): array
    {
        app(ImportSourceIntegrityService::class)->assertUnchanged($importBatch);
        $sampleRowLimit = max(1, (int) config('opsdeck.import.normalization_sample_rows'));
        $rows = [];
        $collectSampleRow = static function (array $row, array $headers) use (&$rows, $sampleRowLimit): void {
            if (count($rows) >= $sampleRowLimit) {
                return;
            }

            $sourceValues = ['__source_row_number' => (int) ($row['source_row_number'] ?? 0)];
            foreach ($headers as $columnIndex => $header) {
                $sourceValues[$header] = (string) ($row['values'][$columnIndex] ?? '');
            }
            $rows[] = $sourceValues;
        };

        if ($importBatch->source_type === 'xlsx' && $importBatch->source_file_path !== null) {
            app(ImportFileLocalStager::class)->withLocalCopy(
                $importBatch->sourceStorageDiskName(),
                $importBatch->source_file_path,
                fn (string $localPath): array => app(XlsxImportSource::class)->parseEach(
                    $localPath,
                    $importBatch->selected_sheet_name,
                    $collectSampleRow,
                    $sampleRowLimit,
                    headerRowNumber: (int) $importBatch->header_row_number,
                    dataStartRowNumber: $importBatch->resolvedDataStartRowNumber(),
                    headerEndRowNumber: $importBatch->resolvedHeaderEndRowNumber(),
                ),
            );
        } elseif ($importBatch->source_type === 'google_sheets' && $importBatch->source_external_id !== null && $importBatch->selected_sheet_name !== null) {
            $connection = GoogleSheetConnection::query()->where('organization_id', $importBatch->organization_id)->first();
            if ($connection === null) {
                return [];
            }
            app(GoogleSheetsImportSource::class)->parseEach(
                $connection,
                $importBatch->source_external_id,
                $importBatch->selected_sheet_name,
                $collectSampleRow,
                $targetType === 'contracts',
                $sampleRowLimit,
                headerRowNumber: (int) $importBatch->header_row_number,
                dataStartRowNumber: $importBatch->resolvedDataStartRowNumber(),
                headerEndRowNumber: $importBatch->resolvedHeaderEndRowNumber(),
            );
        } elseif ($importBatch->source_type === 'csv' && $importBatch->source_file_path !== null) {
            $disk = Storage::disk($importBatch->sourceStorageDiskName());
            if (! $disk->exists($importBatch->source_file_path)) {
                return [];
            }
            $sourceStream = $disk->readStream($importBatch->source_file_path);
            if (! is_resource($sourceStream)) {
                throw new \RuntimeException('CSVを読み込めません。');
            }

            try {
                app(CsvImportSource::class)->parseEachStream(
                    $sourceStream,
                    $collectSampleRow,
                    $sampleRowLimit,
                    headerRowNumber: (int) $importBatch->header_row_number,
                    dataStartRowNumber: $importBatch->resolvedDataStartRowNumber(),
                    headerEndRowNumber: $importBatch->resolvedHeaderEndRowNumber(),
                );
            } finally {
                fclose($sourceStream);
            }
        } else {
            return [];
        }

        return $rows;
    }

    /** @param list<array<string, string|int>> $rows */
    private function createDuplicateCandidates(ImportBatch $importBatch, string $targetType, array $columnMap, array $rows): void
    {
        $firstRows = [];
        $existingKeyHashes = [];
        foreach ($importBatch->reviewCandidates()->where('candidate_type', 'duplicate')->get(['details_json']) as $candidate) {
            $keyHash = $candidate->details_json['key_hash'] ?? null;
            if (is_string($keyHash) && $keyHash !== '') {
                $existingKeyHashes[$keyHash] = true;
            }
        }

        foreach ($rows as $row) {
            $values = [];
            foreach ($columnMap as $sourceHeader => $targetField) {
                if (is_string($targetField) && $targetField !== '' && isset($row[$sourceHeader])) {
                    $values[$targetField] = trim((string) $row[$sourceHeader]);
                }
            }
            $key = $this->duplicateKey($targetType, $this->normalizeImportValues($targetType, $values));
            if ($key === null) {
                continue;
            }
            $sourceRow = (int) ($row['__source_row_number'] ?? 0);
            if ($sourceRow < 1) {
                continue;
            }
            if (! isset($firstRows[$key])) {
                $firstRows[$key] = $sourceRow;

                continue;
            }
            $keyHash = hash('sha256', $key);
            if (! isset($existingKeyHashes[$keyHash])) {
                $importBatch->reviewCandidates()->create(['candidate_type' => 'duplicate', 'source_row_numbers_json' => [$firstRows[$key], $sourceRow], 'confidence' => 0.94, 'details_json' => ['target_type' => $targetType, 'key_hash' => $keyHash], 'status' => 'pending']);
                $existingKeyHashes[$keyHash] = true;
            }
        }
    }

    /** @param list<array<string, string|int>> $rows */
    private function updatePreviewCounts(ImportBatch $importBatch, string $targetType, array $columnMap, array $rows): void
    {
        $counts = ['new' => 0, 'updated' => 0, 'confirmation' => 0, 'error' => 0];
        $seen = [];
        $mappedRows = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($columnMap as $sourceHeader => $targetField) {
                if (is_string($targetField) && $targetField !== '' && isset($row[$sourceHeader])) {
                    $values[$targetField] = trim((string) $row[$sourceHeader]);
                }
            }
            $values = $this->normalizeImportValues($targetType, $values);
            $mappedRows[] = [
                'source_row_number' => (int) ($row['__source_row_number'] ?? 0),
                'values' => $values,
                'duplicate_key' => $this->duplicateKey($targetType, $values),
            ];
        }

        $existingTargetHashes = $this->existingImportTargetHashes($importBatch, $targetType, $mappedRows);
        $rowResultIds = $importBatch->rowResults()
            ->select(['id', 'source_row_number'])
            ->orderByDesc('id')
            ->get()
            ->unique('source_row_number')
            ->keyBy('source_row_number');
        $rowResultUpdates = [];
        $timestamp = now();
        foreach ($mappedRows as $mappedRow) {
            $values = $mappedRow['values'];
            $key = $mappedRow['duplicate_key'];
            $sourceRowNumber = $mappedRow['source_row_number'];
            $previewAction = 'new_candidate';
            $previewErrors = null;
            if ($this->previewRowHasError($targetType, $values)) {
                $counts['error']++;
                $previewAction = 'error';
                $previewErrors = ['row' => $this->hasInvalidImportDateValues($targetType, $values)
                    ? 'date fields are invalid or out of order'
                    : '入力値または関連データを確認してください'];
            } elseif ($key !== null && isset($seen[$key])) {
                $counts['confirmation']++;
                $previewAction = 'confirmation_required';

            } else {
                if ($key !== null) {
                    $seen[$key] = true;
                }
                $targetHash = $this->importTargetIdentityHash($targetType, $values);
                if ($targetHash !== null && isset($existingTargetHashes[$targetHash])) {
                    $counts['updated']++;
                    $previewAction = 'update_candidate';
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
                    'action' => $previewAction,
                    'after_json' => json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'errors_json' => $previewErrors === null ? null : json_encode($previewErrors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'updated_at' => $timestamp,
                ];
            }
        }

        $chunkSize = max(1, (int) config('opsdeck.import.preview_insert_chunk_size'));
        foreach (array_chunk($rowResultUpdates, $chunkSize) as $rowResultChunk) {
            ImportRowResult::query()->upsert($rowResultChunk, ['id'], ['target_type', 'action', 'after_json', 'errors_json', 'updated_at']);
        }

        $importBatch->update(['preview_new_rows' => $counts['new'], 'preview_updated_rows' => $counts['updated'], 'preview_confirmation_rows' => $counts['confirmation'], 'preview_error_rows' => $counts['error']]);
    }

    /**
     * @param  list<array{source_row_number: int, values: array<string, string>, duplicate_key: ?string}>  $mappedRows
     * @return array<string, true>
     */
    private function existingImportTargetHashes(ImportBatch $importBatch, string $targetType, array $mappedRows): array
    {
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
                $records = Contract::query()
                    ->where('organization_id', $importBatch->organization_id)
                    ->where(function (Builder $query) use ($identityChunk): void {
                        foreach ($identityChunk as [$vendorName, $contractName]) {
                            $query->orWhere(function (Builder $pairQuery) use ($vendorName, $contractName): void {
                                $pairQuery->where('vendor_name', $vendorName)->where('contract_name', $contractName);
                            });
                        }
                    })
                    ->get(['vendor_name', 'contract_name']);
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
                    'employees' => Employee::query()->where('organization_id', $importBatch->organization_id)->whereIn($column, $identifiers)->get([$column]),
                    'departments' => Department::query()->where('organization_id', $importBatch->organization_id)->whereIn($column, $identifiers)->get([$column]),
                    'assets' => Asset::query()->where('organization_id', $importBatch->organization_id)->whereIn($column, $identifiers)->get([$column]),
                    'saas_services' => SaasService::query()->where('organization_id', $importBatch->organization_id)->whereIn($column, $identifiers)->get([$column]),
                    'security_controls' => SecurityControl::query()->where('organization_id', $importBatch->organization_id)->whereIn($column, $identifiers)->get([$column]),
                    default => collect(),
                };
            }

            foreach ($records as $record) {
                $values = $targetType === 'contracts'
                    ? ['vendor_name' => $record->vendor_name, 'contract_name' => $record->contract_name]
                    : [$column => $record->{$column}];
                $hash = $this->importTargetIdentityHash($targetType, $values);
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

    private function previewRowHasError(string $targetType, array $values): bool
    {
        if ($this->hasInvalidImportDateValues($targetType, $values)) {
            return true;
        }

        return match ($targetType) {
            'employees' => ($values['name'] ?? '') === '' || filter_var($values['email'] ?? '', FILTER_VALIDATE_EMAIL) === false,
            'departments' => ($values['name'] ?? '') === '',
            'assets' => ($values['asset_number'] ?? '') === '' || ! in_array($values['asset_type'] ?? '', config('opsdeck.asset_types'), true),
            'saas_services' => ($values['name'] ?? '') === '' || ! in_array($values['status'] ?? '', config('opsdeck.saas_service_statuses'), true),
            'contracts' => $this->contractPreviewHasError($values),
            'security_controls' => $this->securityControlPreviewHasError($values),
            default => false,
        };
    }

    private function securityControlPreviewHasError(array $values): bool
    {
        if (($values['control_code'] ?? '') === ''
            || ($values['title'] ?? '') === ''
            || ($values['category'] ?? '') === ''
            || ($values['description'] ?? '') === ''
            || ! in_array($values['status'] ?? '', config('opsdeck.security_control_statuses'), true)) {
            return true;
        }

        return false;
    }

    /** @param array<string, mixed> $values */
    private function contractPreviewHasError(array $values): bool
    {
        return ($values['vendor_name'] ?? '') === ''
            || ($values['contract_name'] ?? '') === ''
            || ! in_array($values['billing_cycle'] ?? '', config('opsdeck.contract_billing_cycles'), true)
            || ! in_array($values['status'] ?? '', config('opsdeck.contract_statuses'), true)
            || ! app(ContractAmountValidator::class)->isValid($values['amount'] ?? null);
    }

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

    private function scopedBatch(Organization $organization, ImportBatch $importBatch): ImportBatch
    {
        abort_unless($importBatch->organization_id === $organization->id, 404);

        return $importBatch;
    }
}
