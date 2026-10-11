<?php

namespace App\Jobs;

use App\Http\Controllers\StripeWebhookController;
use App\Models\StripeWebhookEvent;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessStripeWebhookEvent implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    /** @param array<string, mixed> $eventData */
    public function __construct(public int $eventRecordId, public array $eventData)
    {
        $this->tries = max(1, (int) config('services.stripe.webhook_job_tries'));
        $this->timeout = max(1, (int) config('services.stripe.webhook_job_timeout_seconds'));
    }

    public function uniqueId(): string
    {
        return (string) $this->eventRecordId;
    }

    public function uniqueFor(): int
    {
        return max(1, (int) config('services.stripe.webhook_job_unique_seconds'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return config('services.stripe.webhook_job_backoff_seconds');
    }

    public function handle(StripeWebhookController $controller): void
    {
        $controller->processRecordedEvent($this->eventRecordId, $this->eventData);
    }

    public function failed(?Throwable $exception): void
    {
        StripeWebhookEvent::query()
            ->whereKey($this->eventRecordId)
            ->whereNull('processed_at')
            ->update(['processing_error' => 'event_processing_failed']);
    }
}
