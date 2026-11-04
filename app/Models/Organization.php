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
        'billing_cancel_at_period_end',
        'billing_cancel_at',
        'ai_enabled',
        'stripe_customer_id',
        'stripe_subscription_id',
        'storage_addon_gib',
        'storage_addon_pending_gib',
        'storage_addon_effective_at',
        'storage_addon_checkout_reserved_gib',
        'storage_addon_checkout_reservation_expires_at',
        'storage_addon_checkout_session_id',
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

    public function workTimeMeasurements(): HasMany
    {
        return $this->hasMany(WorkTimeMeasurement::class);
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
            'storage_addon_gib' => 'integer',
            'storage_addon_pending_gib' => 'integer',
            'storage_addon_effective_at' => 'datetime',
            'storage_addon_checkout_reserved_gib' => 'integer',
            'storage_addon_checkout_reservation_expires_at' => 'datetime',
            'billing_past_due_since' => 'datetime',
            'billing_cancel_at_period_end' => 'boolean',
            'billing_cancel_at' => 'datetime',
            'cost_savings_renewal_rate_percent' => 'integer',
            'cost_savings_unused_asset_monthly_jpy' => 'integer',
        ];
    }
}
