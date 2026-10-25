<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWorkTimeMeasurementRequest;
use App\Http\Requests\VoidWorkTimeMeasurementRequest;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\WorkTimeMeasurement;
use App\Services\PlanFeatureService;
use App\Services\ProductEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkTimeMeasurementController extends Controller
{
    public function store(StoreWorkTimeMeasurementRequest $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);
        app(PlanFeatureService::class)->assertAvailable($organization, 'work_time_tracking', '作業時間短縮の記録はPremiumプランで利用できます。');
        $attributes = $request->validated();
        $attributes['process_name'] = $this->normalizeProcessName($attributes['process_name']);
        $attributes['currency'] = ($attributes['hourly_rate'] ?? null) === null ? null : $attributes['currency'];
        $processKey = $this->processKey($attributes['process_name']);
        $wasUpdated = false;

        DB::transaction(function () use ($attributes, $organization, $request, $processKey, &$wasUpdated): void {
            $lockedOrganization = Organization::query()
                ->whereKey($organization->id)
                ->lockForUpdate()
                ->firstOrFail();
            $measurement = WorkTimeMeasurement::query()
                ->where('organization_id', $lockedOrganization->id)
                ->where('active_process_key', $processKey)
                ->whereNull('voided_at')
                ->lockForUpdate()
                ->first();
            $wasUpdated = $measurement !== null;
            $measurement ??= new WorkTimeMeasurement(['organization_id' => $lockedOrganization->id, 'created_by' => $request->user()->id]);
            $measurement->fill($attributes + ['process_key' => $processKey, 'active_process_key' => $processKey]);
            $measurement->save();

            AuditLog::query()->create([
                'organization_id' => $lockedOrganization->id,
                'actor_user_id' => $request->user()->id,
                'entity_type' => WorkTimeMeasurement::class,
                'entity_id' => $measurement->id,
                'action' => AuditLog::WorkTimeMeasurementRecorded,
                'after_json' => ['changed_fields' => array_keys($attributes), 'updated_existing' => $wasUpdated],
            ]);
            app(ProductEventRecorder::class)->recordFirstBusinessChange($lockedOrganization);
        });

        return to_route('costs.dashboard')->with('status', $wasUpdated
            ? '同じ作業名の記録を更新しました。時間の短縮は参考値で、経費削減額には含めていません。'
            : '作業時間を記録しました。時間の短縮は参考値で、経費削減額には含めていません。');
    }

    public function void(VoidWorkTimeMeasurementRequest $request, WorkTimeMeasurement $workTimeMeasurement): RedirectResponse
    {
        $organization = $this->organization($request);
        abort_unless($workTimeMeasurement->organization_id === $organization->id, 404);
        $this->authorize('manageOperationalData', $organization);
        app(PlanFeatureService::class)->assertAvailable($organization, 'work_time_tracking', '作業時間短縮の記録はPremiumプランで利用できます。');
        $validated = $request->validated();

        DB::transaction(function () use ($organization, $workTimeMeasurement, $request, $validated): void {
            $measurement = WorkTimeMeasurement::query()
                ->where('organization_id', $organization->id)
                ->whereKey($workTimeMeasurement->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_if($measurement->voided_at !== null, 409, 'この記録はすでに取り消されています。');
            $measurement->update([
                'voided_at' => now(),
                'voided_by' => $request->user()->id,
                'void_reason' => $validated['reason'],
                'active_process_key' => null,
            ]);
            AuditLog::query()->create([
                'organization_id' => $organization->id,
                'actor_user_id' => $request->user()->id,
                'entity_type' => WorkTimeMeasurement::class,
                'entity_id' => $measurement->id,
                'action' => AuditLog::WorkTimeMeasurementVoided,
                'before_json' => ['voided_at' => null],
                'after_json' => ['voided_at' => $measurement->voided_at->toIso8601String(), 'voided_by' => $measurement->voided_by],
            ]);
            app(ProductEventRecorder::class)->recordFirstBusinessChange($organization);
        });

        return to_route('costs.dashboard')->with('status', '記録を取り消しました。履歴は残ります。');
    }

    private function organization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');

        return $organization;
    }

    private function processKey(string $processName): string
    {
        return hash('sha256', mb_strtolower($this->normalizeProcessName($processName)));
    }

    private function normalizeProcessName(string $processName): string
    {
        $normalizedName = preg_replace('/[\p{Z}\s]+/u', ' ', $processName) ?? $processName;

        return trim($normalizedName);
    }
}
