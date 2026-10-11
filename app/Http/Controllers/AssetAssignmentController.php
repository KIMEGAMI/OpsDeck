<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssetAssignment\ReturnAssetAssignmentRequest;
use App\Http\Requests\AssetAssignment\StoreAssetAssignmentRequest;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Employee;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssetAssignmentController extends Controller
{
    public function store(StoreAssetAssignmentRequest $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);

        $assignment = DB::transaction(function () use ($request, $organization): AssetAssignment {
            $attributes = $request->validated();
            $asset = Asset::query()
                ->where('organization_id', $organization->id)
                ->lockForUpdate()
                ->findOrFail($attributes['asset_id']);
            $employee = Employee::query()
                ->where('organization_id', $organization->id)
                ->findOrFail($attributes['employee_id']);

            if ($employee->employment_status !== config('opsdeck.employee_status.active')) {
                throw ValidationException::withMessages(['employee_id' => '在籍中の社員のみ端末を割り当てられます。']);
            }

            if (AssetAssignment::query()
                ->where('organization_id', $organization->id)
                ->where('asset_id', $asset->id)
                ->whereNull('returned_at')
                ->exists()) {
                throw ValidationException::withMessages(['asset_id' => 'この端末は既に割り当て済みです。']);
            }

            if ($asset->status !== config('opsdeck.asset_status.in_stock')) {
                throw ValidationException::withMessages(['asset_id' => 'この端末は貸出可能な状態ではありません。']);
            }

            $assignment = AssetAssignment::query()->create($attributes + ['organization_id' => $organization->id]);
            $asset->update(['status' => config('opsdeck.asset_status.assigned')]);

            return $assignment;
        });

        return to_route('assets.show', $assignment->asset_id)->with('status', '端末を割り当てました。');
    }

    public function update(ReturnAssetAssignmentRequest $request, AssetAssignment $assetAssignment): RedirectResponse
    {
        $organization = $this->organization($request);
        abort_unless($assetAssignment->organization_id === $organization->id, 404);
        $this->authorize('update', $assetAssignment);

        $assignment = DB::transaction(function () use ($request, $organization, $assetAssignment): AssetAssignment {
            $asset = Asset::query()
                ->where('organization_id', $organization->id)
                ->lockForUpdate()
                ->findOrFail($assetAssignment->asset_id);
            $assignment = AssetAssignment::query()
                ->where('organization_id', $organization->id)
                ->lockForUpdate()
                ->findOrFail($assetAssignment->id);

            if ($assignment->returned_at !== null) {
                throw ValidationException::withMessages(['returned_at' => 'この割当は既に返却済みです。']);
            }

            $assignment->update($request->safe()->only(['returned_at', 'note']));

            if (! AssetAssignment::query()
                ->where('organization_id', $organization->id)
                ->where('asset_id', $asset->id)
                ->whereNull('returned_at')
                ->exists()) {
                $asset->update(['status' => config('opsdeck.asset_status.in_stock')]);
            }

            return $assignment;
        });

        return to_route('assets.show', $assignment->asset_id)->with('status', '端末の返却を記録しました。');
    }

    private function organization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');

        return $organization;
    }
}
