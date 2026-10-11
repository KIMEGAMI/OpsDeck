<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductEvent;
use App\Models\User;
use App\Services\OrganizationActivityRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OrganizationActivityMetricsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_monthly_active_organizations_count_only_successful_organization_activity_once_per_day(): void
    {
        [$member, $organization] = $this->organizationMember();
        $admin = $this->platformAdmin();
        $activeOrganizationsBefore = $this->monthlyActiveOrganizations();
        ProductEvent::query()->create(['organization_id' => $organization->id, 'event_key' => 'registered', 'occurred_at' => now()]);
        ProductEvent::query()->create(['organization_id' => $organization->id, 'event_key' => 'billing_active', 'occurred_at' => now()]);

        $this->actingAs($admin)
            ->get(route('admin.product-metrics'))
            ->assertOk()
            ->assertSee('月次アクティブ組織数（直近30日）：'.$activeOrganizationsBefore);

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('dashboard'))
            ->assertOk();
        $this->actingAs($member)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertSame(1, DB::table('organization_activity_days')->where('organization_id', $organization->id)->count());

        $this->actingAs($admin)
            ->get(route('admin.product-metrics'))
            ->assertOk()
            ->assertSee('月次アクティブ組織数（直近30日）：'.($activeOrganizationsBefore + 1));

        $csv = $this->actingAs($admin)->get(route('admin.product-metrics.export'))->streamedContent();
        $this->assertStringContainsString('monthly_active_organizations,'.($activeOrganizationsBefore + 1), $csv);
    }

    public function test_activity_recording_failure_does_not_break_a_successful_organization_request(): void
    {
        [$member, $organization] = $this->organizationMember();
        $activityRecorder = Mockery::mock(OrganizationActivityRecorder::class);
        $activityRecorder->shouldReceive('record')
            ->once()
            ->with(Mockery::on(fn (Organization $recordedOrganization): bool => $recordedOrganization->is($organization)))
            ->andThrow(new QueryException('sqlite', 'insert into organization_activity_days', [], new RuntimeException('database unavailable')));
        $this->app->instance(OrganizationActivityRecorder::class, $activityRecorder);

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_thirty_day_retention_uses_recent_real_activity_instead_of_billing_events(): void
    {
        $registrationCutoff = now()->subDays(30);
        $registeredOrganizationIds = ProductEvent::query()
            ->where('event_key', 'registered')
            ->whereNotNull('organization_id')
            ->where('occurred_at', '<=', $registrationCutoff)
            ->pluck('organization_id')
            ->unique();
        $retentionStart = now('UTC')->subDays(29)->toDateString();
        $today = now('UTC')->toDateString();
        $eligibleOrganizationsBefore = $registeredOrganizationIds->count();
        $monthlyActiveOrganizationsBefore = $this->monthlyActiveOrganizations();
        $retainedOrganizationsBefore = DB::table('organization_activity_days')
            ->whereIn('organization_id', $registeredOrganizationIds)
            ->whereBetween('activity_date', [$retentionStart, $today])
            ->distinct('organization_id')
            ->count('organization_id');
        $inactiveOrganization = $this->organization('activity-inactive');
        $activeOrganization = $this->organization('activity-active');
        $staleActivityOrganization = $this->organization('activity-stale');
        $registeredAt = now()->subDays(45);

        ProductEvent::query()->create(['organization_id' => $inactiveOrganization->id, 'event_key' => 'registered', 'occurred_at' => $registeredAt]);
        ProductEvent::query()->create(['organization_id' => $inactiveOrganization->id, 'event_key' => 'billing_active', 'occurred_at' => now()]);
        ProductEvent::query()->create(['organization_id' => $activeOrganization->id, 'event_key' => 'registered', 'occurred_at' => $registeredAt]);
        ProductEvent::query()->create(['organization_id' => $staleActivityOrganization->id, 'event_key' => 'registered', 'occurred_at' => $registeredAt]);
        DB::table('organization_activity_days')->insert([
            'organization_id' => $activeOrganization->id,
            'activity_date' => now('UTC')->subDays(3)->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('organization_activity_days')->insert([
            'organization_id' => $staleActivityOrganization->id,
            'activity_date' => now('UTC')->subDays(30)->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eligibleOrganizations = $eligibleOrganizationsBefore + 3;
        $retainedOrganizations = $retainedOrganizationsBefore + 1;
        $retentionRate = round(($retainedOrganizations / $eligibleOrganizations) * 100, 2);

        $this->actingAs($this->platformAdmin())
            ->get(route('admin.product-metrics'))
            ->assertOk()
            ->assertSee('月次アクティブ組織数（直近30日）：'.($monthlyActiveOrganizationsBefore + 1))
            ->assertSee('30日継続率：'.$retentionRate.'%（登録から30日以上経過した組織のうち、直近30日に利用した組織：'.$retainedOrganizations.' / '.$eligibleOrganizations.'）');
    }

    /** @return array{0: User, 1: Organization} */
    private function organizationMember(): array
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'activity-'.$suffix.'@example.test']);
        $organization = $this->organization('activity-'.$suffix);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => MembershipRole::Owner,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        return [$user, $organization];
    }

    private function organization(string $slugPrefix): Organization
    {
        return Organization::query()->create([
            'name' => Str::headline($slugPrefix),
            'slug' => $slugPrefix.'-'.Str::lower(Str::random(8)),
            'timezone' => config('opsdeck.default_timezone'),
            'plan_code' => config('opsdeck.default_plan_code'),
            'billing_status' => config('opsdeck.default_billing_status'),
        ]);
    }

    private function platformAdmin(): User
    {
        $admin = User::factory()->create(['email' => 'metrics-admin-'.Str::uuid().'@example.test']);
        config(['opsdeck.platform_admin_emails' => [$admin->email]]);

        return $admin;
    }

    private function monthlyActiveOrganizations(): int
    {
        return DB::table('organization_activity_days')
            ->whereBetween('activity_date', [now('UTC')->subDays(29)->toDateString(), now('UTC')->toDateString()])
            ->distinct('organization_id')
            ->count('organization_id');
    }
}
