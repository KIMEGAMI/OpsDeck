<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;

class OrganizationActivityRecorder
{
    public function record(Organization $organization): void
    {
        $now = now('UTC');

        DB::table('organization_activity_days')->upsert(
            [[
                'organization_id' => $organization->id,
                'activity_date' => $now->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['organization_id', 'activity_date'],
            ['organization_id'],
        );
    }
}
