<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingStep extends Model
{
    use HasFactory;

    public const ORGANIZATION_SETTINGS_STEP = 'organization_settings';

    public const RENEWAL_CHECKED_STEP = 'renewal_checked';

    protected $fillable = ['organization_id', 'step_key', 'completed_at', 'completed_by'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
