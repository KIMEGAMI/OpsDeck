<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaasAssignment extends Model
{
    protected $fillable = ['organization_id', 'saas_service_id', 'employee_id', 'account_identifier', 'license_name', 'status', 'mfa_status', 'last_reviewed_at', 'assigned_at', 'ended_at', 'memo'];

    protected function casts(): array
    {
        return ['last_reviewed_at' => 'date', 'assigned_at' => 'date', 'ended_at' => 'date'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(SaasService::class, 'saas_service_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
