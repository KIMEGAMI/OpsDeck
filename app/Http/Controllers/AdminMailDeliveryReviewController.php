<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResolveManualDeliveryReviewRequest;
use App\Models\AnnouncementDelivery;
use App\Models\WeeklySummaryDelivery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class AdminMailDeliveryReviewController extends Controller
{
    public function index(): View
    {
        $perPage = config('opsdeck.pagination.per_page');
        $announcementDeliveries = AnnouncementDelivery::query()
            ->whereNotNull('manual_review_at')
            ->whereNull('sent_at')
            ->whereNull('suppressed_at')
            ->with(['announcement:id,title', 'user:id,name,email'])
            ->latest('manual_review_at')
            ->paginate($perPage, ['*'], 'announcement_page');
        $weeklySummaryDeliveries = WeeklySummaryDelivery::query()
            ->whereNotNull('manual_review_at')
            ->whereNull('sent_at')
            ->with(['organization:id,name', 'user:id,name,email'])
            ->latest('manual_review_at')
            ->paginate($perPage, ['*'], 'weekly_summary_page');

        return view('admin.mail-delivery-reviews', compact('announcementDeliveries', 'weeklySummaryDeliveries'));
    }

    public function resolveAnnouncement(ResolveManualDeliveryReviewRequest $request, AnnouncementDelivery $delivery): RedirectResponse
    {
        if (! $this->markAsDelivered($request, $delivery)) {
            return to_route('admin.mail-delivery-reviews.index')->with('status', 'このお知らせメールは確認待ちではありません。');
        }

        return to_route('admin.mail-delivery-reviews.index')->with('status', 'お知らせメールを配信済みとして記録しました。');
    }

    public function resolveWeeklySummary(ResolveManualDeliveryReviewRequest $request, WeeklySummaryDelivery $delivery): RedirectResponse
    {
        if (! $this->markAsDelivered($request, $delivery)) {
            return to_route('admin.mail-delivery-reviews.index')->with('status', 'この週次サマリーメールは確認待ちではありません。');
        }

        return to_route('admin.mail-delivery-reviews.index')->with('status', '週次サマリーメールを配信済みとして記録しました。');
    }

    private function markAsDelivered(ResolveManualDeliveryReviewRequest $request, AnnouncementDelivery|WeeklySummaryDelivery $delivery): bool
    {
        $query = $delivery->newQuery()
            ->whereKey($delivery->getKey())
            ->whereNotNull('manual_review_at')
            ->whereNull('sent_at');

        if ($delivery instanceof AnnouncementDelivery) {
            $query->whereNull('suppressed_at');
        }

        return $query->update([
            'sent_at' => now(),
            'failed_at' => null,
            'manual_review_resolved_by' => (int) $request->user()->getAuthIdentifier(),
        ]) === 1;
    }
}
