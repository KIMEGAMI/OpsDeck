<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $organization = $this->organization($request);
        abort_unless($request->user()->can('viewAny', [AuditLog::class, $organization]), 403);
        $logs = AuditLog::query()->where('organization_id', $organization->id)->latest()->orderByDesc('id')->paginate(config('opsdeck.pagination.per_page'));

        return view('audit-logs.index', compact('logs'));
    }

    private function organization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');

        return $organization;
    }
}
