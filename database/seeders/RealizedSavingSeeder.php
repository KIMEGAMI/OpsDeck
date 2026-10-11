<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\RealizedSaving;
use Illuminate\Database\Seeder;

class RealizedSavingSeeder extends Seeder
{
    private const DemoEvidenceReference = 'OPSDECK-DEMO-REALIZED-SAVING';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $organizationId = filter_var(config('opsdeck.cost_savings.demo_organization_id'), FILTER_VALIDATE_INT);
        if ($organizationId === false || $organizationId === null || $organizationId < 1) {
            return;
        }

        $organization = Organization::query()->find($organizationId);
        $creatorId = $organization?->memberships()->where('status', 'active')->value('user_id');
        if ($organization === null || $creatorId === null) {
            return;
        }

        if (RealizedSaving::query()->where('organization_id', $organization->id)->where('evidence_reference', self::DemoEvidenceReference)->exists()) {
            return;
        }

        RealizedSaving::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $creatorId,
            'description' => 'デモ用の契約見直し実績',
            'evidence_reference' => self::DemoEvidenceReference,
            'realized_on' => now($organization->timezone)->toDateString(),
        ]);
    }
}
