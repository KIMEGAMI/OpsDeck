<?php

namespace Database\Factories;

use App\Models\ImportBatch;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportBatch>
 */
class ImportBatchFactory extends Factory
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
            'source_type' => 'csv',
            'source_name' => fake()->word(),
            'status' => 'completed',
        ];
    }
}
