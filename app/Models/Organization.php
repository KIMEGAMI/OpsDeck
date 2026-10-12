<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'company_size',
        'industry',
        'timezone',
        'plan_code',
        'billing_status',
        'ai_enabled',
        'stripe_customer_id',
        'stripe_subscription_id',
        'stripe_last_event_created_at',
        'billing_past_due_since',
        'billing_failure_event_id',
        'cost_savings_renewal_rate_percent',
        'cost_savings_unused_asset_monthly_jpy',
    ];

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function realizedSavings(): HasMany
    {
        return $this->hasMany(RealizedSaving::class);
    }

    public function importProfiles(): HasMany
    {
        return $this->hasMany(ImportProfile::class);
    }

    protected function casts(): array
    {
        return [
            'ai_enabled' => 'boolean',
            'stripe_last_event_created_at' => 'integer',
            'billing_past_due_since' => 'datetime',
            'cost_savings_renewal_rate_percent' => 'integer',
            'cost_savings_unused_asset_monthly_jpy' => 'integer',
        ];
    }
}
