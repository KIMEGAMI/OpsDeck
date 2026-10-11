<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Services\ProductEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AnnouncementReadController extends Controller
{
    public function store(Request $request, Announcement $announcement): RedirectResponse
    {
        $organization = $request->attributes->get('currentOrganization');
        $isPublished = $announcement->published_at !== null && $announcement->published_at->lessThanOrEqualTo(now());
        $isVisible = $announcement->organizations()->count() === 0 || $announcement->organizations()->where('organizations.id', $organization->id)->exists();
        abort_unless($isPublished && $isVisible, 404);
        AnnouncementRead::query()->updateOrCreate(['announcement_id' => $announcement->id, 'user_id' => $request->user()->id], ['read_at' => now()]);
        app(ProductEventRecorder::class)->recordOnce('first_dashboard_action_completed', $organization);

        return to_route('dashboard')->with('status', 'お知らせを既読にしました。');
    }
}
