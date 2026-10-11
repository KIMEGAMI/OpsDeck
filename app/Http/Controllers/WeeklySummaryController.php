<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateWeeklySummaryPreferenceRequest;
use App\Models\Organization;
use App\Models\UserNotificationPreference;
use App\Services\WeeklySummaryBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WeeklySummaryController extends Controller
{
    public function show(Request $request): View
    {
        $organization = $this->organization($request);
        $preference = UserNotificationPreference::query()->firstOrCreate(['user_id' => $request->user()->id, 'organization_id' => $organization->id], ['weekly_summary_enabled' => true, 'announcement_enabled' => true]);
        $summary = app(WeeklySummaryBuilder::class)->build($organization->id);

        return view('weekly-summary.show', compact('organization', 'summary', 'preference'));
    }

    public function updatePreference(UpdateWeeklySummaryPreferenceRequest $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $attributes = ['weekly_summary_enabled' => $request->boolean('weekly_summary_enabled')];
        if ($request->has('announcement_enabled')) {
            $attributes['announcement_enabled'] = $request->boolean('announcement_enabled');
        }
        UserNotificationPreference::query()->updateOrCreate(['user_id' => $request->user()->id, 'organization_id' => $organization->id], $attributes);

        return to_route('weekly-summary.show')->with('status', '週次サマリーの配信設定を更新しました。');
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('currentOrganization');
    }
}
