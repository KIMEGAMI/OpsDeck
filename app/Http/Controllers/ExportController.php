<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Evidence;
use App\Models\Organization;
use App\Models\Questionnaire;
use App\Models\RealizedSaving;
use App\Models\SaasAssignment;
use App\Models\SaasService;
use App\Models\SecurityControl;
use App\Services\CsvFormulaSanitizer;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function __construct(private readonly CsvFormulaSanitizer $csvFormulaSanitizer) {}

    public function index(Request $request): View
    {
        $organization = $this->organization($request);
        $this->authorize('export', $organization);

        return view('exports.index', compact('organization'));
    }

    public function download(Request $request, string $resource): StreamedResponse
    {
        $organization = $this->organization($request);
        $this->authorize('export', $organization);
        abort_unless(in_array($resource, config('opsdeck.export_resources'), true), 404);
        $filename = 'opsdeck-'.$resource.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($organization, $resource): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $this->headers($resource), ',', '"', '');
            foreach ($this->rows($organization, $resource) as $row) {
                fputcsv($handle, $row, ',', '"', '');
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return list<string> */
    private function headers(string $resource): array
    {
        return match ($resource) {
            'employees' => ['id', 'employee_number', 'name', 'email', 'department_id', 'employment_status', 'joined_at', 'left_at', 'job_title'],
            'departments' => ['id', 'parent_id', 'name', 'code', 'is_active'],
            'assets' => ['id', 'asset_number', 'asset_type', 'manufacturer', 'model', 'serial_number', 'status', 'location'],
            'saas_services' => ['id', 'name', 'vendor', 'category', 'website_url', 'status'],
            'saas_assignments' => ['id', 'saas_service_id', 'employee_id', 'account_identifier', 'license_name', 'status', 'mfa_status'],
            'contracts' => ['id', 'saas_service_id', 'vendor_name', 'contract_name', 'billing_cycle', 'amount', 'currency', 'started_at', 'ends_at', 'renewal_date', 'status'],
            'security_controls' => ['id', 'control_code', 'title', 'category', 'status', 'last_reviewed_at', 'next_review_at'],
            'evidence' => ['id', 'title', 'evidence_type', 'mime_type', 'file_size', 'valid_from', 'valid_until', 'uploaded_by'],
            'questionnaires' => ['id', 'title', 'requester_name', 'requested_at', 'due_at', 'status'],
            'audit_logs' => ['id', 'actor_user_id', 'entity_type', 'entity_id', 'action', 'before_json', 'after_json', 'created_at'],
            'realized_savings' => ['id', 'contract_id', 'evidence_id', 'created_by', 'description', 'amount', 'currency', 'period', 'realized_on', 'evidence_reference', 'voided_by', 'voided_at', 'void_reason', 'created_at', 'updated_at'],
            default => [],
        };
    }

    /** @return iterable<list<string|int|null>> */
    private function rows(Organization $organization, string $resource): iterable
    {
        $query = match ($resource) {
            'employees' => Employee::query()->where('organization_id', $organization->id),
            'departments' => Department::query()->where('organization_id', $organization->id),
            'assets' => Asset::query()->where('organization_id', $organization->id),
            'saas_services' => SaasService::query()->where('organization_id', $organization->id),
            'saas_assignments' => SaasAssignment::query()->where('organization_id', $organization->id),
            'contracts' => Contract::query()->where('organization_id', $organization->id),
            'security_controls' => SecurityControl::query()->where('organization_id', $organization->id),
            'evidence' => Evidence::query()->where('organization_id', $organization->id),
            'questionnaires' => Questionnaire::query()->where('organization_id', $organization->id),
            'audit_logs' => AuditLog::query()->where('organization_id', $organization->id),
            'realized_savings' => RealizedSaving::query()->where('organization_id', $organization->id),
        };
        foreach ($query->cursor() as $record) {
            yield array_map(fn (mixed $value): string => $this->csvFormulaSanitizer->sanitize(is_array($value) ? (string) json_encode($value, JSON_UNESCAPED_UNICODE) : (string) ($value ?? '')), array_values($record->only($this->headers($resource))));
        }
    }

    private function organization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');

        return $organization;
    }
}
