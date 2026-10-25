<?php

namespace App\Http\Controllers;

use App\Exceptions\StorageQuotaExceededException;
use App\Http\Requests\Evidence\StoreEvidenceRequest;
use App\Http\Requests\Evidence\UpdateEvidenceRequest;
use App\Models\AuditLog;
use App\Models\Evidence;
use App\Models\Organization;
use App\Services\OrganizationStorageQuotaService;
use App\Services\PrivateUploadStorageService;
use App\Services\ProductEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class EvidenceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $organization = $this->organization($request);
        $this->authorize('view', $organization);
        $filters = $request->validate(['expired' => ['nullable', 'boolean']]);
        $today = CarbonImmutable::today($organization->timezone)->toDateString();
        $evidence = Evidence::query()->where('organization_id', $organization->id)->when($filters['expired'] ?? false, fn ($query) => $query->whereNotNull('valid_until')->whereDate('valid_until', '<', $today))->latest()->orderByDesc('id')->paginate(config('opsdeck.pagination.per_page'))->withQueryString();

        return view('evidence.index', compact('evidence', 'organization', 'filters'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): View
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);

        return view('evidence.form', [
            'evidence' => new Evidence,
            'formAction' => route('evidence.store'),
            'formMethod' => 'POST',
            'storageQuota' => app(OrganizationStorageQuotaService::class)->summary($organization),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreEvidenceRequest $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);
        $attributes = $request->validated();
        $disk = config('opsdeck.evidence.disk');
        $file = $request->file('file');
        try {
            $evidence = app(OrganizationStorageQuotaService::class)->storeWithinQuota(
                $organization,
                (int) $file->getSize(),
                function () use ($attributes, $organization, $disk, $file, $request): Evidence {
                    $path = app(PrivateUploadStorageService::class)->store(
                        $file,
                        'evidence/'.$organization->id,
                        $disk,
                        config('opsdeck.evidence.allowed_extensions'),
                    );

                    unset($attributes['file']);

                    try {
                        return DB::transaction(function () use ($attributes, $organization, $disk, $file, $path, $request): Evidence {
                            $evidence = Evidence::query()->create([
                                ...$attributes,
                                'organization_id' => $organization->id,
                                'storage_disk' => $disk,
                                'storage_path' => $path,
                                'mime_type' => $file->getMimeType(),
                                'file_size' => $file->getSize(),
                                'uploaded_by' => $request->user()->id,
                            ]);
                            AuditLog::query()->create([
                                'organization_id' => $organization->id,
                                'actor_user_id' => $request->user()->id,
                                'entity_type' => Evidence::class,
                                'entity_id' => $evidence->id,
                                'action' => AuditLog::EvidenceCreated,
                                'after_json' => ['created_fields' => [...array_keys($attributes), 'file']],
                            ]);

                            return $evidence;
                        });
                    } catch (Throwable $exception) {
                        try {
                            $fileRemoved = Storage::disk($disk)->delete($path);
                        } catch (Throwable) {
                            $fileRemoved = false;
                        }

                        if (! $fileRemoved) {
                            try {
                                Log::warning('Evidence upload cleanup failed after evidence record persistence failed.');
                            } catch (Throwable) {
                                // Preserve the original persistence exception.
                            }
                        }

                        throw $exception;
                    }
                },
            );
        } catch (StorageQuotaExceededException $exception) {
            return back()->withErrors(['file' => $exception->getMessage()]);
        }

        app(ProductEventRecorder::class)->recordFirstBusinessChange($organization);

        return to_route('evidence.show', $evidence)->with('status', '証跡を登録しました。');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Evidence $evidence): View
    {
        $evidence = $this->scopedEvidence($this->organization($request), $evidence);
        $this->authorize('view', $evidence);

        return view('evidence.show', compact('evidence'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Evidence $evidence): View
    {
        $evidence = $this->scopedEvidence($this->organization($request), $evidence);
        $this->authorize('update', $evidence);

        return view('evidence.form', ['evidence' => $evidence, 'formAction' => route('evidence.update', $evidence), 'formMethod' => 'PUT']);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateEvidenceRequest $request, Evidence $evidence): RedirectResponse
    {
        $organization = $this->organization($request);
        $evidence = $this->scopedEvidence($organization, $evidence);
        $this->authorize('update', $evidence);
        $attributes = $request->validated();

        DB::transaction(function () use ($request, $organization, $evidence, $attributes): void {
            $evidence->fill($attributes);
            $changedFields = array_keys($evidence->getDirty());
            $evidence->save();

            if ($changedFields !== []) {
                AuditLog::query()->create([
                    'organization_id' => $organization->id,
                    'actor_user_id' => $request->user()->id,
                    'entity_type' => Evidence::class,
                    'entity_id' => $evidence->id,
                    'action' => AuditLog::EvidenceUpdated,
                    'after_json' => ['changed_fields' => $changedFields],
                ]);
                app(ProductEventRecorder::class)->recordFirstBusinessChange($organization);
            }
        });

        return to_route('evidence.show', $evidence)->with('status', '証跡を更新しました。');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function download(Request $request, Evidence $evidence): Response|StreamedResponse
    {
        $evidence = $this->scopedEvidence($this->organization($request), $evidence);
        $this->authorize('view', $evidence);
        abort_if($evidence->storage_path === 'metadata-only', 404);
        try {
            $storage = Storage::disk($evidence->storage_disk);
            $fileExists = $storage->exists($evidence->storage_path);
        } catch (Throwable) {
            return $this->evidenceStorageUnavailable();
        }
        abort_unless($fileExists, 404);

        $extension = strtolower(pathinfo($evidence->storage_path, PATHINFO_EXTENSION));
        $allowedExtensions = array_map('strtolower', config('opsdeck.evidence.allowed_extensions'));
        $downloadName = 'evidence-'.$evidence->id;
        if (in_array($extension, $allowedExtensions, true)) {
            $downloadName .= '.'.$extension;
        }

        try {
            return $storage->download($evidence->storage_path, $downloadName, ['Content-Type' => $evidence->mime_type]);
        } catch (Throwable) {
            return $this->evidenceStorageUnavailable();
        }
    }

    private function evidenceStorageUnavailable(): Response
    {
        try {
            Log::warning('Private evidence storage could not be read.');
        } catch (Throwable) {
            // Preserve the safe response shown to the user.
        }

        return response()->view('errors.503', [], 503);
    }

    private function organization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');

        return $organization;
    }

    private function scopedEvidence(Organization $organization, Evidence $evidence): Evidence
    {
        abort_unless($evidence->organization_id === $organization->id, 404);

        return $evidence;
    }
}
