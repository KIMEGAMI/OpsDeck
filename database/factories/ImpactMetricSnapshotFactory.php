<?php

namespace Database\Factories;

use App\Models\ImpactMetricSnapshot;
use App\Models\ImportBatch;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImpactMetricSnapshot>
 */
class ImpactMetricSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'import_batch_id' => function (array $attributes): int {
                return ImportBatch::factory()->create(['organization_id' => $attributes['organization_id']])->id;
            },
            'stage' => 'import_after',
            'snapshot_date' => null,
            'metrics_json' => [
                'employees' => 0,
                'assets' => 0,
                'saas_services' => 0,
                'evidence_expired' => 0,
                'security_open' => 0,
                'renewals_30_days' => 0,
                'monthly_jpy' => 0,
                'import_errors' => 0,
                'questionnaire_pending' => 0,
                'asset_attention' => 0,
                'saas_attention' => 0,
            ],
            'captured_at' => now(),
        ];
    }
}
