<?php

namespace Tests\Feature\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    public function test_opsdeck_schema_migrates_forward_without_destructive_commands(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        $this->assertTrue(Schema::hasTable('organizations'));
        $this->assertTrue(Schema::hasTable('organization_memberships'));
        $this->assertTrue(Schema::hasTable('departments'));
        $this->assertTrue(Schema::hasTable('employees'));
        $this->assertTrue(Schema::hasTable('assets'));
        $this->assertTrue(Schema::hasTable('asset_assignments'));
        $this->assertTrue(Schema::hasTable('saas_services'));
        $this->assertTrue(Schema::hasTable('saas_assignments'));
        $indexNames = collect(Schema::getIndexes('stripe_webhook_events'))->pluck('name');
        $this->assertContains('stripe_webhook_pending_idx', $indexNames);
        $aiUsageIndexes = collect(Schema::getIndexes('ai_usage_logs'));
        $this->assertTrue($aiUsageIndexes->contains(static fn (array $index): bool => $index['name'] === 'ai_usage_organization_created_at_idx'
            && $index['columns'] === ['organization_id', 'created_at']));
    }
}
