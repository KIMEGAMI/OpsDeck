<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    public const QuestionnaireAnswerApproved = 'questionnaire_answer.approved';

    public const OrganizationSettingsUpdated = 'organization.settings_updated';

    public const OrganizationInvitationRevoked = 'organization.invitation_revoked';

    public const RealizedSavingRecorded = 'cost_saving.realized';

    public const RealizedSavingVoided = 'cost_saving.voided';

    public const ImportErrorsReviewed = 'import.errors_reviewed';

    protected $fillable = ['organization_id', 'actor_user_id', 'entity_type', 'entity_id', 'action', 'before_json', 'after_json'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['before_json' => 'array', 'after_json' => 'array', 'created_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
