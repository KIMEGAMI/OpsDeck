<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnnouncementRecipientCountRequest;
use App\Http\Requests\StoreAnnouncementRequest;
use App\Jobs\SendAnnouncementEmail;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\Organization;
use App\Services\AnnouncementRecipientQuery;
use App\Services\ProductEventRecorder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminAnnouncementController extends Controller
{
    public function index(): View
    {
        $announcements = Announcement::query()->withCount(['deliveries as sent_count' => fn ($query) => $query->whereNotNull('sent_at'), 'deliveries as failed_count' => fn ($query) => $query->whereNotNull('failed_at')->whereNull('sent_at')->whereNull('suppressed_at'), 'deliveries as suppressed_count' => fn ($query) => $query->whereNotNull('suppressed_at')])->latest()->orderByDesc('id')->paginate(config('opsdeck.pagination.per_page'));

        return view('admin.announcements.index', compact('announcements'));
    }

    public function create(AnnouncementRecipientQuery $recipientQuery): View
    {
        $organizations = Organization::query()->orderBy('name')->get(['id', 'name']);
        $eligibleRecipientCount = $recipientQuery->eligibleFor()->count();

        return view('admin.announcements.create', compact('organizations', 'eligibleRecipientCount'));
    }

    public function recipientCount(AnnouncementRecipientCountRequest $request, AnnouncementRecipientQuery $recipientQuery): JsonResponse
    {
        $organizationIds = $request->validated('organization_ids', []);
        $count = $recipientQuery->eligibleFor($organizationIds)->count();

        return response()->json(['count' => $count]);
    }

    public function retry(Announcement $announcement, AnnouncementRecipientQuery $recipientQuery): RedirectResponse
    {
        $queued = 0;
        $organizationIds = $announcement->organizations()->pluck('organizations.id')->map(fn (int|string $id): int => (int) $id)->all();
        $eligibleRecipientIds = $recipientQuery->eligibleFor($organizationIds)->select('users.id');
        $announcement->deliveries()->whereNotNull('failed_at')->whereNull('sent_at')->whereNull('suppressed_at')->whereIn('user_id', $eligibleRecipientIds)->orderBy('id')->each(function (AnnouncementDelivery $delivery) use (&$queued, $announcement): void {
            SendAnnouncementEmail::dispatch($announcement, (int) $delivery->user_id);
            $queued++;
        });

        return to_route('admin.announcements.index')->with('status', $queued.'件のお知らせメールを再送キューへ登録しました。');
    }

    public function store(StoreAnnouncementRequest $request, AnnouncementRecipientQuery $recipientQuery): RedirectResponse
    {
        $validated = $request->validated();
        $organizationIds = $validated['organization_ids'] ?? [];
        $announcement = DB::transaction(function () use ($validated, $request, $organizationIds): Announcement {
            $announcement = Announcement::query()->create(['title' => $validated['title'], 'body' => $validated['body'], 'created_by' => $request->user()->id, 'published_at' => now()]);
            if ($organizationIds !== []) {
                $announcement->organizations()->sync($organizationIds);
            }

            return $announcement;
        });
        app(ProductEventRecorder::class)->record('announcement_published');
        $recipients = $recipientQuery->eligibleFor($organizationIds)->select('id');
        $recipientCount = (clone $recipients)->count();
        $recipients->orderBy('id')->chunkById(100, function (Collection $users) use ($announcement): void {
            foreach ($users as $user) {
                SendAnnouncementEmail::dispatch($announcement, (int) $user->id);
            }
        });
        $announcement->update(['recipients_count' => $recipientCount]);

        return to_route('admin.announcements.index')->with('status', 'お知らせを公開し、メール送信キューへ登録しました。');
    }
}
