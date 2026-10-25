<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRealizedSavingRequest;
use App\Http\Requests\VoidRealizedSavingRequest;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\RealizedSaving;
use App\Services\PlanFeatureService;
use App\Services\ProductEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RealizedSavingController extends Controller
{
    public function store(StoreRealizedSavingRequest $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);
        app(PlanFeatureService::class)->assertAvailable($organization, 'realized_savings', '削減実績の記録はPremiumプランで利用できます。');
        $attributes = $request->validated();
        if (isset($attributes['evidence_id'])) {
            app(PlanFeatureService::class)->assertAvailable($organization, 'evidence', '保存済み証憑との紐づけはPremiumプランで利用できます。');
        }

        DB::transaction(function () use ($attributes, $organization, $request): void {
            $saving = $organization->realizedSavings()->create($attributes + ['created_by' => $request->user()->id]);
            AuditLog::query()->create([
                'organization_id' => $organization->id,
                'actor_user_id' => $request->user()->id,
                'entity_type' => RealizedSaving::class,
                'entity_id' => $saving->id,
                'action' => AuditLog::RealizedSavingRecorded,
                'after_json' => ['changed_fields' => array_keys($attributes)],
            ]);
            app(ProductEventRecorder::class)->recordFirstBusinessChange($organization);
        });

        return to_route('costs.dashboard')->with('status', '削減実績を記録しました。金額は入力した通貨と期間ごとに集計されます。');
    }

    public function void(VoidRealizedSavingRequest $request, RealizedSaving $realizedSaving): RedirectResponse
    {
        $organization = $this->organization($request);
        abort_unless($realizedSaving->organization_id === $organization->id, 404);
        $this->authorize('manageOperationalData', $organization);
        app(PlanFeatureService::class)->assertAvailable($organization, 'realized_savings', '削減実績の記録はPremiumプランで利用できます。');
        $validated = $request->validated();

        DB::transaction(function () use ($organization, $realizedSaving, $request, $validated): void {
            $saving = RealizedSaving::query()
                ->where('organization_id', $organization->id)
                ->whereKey($realizedSaving->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_if($saving->voided_at !== null, 409, 'この削減実績はすでに取り消されています。');
            $saving->update([
                'voided_at' => now(),
                'voided_by' => $request->user()->id,
                'void_reason' => $validated['reason'],
            ]);
            AuditLog::query()->create([
                'organization_id' => $organization->id,
                'actor_user_id' => $request->user()->id,
                'entity_type' => RealizedSaving::class,
                'entity_id' => $saving->id,
                'action' => AuditLog::RealizedSavingVoided,
                'before_json' => ['voided_at' => null],
                'after_json' => ['voided_at' => $saving->voided_at->toIso8601String(), 'voided_by' => $saving->voided_by],
            ]);
            app(ProductEventRecorder::class)->recordFirstBusinessChange($organization);
        });

        return to_route('costs.dashboard')->with('status', '削減実績を取り消し、記録を残しました。');
    }

    private function organization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');

        return $organization;
    }
}
