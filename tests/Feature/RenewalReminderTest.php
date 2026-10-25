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
        Contract::query()->create(['organization_id' => $organization->id, 'vendor_name' => 'Deadline', 'contract_name' => 'Cancellation deadline only', 'contract_type' => 'service', 'billing_cycle' => 'monthly', 'amount' => 1000, 'currency' => 'JPY', 'auto_renew' => true, 'status' => 'active', 'cancellation_deadline' => today()->addDays(5)]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->get(route('reminders.renewals'))
            ->assertOk()
            ->assertSee('Overdue')
            ->assertSee('Upcoming')
            ->assertSee('Cancellation deadline only')
            ->assertSee('解約申込期限')
            ->assertSee(route('contracts.show', Contract::query()->where('contract_name', 'Cancellation deadline only')->first()), false);
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

    public function test_upcoming_cancellation_deadline_is_not_hidden_by_an_overdue_renewal_date(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'reminder-overdue-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Reminder Overdue '.$suffix, 'slug' => 'reminder-overdue-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => MembershipRole::Viewer, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        $contract = Contract::query()->create([
            'organization_id' => $organization->id,
            'vendor_name' => 'Actionable deadline vendor',
            'contract_name' => 'Actionable cancellation deadline',
            'contract_type' => 'service',
            'billing_cycle' => 'monthly',
            'amount' => 1000,
            'currency' => 'JPY',
            'auto_renew' => true,
            'status' => 'active',
            'renewal_date' => today()->subDay(),
            'cancellation_deadline' => today()->addDays(5),
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('reminders.renewals'))
            ->assertOk()
            ->assertViewHas('reminders', fn (array $reminders): bool => $reminders['within_30_days']->contains(
                fn (Contract $reminder): bool => $reminder->is($contract)
                    && $reminder->reminder_date->toDateString() === today()->addDays(5)->toDateString()
                    && $reminder->reminder_date_label === '解約申込期限',
            ) && ! $reminders['overdue']->contains(fn (Contract $reminder): bool => $reminder->is($contract)));
    }
}
