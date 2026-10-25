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

    public const OrganizationInvitationCreated = 'organization.invitation_created';

    public const OrganizationInvitationRevoked = 'organization.invitation_revoked';

    public const OrganizationInvitationDispatchFailed = 'organization.invitation_dispatch_failed';

    public const OrganizationMemberJoined = 'organization.member_joined';

    public const OrganizationMemberUpdated = 'organization.member_updated';

    public const RealizedSavingRecorded = 'cost_saving.realized';

    public const RealizedSavingVoided = 'cost_saving.voided';

    public const WorkTimeMeasurementRecorded = 'work_time_measurement.recorded';

    public const WorkTimeMeasurementVoided = 'work_time_measurement.voided';

    public const ImportErrorsReviewed = 'import.errors_reviewed';

    public const ImportBatchExecuted = 'import.batch_executed';

    public const ImportBatchRolledBack = 'import.batch_rolled_back';

    public const ContractUpdated = 'contract.updated';

    public const ContractCreated = 'contract.created';

    public const EmployeeUpdated = 'employee.updated';

    public const EmployeeCreated = 'employee.created';

    public const AssetUpdated = 'asset.updated';

    public const AssetCreated = 'asset.created';

    public const DepartmentUpdated = 'department.updated';

    public const DepartmentCreated = 'department.created';

    public const SaasServiceUpdated = 'saas_service.updated';

    public const SaasServiceCreated = 'saas_service.created';

    public const SecurityControlUpdated = 'security_control.updated';

    public const AssetAssignmentCreated = 'asset_assignment.created';

    public const AssetAssignmentReturned = 'asset_assignment.returned';

    public const SaasAssignmentCreated = 'saas_assignment.created';

    public const SaasAssignmentUpdated = 'saas_assignment.updated';

    public const EvidenceUpdated = 'evidence.updated';

    public const EvidenceCreated = 'evidence.created';

    public const SavedViewCreated = 'saved_view.created';

    public const SavedViewDeleted = 'saved_view.deleted';

    public const ImportCandidateReviewed = 'import.candidate_reviewed';

    public const GoogleSheetDisconnected = 'google_sheet.disconnected';

    public const GoogleSheetConnected = 'google_sheet.connected';

    public const DataExportStarted = 'data.export_started';

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

    public function actionLabel(): string
    {
        return match ($this->action) {
            self::QuestionnaireAnswerApproved => '質問票の回答を承認',
            self::QuestionnaireAnswerCreated => '質問票の回答を登録',
            self::QuestionnaireAnswerUpdated => '質問票の回答を変更',
            self::QuestionnaireCreated => '質問票を登録',
            self::QuestionnaireUpdated => '質問票を変更',
            self::QuestionnaireQuestionCreated => '質問票の設問を登録',
            self::QuestionnaireQuestionUpdated => '質問票の設問を変更',
            self::OrganizationSettingsUpdated => '組織設定を変更',
            self::OrganizationInvitationCreated => 'メンバーを招待',
            self::OrganizationInvitationRevoked => 'メンバー招待を取り消し',
            self::OrganizationInvitationDispatchFailed => 'メンバー招待の送信に失敗',
            self::OrganizationMemberJoined => 'メンバーが参加',
            self::OrganizationMemberUpdated => 'メンバーの権限・状態を変更',
            self::RealizedSavingRecorded => '費用削減実績を記録',
            self::RealizedSavingVoided => '費用削減実績を取り消し',
            self::WorkTimeMeasurementRecorded => '作業時間の記録を登録・変更',
            self::WorkTimeMeasurementVoided => '作業時間の記録を取り消し',
            self::ImportErrorsReviewed => '取込エラーを確認',
            self::ImportBatchExecuted => 'データ取込を実行',
            self::ImportBatchRolledBack => 'データ取込を取り消し',
            self::ContractCreated => '契約を登録',
            self::ContractUpdated => '契約を変更',
            self::EmployeeCreated => '社員を登録',
            self::EmployeeUpdated => '社員情報を変更',
            self::AssetCreated => '端末を登録',
            self::AssetUpdated => '端末情報を変更',
            self::DepartmentCreated => '部署を登録',
            self::DepartmentUpdated => '部署を変更',
            self::SaasServiceCreated => 'クラウドサービスを登録',
            self::SaasServiceUpdated => 'クラウドサービスを変更',
            self::SecurityControlUpdated => 'セキュリティ項目を登録・変更',
            self::AssetAssignmentCreated => '端末を貸与',
            self::AssetAssignmentReturned => '端末を返却',
            self::SaasAssignmentCreated => 'クラウドサービスを割り当て',
            self::SaasAssignmentUpdated => 'クラウドサービスの割当を変更',
            self::EvidenceUpdated => '証跡を変更',
            self::EvidenceCreated => '証跡を登録',
            self::SavedViewCreated => '共有ビューを登録',
            self::SavedViewDeleted => '共有ビューを削除',
            self::ImportCandidateReviewed => '取込の重複候補を確認',
            self::GoogleSheetDisconnected => 'Googleスプレッドシートの接続を解除',
            self::GoogleSheetConnected => 'Googleスプレッドシートを接続',
            self::DataExportStarted => 'CSV出力を開始',
            default => 'その他の操作',
        };
    }

    public function entityLabel(): string
    {
        return match (class_basename($this->entity_type)) {
            'OrganizationMembership' => 'メンバー',
            'QuestionnaireAnswer' => '質問票の回答',
            'QuestionnaireQuestion' => '質問票の設問',
            'Questionnaire' => '質問票',
            'RealizedSaving' => '費用削減実績',
            'WorkTimeMeasurement' => '作業時間の記録',
            'ImportBatch' => 'データ取込',
            'ImportReviewCandidate' => '取込の重複候補',
            'Contract' => '契約',
            'Employee' => '社員',
            'AssetAssignment' => '端末の貸与',
            'Asset' => '端末',
            'Department' => '部署',
            'SaasAssignment' => 'クラウドサービスの割当',
            'SaasService' => 'クラウドサービス',
            'GoogleSheetConnection' => 'Googleスプレッドシート接続',
            'SecurityControl' => 'セキュリティ項目',
            'Evidence' => '証跡',
            'SavedView' => '定型ビュー',
            'Organization' => '組織',
            'OrganizationInvitation' => 'メンバー招待',
            default => '記録',
        };
    }
}
