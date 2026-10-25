<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnnouncementRecipientCountRequest;
use App\Http\Requests\StoreAnnouncementRequest;
use App\Jobs\SendAnnouncementEmail;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\Organization;
use App\Models\User;
use App\Services\AnnouncementRecipientQuery;
use App\Services\ProductEventRecorder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdminAnnouncementController extends Controller
{
    public function index(): View
    {
        $announcements = Announcement::query()->withCount([
            'deliveries as sent_count' => fn ($query) => $query->whereNotNull('sent_at'),
            'deliveries as failed_count' => fn ($query) => $query->whereNotNull('failed_at')->whereNull('sent_at')->whereNull('suppressed_at')->whereNull('manual_review_at'),
            'deliveries as manual_review_count' => fn ($query) => $query->whereNotNull('manual_review_at')->whereNull('sent_at')->whereNull('suppressed_at'),
            'deliveries as suppressed_count' => fn ($query) => $query->whereNotNull('suppressed_at'),
        ])->latest()->orderByDesc('id')->paginate(config('opsdeck.pagination.per_page'));

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
        $dispatchFailures = 0;
        $organizationIds = $announcement->organizations()->pluck('organizations.id')->map(fn (int|string $id): int => (int) $id)->all();
        $eligibleRecipientIds = $recipientQuery->eligibleFor($organizationIds)->select('users.id');
        $announcement->deliveries()->whereNotNull('failed_at')->whereNull('sent_at')->whereNull('suppressed_at')->whereNull('manual_review_at')->whereIn('user_id', $eligibleRecipientIds)->orderBy('id')->each(function (AnnouncementDelivery $delivery) use (&$queued, &$dispatchFailures, $announcement): void {
            if ($this->dispatchDelivery($announcement, $delivery)) {
                $queued++;
            } else {
                $dispatchFailures++;
            }
        });

        $status = $queued.'件のお知らせメールを再送キューへ登録しました。';
        if ($dispatchFailures > 0) {
            $status .= ' '.$dispatchFailures.'件はキュー登録に失敗しました。失敗件数を確認して再試行してください。';
        }

        return to_route('admin.announcements.index')->with('status', $status);
    }

    public function store(StoreAnnouncementRequest $request, AnnouncementRecipientQuery $recipientQuery): RedirectResponse
    {
        $validated = $request->validated();
        $organizationIds = $validated['organization_ids'] ?? [];
        $announcement = DB::transaction(function () use ($validated, $request, $organizationIds, $recipientQuery): Announcement {
            $announcement = Announcement::query()->create(['title' => $validated['title'], 'body' => $validated['body'], 'created_by' => $request->user()->id, 'published_at' => now()]);
            if ($organizationIds !== []) {
                $announcement->organizations()->sync($organizationIds);
            }
            $recipients = $recipientQuery->eligibleFor($organizationIds);
            $recipientCount = (clone $recipients)->count();
            $recipients->select('users.id')->orderBy('users.id')->chunkById(100, function (Collection $users) use ($announcement): void {
                $timestamp = now();
                AnnouncementDelivery::query()->insertOrIgnore($users->map(fn (User $user): array => [
                    'announcement_id' => $announcement->id,
                    'user_id' => $user->id,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])->all());
            });
            $announcement->update(['recipients_count' => $recipientCount]);
            app(ProductEventRecorder::class)->record('announcement_published');

            return $announcement;
        });
        $eligibleRecipientIds = $recipientQuery->eligibleFor($organizationIds)->select('users.id');
        $announcement->deliveries()
            ->whereNull('sent_at')
            ->whereNull('suppressed_at')
            ->whereNotIn('user_id', $eligibleRecipientIds)
            ->update(['suppressed_at' => now()]);
        $queued = 0;
        $dispatchFailures = 0;
        $announcement->deliveries()->whereIn('user_id', $eligibleRecipientIds)->orderBy('id')->chunkById(100, function (Collection $deliveries) use ($announcement, &$queued, &$dispatchFailures): void {
            foreach ($deliveries as $delivery) {
                if ($this->dispatchDelivery($announcement, $delivery)) {
                    $queued++;
                } else {
                    $dispatchFailures++;
                }
            }
        });

        $status = 'お知らせを公開し、'.$queued.'件をメール送信キューへ登録しました。';
        if ($dispatchFailures > 0) {
            $status .= ' '.$dispatchFailures.'件はキュー登録に失敗しました。管理画面の失敗件数から再送できます。';
        }

        return to_route('admin.announcements.index')->with('status', $status);
    }

    private function dispatchDelivery(Announcement $announcement, AnnouncementDelivery $delivery): bool
    {
        try {
            SendAnnouncementEmail::dispatch($announcement, (int) $delivery->user_id);

            return true;
        } catch (Throwable $exception) {
            $delivery->newQuery()->whereKey($delivery->getKey())->whereNull('sent_at')->update(['failed_at' => now()]);
            Log::warning('Announcement email queue dispatch failed.', [
                'announcement_id' => $announcement->id,
                'delivery_id' => $delivery->id,
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }
}
