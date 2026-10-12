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

    public const QuestionnaireAnswerCreated = 'questionnaire_answer.created';

    public const QuestionnaireAnswerUpdated = 'questionnaire_answer.updated';

    public const QuestionnaireCreated = 'questionnaire.created';

    public const QuestionnaireUpdated = 'questionnaire.updated';

    public const QuestionnaireQuestionCreated = 'questionnaire_question.created';

    public const QuestionnaireQuestionUpdated = 'questionnaire_question.updated';

    public const OrganizationSettingsUpdated = 'organization.settings_updated';

    public const OrganizationInvitationRevoked = 'organization.invitation_revoked';

    public const OrganizationMemberJoined = 'organization.member_joined';

    public const RealizedSavingRecorded = 'cost_saving.realized';

    public const RealizedSavingVoided = 'cost_saving.voided';

    public const ImportErrorsReviewed = 'import.errors_reviewed';

    public const ImportBatchExecuted = 'import.batch_executed';

    public const ContractUpdated = 'contract.updated';

    public const EmployeeUpdated = 'employee.updated';

    public const AssetUpdated = 'asset.updated';

    public const DepartmentUpdated = 'department.updated';

    public const SaasServiceUpdated = 'saas_service.updated';

    public const SecurityControlUpdated = 'security_control.updated';

    public const AssetAssignmentCreated = 'asset_assignment.created';

    public const AssetAssignmentReturned = 'asset_assignment.returned';

    public const EvidenceUpdated = 'evidence.updated';

    public const ImportCandidateReviewed = 'import.candidate_reviewed';

    public const GoogleSheetDisconnected = 'google_sheet.disconnected';

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
