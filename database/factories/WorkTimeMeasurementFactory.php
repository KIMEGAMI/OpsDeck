<?php

namespace Database\Factories;

use App\Models\WorkTimeMeasurement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkTimeMeasurement>
 */
class WorkTimeMeasurementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $processKey = hash('sha256', fake()->unique()->uuid());

        return [
            'process_name' => fake()->unique()->words(3, true),
            'process_key' => $processKey,
            'active_process_key' => $processKey,
            'before_minutes' => fake()->numberBetween(10, 120),
            'after_minutes' => fake()->numberBetween(1, 9),
            'monthly_occurrences' => fake()->numberBetween(1, 30),
            'hourly_rate' => null,
            'currency' => null,
            'measured_on' => today()->toDateString(),
            'evidence_reference' => fake()->sentence(),
            'voided_by' => null,
            'voided_at' => null,
            'void_reason' => null,
        ];
    }
}
