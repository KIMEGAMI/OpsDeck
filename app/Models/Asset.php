<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    protected $fillable = ['organization_id', 'asset_number', 'asset_type', 'manufacturer', 'model', 'serial_number', 'os', 'purchased_at', 'purchase_cost', 'warranty_until', 'status', 'location', 'memo'];

    protected function casts(): array
    {
        return ['purchased_at' => 'date', 'purchase_cost' => 'decimal:2', 'warranty_until' => 'date'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class);
    }

    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->whereNotIn('status', config('opsdeck.asset_non_operational_statuses', []))
            ->where(function (Builder $query): void {
                $query->whereDoesntHave('assignments', fn (Builder $assignmentQuery): Builder => $assignmentQuery->whereNull('returned_at'))
                    ->orWhereHas('assignments', fn (Builder $assignmentQuery): Builder => $assignmentQuery
                        ->whereNull('returned_at')
                        ->whereHas('employee', fn (Builder $employeeQuery): Builder => $employeeQuery
                            ->where('employment_status', '!=', config('opsdeck.employee_status.active'))));
            });
    }
}
