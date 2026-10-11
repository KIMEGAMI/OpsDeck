<?php

namespace App\Services;

use App\Models\StripeWebhookEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;

class StripeWebhookHealthService
{
    public function processingErrorCount(): int
    {
        if (! Schema::hasTable('stripe_webhook_events')) {
            return 0;
        }

        return StripeWebhookEvent::query()->whereNotNull('processing_error')->count();
    }

    public function staleUnprocessedCount(): int
    {
        if (! Schema::hasTable('stripe_webhook_events')) {
            return 0;
        }

        return $this->staleUnprocessedQuery()->count();
    }

    /** @return Collection<int, StripeWebhookEvent> */
    public function staleUnprocessedEvents(): Collection
    {
        if (! Schema::hasTable('stripe_webhook_events')) {
            return new Collection;
        }

        return $this->staleUnprocessedQuery()
            ->latest()
            ->limit((int) config('opsdeck.pagination.per_page'))
            ->get(['stripe_event_id', 'event_type', 'created_at']);
    }

    private function staleUnprocessedQuery(): Builder
    {
        $cutoff = now()->subMinutes((int) config('opsdeck.stripe_webhook.stale_after_minutes'));

        return StripeWebhookEvent::query()
            ->whereNull('processed_at')
            ->whereNull('processing_error')
            ->where('created_at', '<=', $cutoff);
    }
}
