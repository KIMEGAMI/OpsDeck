<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportBatch\ExecuteImportBatchRequest;
use App\Http\Requests\ImportBatch\ResolveImportBatchErrorsRequest;
use App\Http\Requests\ImportBatch\RetryImportBatchRequest;
use App\Http\Requests\ImportBatch\ReviewImportCandidateRequest;
use App\Http\Requests\ImportBatch\RollbackImportBatchRequest;
use App\Http\Requests\ImportBatch\SelectGoogleSpreadsheetRequest;
use App\Http\Requests\ImportBatch\SelectImportSheetRequest;
use App\Http\Requests\ImportBatch\StoreImportBatchRequest;
use App\Http\Requests\ImportBatch\StoreImportMappingRequest;
use App\Http\Requests\ImportBatch\UpdateImportRowSelectionRequest;
use App\Jobs\ExecuteImportBatch;
use App\Jobs\PrepareGoogleSheetDiffPreview;
use App\Jobs\PrepareImportMappingAnalysis;
use App\Jobs\PrepareImportPreview;
use App\Jobs\PrepareImportRetryBatch;
use App\Jobs\RollbackImportBatch;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Evidence;
use App\Models\GoogleSheetConnection;
use App\Models\ImportBatch;
use App\Models\ImportReviewCandidate;
use App\Models\ImportRowResult;
use App\Models\Organization;
use App\Models\Questionnaire;
use App\Models\QuestionnaireAnswer;
use App\Models\SaasAssignment;
use App\Models\SaasService;
use App\Models\SecurityControl;
use App\Services\Ai\ImportMappingSuggestionService;
use App\Services\ContractAmountValidator;
use App\Services\Import\CsvImportSource;
use App\Services\Import\GoogleSheetsImportSource;
use App\Services\Import\ImportNormalizationSuggestionService;
use App\Services\Import\XlsxImportSource;
use App\Services\ImportBatchExecutionService;
use App\Services\ImportBatchRollbackService;
use App\Services\PlanFeatureService;
use App\Services\PlanLimitService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        return view('imports.create', compact('organization', 'capabilities', 'canManageBilling'));
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
        $targetLabel = match ($targetType) {
            'employees' => '社員',
            'assets' => 'IT資産',
            'contracts' => '契約',
            'saas_services' => 'SaaS',
            default => $targetType,
        };
        $columns = [];
        foreach ($sample['headers'] as $index => $header) {
            $columns[] = [
                'header' => $header,
                'field' => $aliases[$header] ?? null,
                'value' => $sample['row'][$index] ?? '',
            ];
        }

        return view('imports.samples.preview', compact('sample', 'targetLabel', 'targetType', 'columns'));
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
        DB::transaction(function () use ($importBatch, $request): void {
            $claimed = ImportBatch::query()->whereKey($importBatch->id)->where('status', 'sheet_selection')->update([
                'selected_sheet_name' => $request->string('sheet_name')->toString(),
                'headers_json' => null,
                'status' => 'previewing',
                'total_rows' => 0,
                'failure_message' => null,
                'started_at' => now(),
                'completed_at' => null,
            ]);
            abort_unless($claimed === 1, 409, 'このシート選択はすでに処理されています。');
            PrepareImportPreview::dispatch($importBatch->id)->afterCommit();
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
        $disk = config('opsdeck.import.disk');
        $file = $request->file('file');
        if (strtolower($file->getClientOriginalExtension()) === 'xlsx') {
            $this->assertFeatureAvailable($organization, 'xlsx_preview', 'XLSXプレビューはPremiumプランで利用できます。');
        }
        $path = $file->store('imports/'.$organization->id, $disk);
        if (strtolower($file->getClientOriginalExtension()) === 'xlsx') {
            try {
                $sheets = app(XlsxImportSource::class)->sheets(Storage::disk($disk)->path($path));
                abort_if($sheets === [], 422, 'XLSXにシートがありません。');
                $batch = ImportBatch::query()->create([
                    'organization_id' => $organization->id, 'source_type' => 'xlsx',
                    'source_name' => $request->string('source_name')->toString(), 'source_file_path' => $path,
                    'available_sheets_json' => $sheets, 'status' => 'sheet_selection', 'executed_by' => $request->user()->id,
                    'started_at' => now(),
                ]);

                return to_route('imports.show', $batch)->with('status', 'シートを選択してください。');
            } catch (\Throwable $exception) {
                Storage::disk($disk)->delete($path);
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
                'status' => 'previewing',
                'executed_by' => $request->user()->id,
                'started_at' => now(),
            ]);
            PrepareImportPreview::dispatch($batch->id)->afterCommit();
        } catch (\Throwable $exception) {
            if ($batch === null) {
                Storage::disk($disk)->delete($path);
            } else {
                $this->markPreviewDispatchFailed($batch->id);
            }
            throw $exception;
        }

        return to_route('imports.show', $batch)->with('status', 'ファイルを受け付けました。Previewをバックグラウンドで準備します。');
    }

    public function show(Request $request, ImportBatch $importBatch): View
    {
        $organization = $this->organization($request);
        $importBatch = $this->scopedBatch($organization, $importBatch);
        $this->authorize('view', $importBatch);
        $capabilities = $this->importCapabilities($organization);
        $rowResults = $importBatch->rowResults()
            ->orderBy('source_row_number')
            ->orderBy('id')
            ->paginate(config('opsdeck.pagination.per_page'), ['*'], 'row_results_page')
            ->withQueryString();
        $selectableRowResults = $rowResults->getCollection()->whereIn('action', ['new_candidate', 'update_candidate', 'confirmation_required', 'error', 'preview']);
        $reviewCandidates = $importBatch->reviewCandidates()
            ->orderBy('id')
            ->paginate(config('opsdeck.pagination.per_page'), ['*'], 'review_candidates_page')
            ->withQueryString();
        $mapping = $importBatch->mapping_id === null
            ? null
            : $importBatch->mappings()->whereKey($importBatch->mapping_id)->first();
        $importTargetLabel = config('opsdeck.import_target_labels.'.($mapping?->target_type ?? ''), '業務');
        $importFieldLabels = config('opsdeck.import_field_labels', []);
        $importActionLabels = config('opsdeck.import_action_labels', []);
        $importErrorLabels = config('opsdeck.import_error_labels', []);
        $importBatchStatusLabels = config('opsdeck.import_batch_status_labels', []);
        $importSourceTypeLabels = config('opsdeck.import_source_type_labels', []);
        $importCandidateTypeLabels = config('opsdeck.import_candidate_type_labels', []);
        $importCandidateStatusLabels = config('opsdeck.import_candidate_status_labels', []);

        return view('imports.show', compact('importBatch', 'rowResults', 'selectableRowResults', 'reviewCandidates', 'capabilities', 'mapping', 'importTargetLabel', 'importFieldLabels', 'importActionLabels', 'importErrorLabels', 'importBatchStatusLabels', 'importSourceTypeLabels', 'importCandidateTypeLabels', 'importCandidateStatusLabels'));
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

    public function mapping(Request $request, ImportBatch $importBatch): View
    {
        $organization = $this->organization($request);
        $importBatch = $this->scopedBatch($organization, $importBatch);
        $this->authorize('view', $importBatch);
        abort_unless(in_array($importBatch->status, ['preview', 'ready'], true), 422, 'Preview準備が完了してからマッピングを設定できます。');

        $targetTypes = config('opsdeck.import_target_types');
        $suggestions = [];
        $aiSuggestions = [];
        $aiTargetType = $request->string('ai_target_type')->toString();
        $normalizationSuggestions = [];
        foreach ($targetTypes as $targetType) {
            $aliases = config('opsdeck.import_column_aliases.'.$targetType, []);
            $suggestions[$targetType] = array_map(static fn (string $header): ?string => $aliases[trim($header)] ?? null, $importBatch->headers_json ?? []);
        }
        if ($request->boolean('ai') && in_array($aiTargetType, $targetTypes, true)) {
            $this->authorize('update', $importBatch);
            $this->rateLimitAiMappingSuggestions($request, $organization);
            $targetFields = array_values(array_unique(array_filter(config('opsdeck.import_column_aliases.'.$aiTargetType, []))));
            $aiSuggestions = app(ImportMappingSuggestionService::class)->suggest($organization, $importBatch->headers_json ?? [], $aiTargetType, $targetFields, $request->user()->id);
            foreach ($aiSuggestions as $header => $suggestion) {
                $index = array_search($header, $importBatch->headers_json ?? [], true);
                if ($index !== false && $suggestions[$aiTargetType][$index] === null) {
                    $suggestions[$aiTargetType][$index] = $suggestion['field'];
                }
            }
        }
        if (in_array($aiTargetType, $targetTypes, true)) {
            $aliases = config('opsdeck.import_column_aliases.'.$aiTargetType, []);
            $columnMap = [];
            foreach ($importBatch->headers_json ?? [] as $header) {
                if (isset($aliases[$header])) {
                    $columnMap[$header] = $aliases[$header];
                }
            }
            $normalizationSuggestions = app(ImportNormalizationSuggestionService::class)->suggest($importBatch->headers_json ?? [], $this->sourceRowsForMapping($importBatch, $aiTargetType), $columnMap);
        }

        return view('imports.mapping', compact('importBatch', 'targetTypes', 'suggestions', 'aiTargetType', 'aiSuggestions', 'normalizationSuggestions'));
    }

    public function saveMapping(StoreImportMappingRequest $request, ImportBatch $importBatch): RedirectResponse
    {
        $importBatch = $this->scopedBatch($this->organization($request), $importBatch);
        $this->authorize('update', $importBatch);
        abort_unless(in_array($importBatch->status, ['preview', 'ready'], true), 422, 'Preview準備が完了してからマッピングを保存できます。');

        try {
            DB::transaction(function () use ($importBatch, $request): void {
                $lockedBatch = ImportBatch::query()->whereKey($importBatch->id)->lockForUpdate()->firstOrFail();
                abort_unless(in_array($lockedBatch->status, ['preview', 'ready'], true), 409, 'このバッチはすでに処理中です。');

                $mapping = $lockedBatch->mappings()->create([
                    'target_type' => $request->string('target_type')->toString(),
                    'column_map' => $request->validated('column_map'),
                    'normalization_rules' => [],
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
        } catch (\Throwable $exception) {
            $this->markMappingAnalysisDispatchFailed($importBatch->id);
            throw $exception;
        }

        return to_route('imports.show', $importBatch)->with('status', '列マッピングを受け付けました。重複候補と件数をバックグラウンドで分析します。');
    }

    public function reviewCandidate(ReviewImportCandidateRequest $request, ImportBatch $importBatch, ImportReviewCandidate $candidate): RedirectResponse
    {
        $importBatch = $this->scopedBatch($this->organization($request), $importBatch);
        $this->authorize('update', $importBatch);
        abort_unless($candidate->import_batch_id === $importBatch->id, 404);
        $candidate->update(['status' => $request->validated('status'), 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

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

            $selectableActions = ['new_candidate', 'update_candidate', 'confirmation_required', 'error', 'preview'];
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
        $this->assertFeatureAvailable($organization, 'import_execution', 'Import実行はPremiumプランで利用できます。');
        abort_unless($importBatch->status === 'ready', 422, 'ready状態のバッチだけ実行できます。');
        abort_unless(($importBatch->source_type === 'google_sheets' && $importBatch->source_external_id !== null) || (in_array($importBatch->source_type, ['csv', 'xlsx'], true) && $importBatch->source_file_path !== null), 422, '実行可能なImportファイルがありません。');
        $mapping = $importBatch->mappings()->where('status', 'ready')->latest()->firstOrFail();
        abort_if($importBatch->reviewCandidates()->whereIn('status', ['pending', 'deferred'])->exists(), 422, '重複候補の判断が残っているため、Importを実行できません。');
        abort_unless(in_array($mapping->target_type, ['employees', 'departments', 'assets', 'saas_services', 'saas_assignments', 'contracts', 'security_controls', 'evidence', 'questionnaire_answers'], true), 422, 'このデータ種別はImport実行に対応していません。');
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

            return to_route('imports.show', $importBatch)->with('status', 'Importを受け付けました。処理状況はこの画面で確認できます。');
        }

        app(ImportBatchExecutionService::class)->execute($importBatch->id, $request->user()->id, 'ready');

        return to_route('imports.show', $importBatch->refresh())->with('status', 'ファイルを取り込みました。');
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
                $result['errors_json'] = ['row' => '管理項目コード・タイトル・カテゴリ・説明と有効な状態が必要です。'];

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
                $result['errors_json'] = ['row' => '次回確認日は最終確認日以降の日付にしてください。'];

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

    private function duplicateKey(string $targetType, array $values): ?string
    {
        return app(ImportBatchExecutionService::class)->duplicateKey($targetType, $values);
    }

    private function normalizeImportValues(string $targetType, array $values): array
    {
        return app(ImportBatchExecutionService::class)->normalizeImportValues($targetType, $values);
    }

    private function sameImportValues(array $previous, array $current): bool
    {
        return app(ImportBatchExecutionService::class)->sameImportValues($previous, $current);
    }

    /**
     * @param  list<string>  $fields
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
                DB::transaction(function () use ($organization, $importBatch, &$markedRollingBack): void {
                    $lockedBatch = ImportBatch::query()
                        ->where('organization_id', $organization->id)
                        ->whereKey($importBatch->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    abort_unless($lockedBatch->status === 'completed', 409, 'このImportはすでに取消処理中です。');
                    $lockedBatch->update(['status' => 'rolling_back', 'failure_message' => null]);
                    $markedRollingBack = true;
                    RollbackImportBatch::dispatch((int) $lockedBatch->id)->afterCommit();
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

        DB::transaction(function () use ($organization, $importBatch): void {
            $lockedBatch = ImportBatch::query()
                ->where('organization_id', $organization->id)
                ->whereKey($importBatch->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($lockedBatch->status === 'completed', 409, 'このImportはすでに取消処理中です。');
            $lockedBatch->update(['status' => 'rolling_back', 'failure_message' => null]);
            app(ImportBatchRollbackService::class)->rollback((int) $lockedBatch->id);
        });

        return to_route('imports.show', $importBatch)->with('status', 'Importを安全に取消しました。');
    }

    public function retry(RetryImportBatchRequest $request, ImportBatch $importBatch): RedirectResponse
    {
        $organization = $this->organization($request);
        $importBatch = $this->scopedBatch($organization, $importBatch);
        $this->authorize('update', $importBatch);
        abort_unless($importBatch->status === 'failed', 422, 'failed状態のバッチだけ再実行できます。');
        if ($importBatch->diff_source_batch_id !== null) {
            $this->assertFeatureAvailable($organization, 'google_sheets', 'Google Sheets連携はPremiumプランで利用できます。');
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
                PrepareGoogleSheetDiffPreview::dispatch($sourceBatchId, $lockedBatch->id)->afterCommit();
            });

            return to_route('imports.show', $importBatch)->with('status', 'Google Sheets差分Previewの再試行を受け付けました。');
        }
        $failedMapping = $importBatch->mapping_id === null
            ? null
            : $importBatch->mappings()->whereKey($importBatch->mapping_id)->where('status', 'failed')->first();
        if ($failedMapping !== null) {
            if ($importBatch->source_type === 'xlsx') {
                $this->assertFeatureAvailable($organization, 'xlsx_preview', 'XLSXプレビューはPremiumプランで利用できます。');
            } elseif ($importBatch->source_type === 'google_sheets') {
                $this->assertFeatureAvailable($organization, 'google_sheets', 'Google Sheets連携はPremiumプランで利用できます。');
            }

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
            if ($importBatch->source_type === 'xlsx') {
                $this->assertFeatureAvailable($organization, 'xlsx_preview', 'XLSXプレビューはPremiumプランで利用できます。');
            } elseif ($importBatch->source_type === 'google_sheets') {
                $this->assertFeatureAvailable($organization, 'google_sheets', 'Google Sheets連携はPremiumプランで利用できます。');
            }

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

        $this->assertFeatureAvailable($organization, 'import_execution', 'Import実行はPremiumプランで利用できます。');
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

    private function rateLimitAiMappingSuggestions(Request $request, Organization $organization): void
    {
        $rateLimitKey = 'opsdeck:ai-mapping-suggestions:'.hash('sha256', $organization->getKey().'|'.$request->user()->getAuthIdentifier());
        $attempts = RateLimiter::hit($rateLimitKey, config('opsdeck.ai.mapping_suggestion_decay_seconds'));

        abort_if(
            $attempts > config('opsdeck.ai.mapping_suggestions_max_attempts'),
            429,
            'AI候補の取得回数が上限に達しました。しばらくしてから再度お試しください。',
        );
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
            app(XlsxImportSource::class)->parseEach(
                Storage::disk(config('opsdeck.import.disk'))->path($importBatch->source_file_path),
                $importBatch->selected_sheet_name,
                $collectSampleRow,
                $sampleRowLimit,
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
            );
        } elseif ($importBatch->source_type === 'csv' && $importBatch->source_file_path !== null) {
            $disk = Storage::disk(config('opsdeck.import.disk'));
            if (! $disk->exists($importBatch->source_file_path)) {
                return [];
            }
            $sourceStream = $disk->readStream($importBatch->source_file_path);
            if (! is_resource($sourceStream)) {
                throw new \RuntimeException('CSVを読み込めません。');
            }

            try {
                app(CsvImportSource::class)->parseEachStream($sourceStream, $collectSampleRow, $sampleRowLimit);
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
