<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class RecoveryGuideController extends Controller
{
    public function show(Request $request, string $type): View
    {
        abort_unless(array_key_exists($type, config('opsdeck.recovery_guides', [])), 404);
        $guide = config('opsdeck.recovery_guides.'.$type);
        $releaseFeature = $guide['release_feature'] ?? null;
        abort_unless(! is_string($releaseFeature) || config('opsdeck.release_features.'.$releaseFeature) === true, 404);

        $organization = $request->attributes->get('currentOrganization');
        $canManageBilling = $request->user()->can('manageBilling', $organization);
        $canManageOrganizationSettings = $request->user()->can('update', $organization);

        return view('recovery-guides.show', compact('guide', 'type', 'canManageBilling', 'canManageOrganizationSettings'));
    }
}
