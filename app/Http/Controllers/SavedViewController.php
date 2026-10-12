<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeleteSavedViewRequest;
use App\Http\Requests\StoreSavedViewRequest;
use App\Models\Asset;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\SaasAssignment;
use App\Models\SavedView;
use App\Services\ContractRenewalWindow;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\View\View;

class SavedViewController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $this->organization($request);
        $views = SavedView::query()->where('organization_id', $organization->id)->where(function ($query) use ($request): void {
            $query->where('is_shared', true)->orWhere('created_by', $request->user()->id);
        })->with('createdBy')->orderByDesc('id')->paginate(config('opsdeck.pagination.per_page'))->withQueryString();

        return view('saved-views.index', compact('organization', 'views'));
    }

    public function create(Request $request): View
    {
        $this->authorizeCreate($request);

        return view('saved-views.create', ['viewTypes' => config('opsdeck.saved_view_types', [])]);
    }

    public function store(StoreSavedViewRequest $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $role = $request->attributes->get('currentMembership')?->role?->value;
        SavedView::query()->create(['organization_id' => $organization->id, 'created_by' => $request->user()->id, 'name' => $request->string('name')->toString(), 'view_key' => $request->string('view_key')->toString(), 'is_shared' => in_array($role, ['owner', 'admin'], true) && $request->boolean('is_shared')]);

        return to_route('saved-views.index')->with('status', '定型ビューを保存しました。');
    }

    public function show(Request $request, SavedView $savedView): View
    {
        $organization = $this->organization($request);
        abort_unless($savedView->organization_id === $organization->id, 404);
        abort_unless($savedView->is_shared || $savedView->created_by === $request->user()->id, 403);
        $rows = $this->rows($savedView->view_key, $organization);

        return view('saved-views.show', ['savedView' => $savedView, 'rows' => $rows, 'label' => config('opsdeck.saved_view_types.'.$savedView->view_key)]);
    }

    public function destroy(DeleteSavedViewRequest $request, SavedView $savedView): RedirectResponse
    {
        $savedView->delete();

        return to_route('saved-views.index')->with('status', '定型ビューを削除しました。');
    }

    private function rows(string $key, Organization $organization): LengthAwarePaginator
    {
        $today = CarbonImmutable::today($organization->timezone);
        $perPage = max(1, (int) config('opsdeck.pagination.per_page'));

        return match ($key) {
            'renewals' => Contract::query()->where('organization_id', $organization->id)->whereIn('status', ['active', 'pending_cancel'])->where(function ($query) use ($today): void {
                $query->whereBetween('renewal_date', [$today->toDateString(), $today->addDays(ContractRenewalWindow::ALERT_WINDOW_DAYS)->toDateString()])->orWhereBetween('ends_at', [$today->toDateString(), $today->addDays(ContractRenewalWindow::ALERT_WINDOW_DAYS)->toDateString()]);
            })->orderBy('renewal_date')->orderBy('ends_at')->orderBy('id')->paginate($perPage)->withQueryString(),
            'unassigned_assets' => Asset::query()->where('organization_id', $organization->id)->whereNotIn('status', config('opsdeck.asset_non_operational_statuses', []))->whereDoesntHave('assignments', fn ($query) => $query->whereNull('returned_at'))->orderBy('asset_number')->paginate($perPage)->withQueryString(),
            'mfa_unknown' => SaasAssignment::query()->where('organization_id', $organization->id)->where('mfa_status', config('opsdeck.saas_assignment_mfa_status.unknown'))->whereNull('ended_at')->whereHas('service', fn ($query) => $query->where('status', config('opsdeck.saas_service_active_status')))->orderBy('account_identifier')->orderBy('id')->paginate($perPage)->withQueryString(),
            default => new LengthAwarePaginator([], 0, $perPage, Paginator::resolveCurrentPage()),
        };
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('currentOrganization');
    }

    private function authorizeCreate(Request $request): void
    {
        abort_unless($request->user()?->can('manageOperationalData', $this->organization($request)) === true, 403);
    }
}
