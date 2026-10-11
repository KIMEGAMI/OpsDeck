<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\RealizedSaving;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RealizedSaving>
 */
class RealizedSavingFactory extends Factory
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
            'contract_id' => null,
            'created_by' => User::factory(),
            'description' => fake()->sentence(),
            'amount' => fake()->randomFloat(2, (float) config('opsdeck.cost_savings.realized_amount_min'), (float) config('opsdeck.cost_savings.realized_amount_max')),
            'currency' => config('opsdeck.cost_savings.default_currency'),
            'period' => config('opsdeck.cost_savings.default_realized_period'),
            'realized_on' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'evidence_reference' => fake()->unique()->bothify('EVIDENCE-########'),
            'voided_by' => null,
            'voided_at' => null,
            'void_reason' => null,
        ];
    }
}
