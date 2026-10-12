<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\ProductEvent;
use Illuminate\Support\Facades\DB;

class ProductEventRecorder
{
    public function record(string $eventKey, ?Organization $organization = null): void
    {
        ProductEvent::query()->create(['event_key' => $eventKey, 'organization_id' => $organization?->id, 'occurred_at' => now()]);
    }

    public function recordOnce(string $eventKey, Organization $organization): void
    {
        DB::transaction(function () use ($eventKey, $organization): void {
            $lockedOrganization = Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedOrganization === null) {
                return;
            }

            $exists = ProductEvent::query()
                ->where('event_key', $eventKey)
                ->where('organization_id', $lockedOrganization->id)
                ->exists();

            if (! $exists) {
                $this->record($eventKey, $lockedOrganization);
            }
        });
    }

    public function recordFirstDashboardAction(Organization $organization): void
    {
        $this->recordOnce('first_dashboard_action_completed', $organization);
    }
}
