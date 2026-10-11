<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class RenewalReminderTest extends TestCase
{
    public function test_viewer_can_see_overdue_and_upcoming_renewals(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'reminder-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Reminder '.$suffix, 'slug' => 'reminder-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => 'Old', 'contract_name' => 'Overdue', 'contract_type' => 'service', 'billing_cycle' => 'monthly', 'amount' => 1000, 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active', 'renewal_date' => now()->subDay()]);
        Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => 'Soon', 'contract_name' => 'Upcoming', 'contract_type' => 'service', 'billing_cycle' => 'monthly', 'amount' => 1000, 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active', 'renewal_date' => now()->addDays(10)]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('reminders.renewals'))->assertOk()->assertSee('Overdue')->assertSee('Upcoming');
    }

    public function test_free_plan_cannot_open_renewal_reminders_by_direct_url(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'reminder-free-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Free Reminder '.$suffix, 'slug' => 'free-reminder-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.free_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('reminders.renewals'))
            ->assertForbidden();
    }

    public function test_reminder_uses_the_earliest_relevant_date_when_both_dates_are_set(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'reminder-date-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Reminder Date '.$suffix, 'slug' => 'reminder-date-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => 'Soon end', 'contract_name' => 'End date is sooner', 'contract_type' => 'service', 'billing_cycle' => 'monthly', 'amount' => 1000, 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active', 'renewal_date' => today()->addDays(60), 'ends_at' => today()->addDays(10)]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('reminders.renewals'))
            ->assertOk()
            ->assertSee('End date is sooner')
            ->assertSee(today()->addDays(10)->toDateString())
            ->assertDontSee(today()->addDays(60)->toDateString());
    }
}
