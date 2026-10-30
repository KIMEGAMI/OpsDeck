<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\ProductEvent;
use App\Services\ProductEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductEventRecorderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_recorder_persists_event_without_personal_payload(): void
    {
        $organization = Organization::query()->create(['name' => 'Event Org', 'slug' => 'event-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        app(ProductEventRecorder::class)->record('registered', $organization);
        $event = ProductEvent::query()->latest('id')->firstOrFail();

        $this->assertSame('registered', $event->event_key);
        $this->assertSame($organization->id, $event->organization_id);
        $this->assertNull($event->getAttribute('payload'));
        $this->assertFalse(Schema::hasColumn('product_events', 'user_id'));
    }

    public function test_record_once_keeps_only_the_first_event_for_an_organization(): void
    {
        $organization = Organization::query()->create(['name' => 'Event Org', 'slug' => 'event-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $recorder = app(ProductEventRecorder::class);

        $recorder->recordOnce('first_asset_registered', $organization);
        $recorder->recordOnce('first_asset_registered', $organization);

        $this->assertSame(1, ProductEvent::query()
            ->where('event_key', 'first_asset_registered')
            ->where('organization_id', $organization->id)
            ->count());
        $this->assertDatabaseHas('product_events', [
            'event_key' => 'first_asset_registered',
            'organization_id' => $organization->id,
        ]);
    }

    public function test_record_once_does_not_record_an_event_for_a_deleted_organization(): void
    {
        $organization = Organization::query()->create(['name' => 'Event Org', 'slug' => 'event-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $organization->delete();

        app(ProductEventRecorder::class)->recordOnce('deleted_organization_event', $organization);

        $this->assertDatabaseMissing('product_events', [
            'event_key' => 'deleted_organization_event',
        ]);
    }

    public function test_first_business_change_is_recorded_only_after_success_and_only_once(): void
    {
        $organization = Organization::query()->create(['name' => 'Event Org', 'slug' => 'event-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $auditLog = [
            'organization_id' => $organization->id,
            'entity_type' => Organization::class,
            'entity_id' => $organization->id,
            'action' => AuditLog::OrganizationSettingsUpdated,
        ];

        try {
            DB::transaction(function () use ($auditLog): void {
                AuditLog::query()->create($auditLog);
                throw new \RuntimeException('Simulated rollback after the business change audit entry.');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated rollback after the business change audit entry.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'first_dashboard_action_completed',
        ]);

        AuditLog::query()->create($auditLog);
        AuditLog::query()->create($auditLog);

        $this->assertSame(1, ProductEvent::query()
            ->where('event_key', 'first_dashboard_action_completed')
            ->where('organization_id', $organization->id)
            ->count());
    }

    public function test_recorder_preserves_repeated_events_for_the_same_organization(): void
    {
        $organization = Organization::query()->create(['name' => 'Event Org', 'slug' => 'event-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $recorder = app(ProductEventRecorder::class);

        $recorder->record('member_invited', $organization);
        $recorder->record('member_invited', $organization);

        $this->assertSame(2, ProductEvent::query()
            ->where('event_key', 'member_invited')
            ->where('organization_id', $organization->id)
            ->count());
    }

    public function test_migration_refuses_duplicate_organization_events_without_deleting_them(): void
    {
        $organization = Organization::query()->create(['name' => 'Event Org', 'slug' => 'event-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $event = [
            'event_key' => 'migration_duplicate_guard',
            'organization_id' => $organization->id,
            'occurred_at' => now(),
        ];
        ProductEvent::query()->create($event);
        ProductEvent::query()->create($event);

        try {
            $migration = require base_path('database/migrations/2026_10_10_114059_add_unique_constraint_to_product_events_table.php');
            $migration->up();
            $this->fail('The migration should refuse existing duplicate organization events.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Cannot add the product event uniqueness constraint while duplicate organization events exist.', $exception->getMessage());
            $this->assertSame(2, ProductEvent::query()
                ->where('organization_id', $organization->id)
                ->where('event_key', 'migration_duplicate_guard')
                ->count());
        } finally {
            ProductEvent::query()->where('organization_id', $organization->id)->delete();
            $organization->delete();
        }
    }

    public function test_migration_refuses_rollback_when_account_identifiers_cannot_be_restored(): void
    {
        $migration = require base_path('database/migrations/2026_10_11_135640_drop_user_id_from_product_events_table.php');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Product-event actor identifiers cannot be restored; use a forward migration.');

        $migration->down();
    }

    public function test_recent_forward_only_migrations_refuse_rollback(): void
    {
        $migrationFiles = [
            '2026_10_14_040857_add_value_mappings_to_import_mappings_and_import_profiles_tables.php',
            '2026_10_14_130151_create_work_time_measurements_table.php',
            '2026_10_14_131946_add_active_process_key_to_work_time_measurements_table.php',
            '2026_10_14_150129_add_ai_mapping_review_counts_to_import_mappings_table.php',
        ];

        foreach ($migrationFiles as $migrationFile) {
            $migration = require base_path('database/migrations/'.$migrationFile);

            try {
                $migration->down();
                $this->fail("The migration {$migrationFile} should refuse rollback.");
            } catch (\LogicException $exception) {
                $this->assertStringContainsString('apply a new migration instead.', $exception->getMessage());
            }
        }
    }

    public function test_migration_refuses_to_restore_unique_event_keys_when_repeated_events_exist(): void
    {
        $organization = Organization::query()->create(['name' => 'Event Org', 'slug' => 'event-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $event = [
            'event_key' => 'repeatable_event',
            'organization_id' => $organization->id,
            'occurred_at' => now(),
        ];
        ProductEvent::query()->create($event);
        ProductEvent::query()->create($event);
        $migration = require base_path('database/migrations/2026_10_11_220330_drop_product_event_organization_event_unique_index.php');

        try {
            $migration->down();
            $this->fail('The migration must preserve repeated product events when restoring the unique constraint.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Cannot restore the product event uniqueness constraint while duplicate organization events exist.', $exception->getMessage());
            $this->assertSame(2, ProductEvent::query()
                ->where('organization_id', $organization->id)
                ->where('event_key', 'repeatable_event')
                ->count());
        }

    }
}
