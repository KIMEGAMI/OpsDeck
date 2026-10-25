<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Services\ProductEventRecorder;

class BusinessChangeObserver
{
    private const BUSINESS_CHANGE_ACTIONS = [
        AuditLog::QuestionnaireAnswerApproved,
        AuditLog::QuestionnaireAnswerCreated,
        AuditLog::QuestionnaireAnswerUpdated,
        AuditLog::QuestionnaireCreated,
        AuditLog::QuestionnaireUpdated,
        AuditLog::QuestionnaireQuestionCreated,
        AuditLog::QuestionnaireQuestionUpdated,
        AuditLog::OrganizationMemberUpdated,
        AuditLog::OrganizationSettingsUpdated,
        AuditLog::OrganizationMemberJoined,
        AuditLog::RealizedSavingRecorded,
        AuditLog::RealizedSavingVoided,
        AuditLog::WorkTimeMeasurementRecorded,
        AuditLog::WorkTimeMeasurementVoided,
        AuditLog::ContractUpdated,
        AuditLog::ContractCreated,
        AuditLog::EmployeeUpdated,
        AuditLog::EmployeeCreated,
        AuditLog::AssetUpdated,
        AuditLog::AssetCreated,
        AuditLog::DepartmentUpdated,
        AuditLog::DepartmentCreated,
        AuditLog::SaasServiceUpdated,
        AuditLog::SaasServiceCreated,
        AuditLog::SecurityControlUpdated,
        AuditLog::AssetAssignmentCreated,
        AuditLog::AssetAssignmentReturned,
        AuditLog::SaasAssignmentCreated,
        AuditLog::SaasAssignmentUpdated,
        AuditLog::EvidenceUpdated,
    ];

    public function created(AuditLog $auditLog): void
    {
        if (! in_array($auditLog->action, self::BUSINESS_CHANGE_ACTIONS, true)) {
            return;
        }

        $organization = $auditLog->organization;

        if ($organization !== null) {
            app(ProductEventRecorder::class)->recordFirstBusinessChange($organization);
        }
    }
}
