<?php

namespace App\Models;

use Database\Factories\ContractFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contract extends Model
{
    /** @use HasFactory<ContractFactory> */
    use HasFactory;

    protected $fillable = ['organization_id', 'saas_service_id', 'vendor_name', 'contract_name', 'contract_type', 'billing_cycle', 'amount', 'currency', 'started_at', 'ends_at', 'auto_renew', 'cancellation_deadline', 'renewal_date', 'license_quantity', 'owner_employee_id', 'status', 'memo'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'auto_renew' => 'boolean',
            'started_at' => 'date',
            'ends_at' => 'date',
            'cancellation_deadline' => 'date',
            'renewal_date' => 'date',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(SaasService::class, 'saas_service_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_employee_id');
    }
}
