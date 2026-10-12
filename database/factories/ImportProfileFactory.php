<?php

namespace Database\Factories;

use App\Models\ImportProfile;
use App\Models\Organization;
use App\Services\Import\ImportProfileService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ImportProfile>
 */
class ImportProfileFactory extends Factory
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
            'profile_key' => (string) Str::uuid(),
            'version' => 1,
            'name' => fake()->words(2, true),
            'target_type' => 'employees',
            'source_signature' => app(ImportProfileService::class)->sourceSignature(null, ['name', 'email']),
            'header_row_number' => 1,
            'data_start_row_number' => 2,
            'selected_sheet_name' => null,
            'source_headers' => ['name', 'email'],
            'column_map' => ['name' => 'name', 'email' => 'email'],
            'normalization_rules' => [],
            'created_by' => null,
        ];
    }
}
