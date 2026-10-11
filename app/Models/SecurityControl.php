<?php

namespace App\Models;

use Database\Factories\SecurityControlFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityControl extends Model
{
    /** @use HasFactory<SecurityControlFactory> */
    use HasFactory;

    protected $fillable = ['organization_id', 'control_code', 'title', 'category', 'description', 'status', 'owner_employee_id', 'review_frequency', 'last_reviewed_at', 'next_review_at', 'memo'];

    protected function casts(): array
    {
        return ['last_reviewed_at' => 'date', 'next_review_at' => 'date'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_employee_id');
    }
}
