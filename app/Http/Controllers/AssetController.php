<?php

namespace App\Http\Controllers;

use App\Http\Requests\Asset\StoreAssetRequest;
use App\Http\Requests\Asset\UpdateAssetRequest;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Organization;
use App\Services\PlanLimitService;
use App\Services\ProductEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AssetController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $this->organization($request);
        $this->authorize('view', $organization);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:'.config('opsdeck.validation.name_max_length')]]);
        $assets = Asset::query()->where('organization_id', $organization->id)
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(fn ($query) => $query->where('asset_number', 'like', '%'.$search.'%')->orWhere('manufacturer', 'like', '%'.$search.'%')->orWhere('model', 'like', '%'.$search.'%'));
            })->orderBy('asset_number')->paginate(config('opsdeck.pagination.per_page'))->withQueryString();

        return view('assets.index', compact('assets', 'filters', 'organization'));
    }

    public function create(Request $request): View
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);

        return view('assets.form', ['asset' => new Asset(['status' => config('opsdeck.asset_statuses')[0]]), 'formAction' => route('assets.store'), 'formMethod' => 'POST']);
    }

    public function store(StoreAssetRequest $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);
        $asset = DB::transaction(function () use ($request, $organization): Asset {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            app(PlanLimitService::class)->assertCanAdd($organization, 'assets');

            return Asset::query()->create($this->validatedForOrganization($request->validated(), $organization) + ['organization_id' => $organization->id]);
        });
        app(ProductEventRecorder::class)->recordOnce('first_asset_registered', $organization);
        app(ProductEventRecorder::class)->recordFirstDashboardAction($organization);

        return to_route('assets.show', $asset)->with('status', '端末を登録しました。');
    }

    public function show(Request $request, Asset $asset): View
    {
        $asset = $this->scopedAsset($this->organization($request), $asset);
        $this->authorize('view', $asset);
        $asset->load(['assignments' => fn ($query) => $query->with('employee')->latest('assigned_at')]);
        $employees = $request->user()->can('manageOperationalData', $this->organization($request))
            ? Employee::query()
                ->where('organization_id', $asset->organization_id)
                ->where('employment_status', config('opsdeck.employee_status.active'))
                ->orderBy('name')
                ->get()
            : collect();
        $activeAssignment = $asset->assignments->firstWhere('returned_at', null);

        return view('assets.show', compact('asset', 'employees', 'activeAssignment'));
    }

    public function edit(Request $request, Asset $asset): View
    {
        $organization = $this->organization($request);
        $asset = $this->scopedAsset($organization, $asset);
        $this->authorize('update', $asset);

        return view('assets.form', ['asset' => $asset, 'formAction' => route('assets.update', $asset), 'formMethod' => 'PUT']);
    }

    public function update(UpdateAssetRequest $request, Asset $asset): RedirectResponse
    {
        $organization = $this->organization($request);
        $asset = $this->scopedAsset($organization, $asset);
        $this->authorize('update', $asset);
        $attributes = $this->validatedForOrganization($request->validated(), $organization, $asset);

        DB::transaction(function () use ($request, $organization, $asset, $attributes): void {
            $asset->fill($attributes);
            $changedFields = array_keys($asset->getDirty());
            $asset->save();

            if ($changedFields !== []) {
                AuditLog::query()->create([
                    'organization_id' => $organization->id,
                    'actor_user_id' => $request->user()->id,
                    'entity_type' => Asset::class,
                    'entity_id' => $asset->id,
                    'action' => AuditLog::AssetUpdated,
                    'after_json' => ['changed_fields' => $changedFields],
                ]);
            }
        });

        return to_route('assets.show', $asset)->with('status', '端末を更新しました。');
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('currentOrganization');
    }

    private function scopedAsset(Organization $organization, Asset $asset): Asset
    {
        abort_unless($asset->organization_id === $organization->id, 404);

        return $asset;
    }

    private function validatedForOrganization(array $attributes, Organization $organization, ?Asset $asset = null): array
    {
        if (Asset::query()->where('organization_id', $organization->id)->where('asset_number', $attributes['asset_number'])->when($asset, fn ($query) => $query->whereKeyNot($asset->id))->exists()) {
            throw ValidationException::withMessages(['asset_number' => '同じ資産番号の端末が既に存在します。']);
        }

        return $attributes;
    }
}
