<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\OnboardingStep;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\SaasService;
use App\Models\SecurityControl;
use Illuminate\Database\Eloquent\Builder;

class OnboardingProgressService
{
    /**
     * @param  array<int, array{key:string,label:string,route:string,completed:bool}>  $steps
     */
    public function recordCompletedSteps(Organization $organization, array $steps): void
    {
        $completedSteps = count(array_filter($steps, static fn (array $step): bool => $step['completed']));
        if ($steps === [] || $completedSteps !== count($steps)) {
            return;
        }

        app(ProductEventRecorder::class)->recordOnce('onboarding_completed', $organization);
    }

    public function recordCompletionIfReady(Organization $organization): void
    {
        $this->recordCompletedSteps($organization, $this->steps($organization));
    }

    /** @return array<int, array{key:string,label:string,route:string,completed:bool}> */
    public function steps(Organization $organization): array
    {
        $planFeatureService = app(PlanFeatureService::class);
        $canExecuteImports = $planFeatureService->isAvailable($organization, 'import_execution');
        $recorded = OnboardingStep::query()->where('organization_id', $organization->id)->pluck('completed_at', 'step_key');
        $hasMembership = OrganizationMembership::query()->where('organization_id', $organization->id)->where('status', 'active')->count() > 1;
        $hasInvitation = OrganizationInvitation::query()
            ->where('organization_id', $organization->id)
            ->whereNull('revoked_at')
            ->where(static fn (Builder $query): Builder => $query->whereNotNull('accepted_at')->orWhere('expires_at', '>', now()))
            ->exists();
        $hasContractOrSaas = Contract::query()->where('organization_id', $organization->id)->exists() || SaasService::query()->where('organization_id', $organization->id)->exists();
        $hasCompletedImport = static fn (string $targetType): bool => ImportBatch::query()
            ->where('organization_id', $organization->id)
            ->where('status', 'completed')
            ->whereHas('mappings', static fn (Builder $query): Builder => $query
                ->where('target_type', $targetType)
                ->whereColumn('import_mappings.id', 'import_batches.mapping_id'))
            ->whereHas('rowResults', static fn (Builder $query): Builder => $query
                ->where('target_type', $targetType)
                ->whereNotNull('target_id')
                ->whereIn('action', ['inserted', 'updated']))
            ->exists();
        $hasPreviewedImport = static fn (string $targetType): bool => ImportBatch::query()
            ->where('organization_id', $organization->id)
            ->where('source_type', 'csv')
            ->whereIn('status', ['ready', 'completed'])
            ->whereHas('mappings', static fn (Builder $query): Builder => $query
                ->where('target_type', $targetType)
                ->whereColumn('import_mappings.id', 'import_batches.mapping_id'))
            ->exists();
        $derived = [
            'employees_import' => $canExecuteImports
                ? Employee::query()->where('organization_id', $organization->id)->exists() && $hasCompletedImport('employees')
                : $hasPreviewedImport('employees'),
            'assets_import' => $canExecuteImports
                ? Asset::query()->where('organization_id', $organization->id)->exists() && $hasCompletedImport('assets')
                : $hasPreviewedImport('assets'),
            'contract_or_saas' => $hasContractOrSaas,
            'security_control' => SecurityControl::query()->where('organization_id', $organization->id)->exists(),
            'member_invited' => $hasMembership || $hasInvitation,
        ];
        $definitions = array_filter(
            config('opsdeck.onboarding_steps', []),
            static fn (array $definition): bool => ! isset($definition['required_feature'])
                || $planFeatureService->isAvailable($organization, $definition['required_feature']),
        );

        return array_map(static function (array $definition, string $key) use ($recorded, $derived, $canExecuteImports): array {
            return [
                'key' => $key,
                'label' => ! $canExecuteImports && isset($definition['free_label'])
                    ? $definition['free_label']
                    : $definition['label'],
                'route' => $definition['route'],
                'completed' => array_key_exists($key, $derived) ? $derived[$key] : isset($recorded[$key]),
            ];
        }, $definitions, array_keys($definitions));
    }
}
