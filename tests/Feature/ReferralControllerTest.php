<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProductEvent;
use App\Models\ReferralCode;
use App\Models\ReferralConversion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReferralControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'opsdeck.plans.free.capabilities.referrals' => true,
            'opsdeck.plans.premium.capabilities.referrals' => true,
        ]);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_admin_can_get_referral_link_and_repeated_public_clicks_are_counted(): void
    {
        [$admin, $organization] = $this->member(MembershipRole::Admin);
        $this->actingAs($admin)->withSession(['current_organization_id' => $organization->id])->get(route('referrals.show'))->assertOk()->assertSee('紹介リンク')->assertSee('割引・報酬は自動適用されず');
        $referral = ReferralCode::query()->where('organization_id', $organization->id)->firstOrFail();
        ReferralConversion::query()->create(['referral_code_id' => $referral->id, 'organization_id' => $organization->id]);
        $this->actingAs($admin)->withSession(['current_organization_id' => $organization->id])->get(route('referrals.show'))->assertOk()->assertSee('登録組織数：1');
        $this->get(route('referrals.redirect', $referral->code))->assertRedirect(route('register'))->assertSessionHas('referral_code', $referral->code);
        $this->get(route('referrals.redirect', $referral->code))->assertRedirect(route('register'))->assertSessionHas('referral_code', $referral->code);

        $this->assertDatabaseHas('referral_codes', ['id' => $referral->id, 'click_count' => 2]);
        $this->assertSame(0, ProductEvent::query()
            ->where('organization_id', $organization->id)
            ->where('event_key', 'referral_clicked')
            ->count());
    }

    public function test_referral_routes_are_disabled_outside_the_release_scope(): void
    {
        [$admin, $organization] = $this->member(MembershipRole::Admin);
        $referral = ReferralCode::query()->create([
            'organization_id' => $organization->id,
            'code' => 'release-disabled-'.Str::lower(Str::random(8)),
            'active' => true,
        ]);
        config(['opsdeck.release_features.referrals' => false]);

        $this->actingAs($admin)
            ->withSession(['current_organization_id' => $organization->id])
            ->get(route('referrals.show'))
            ->assertNotFound();
        $this->get(route('referrals.redirect', $referral->code))->assertNotFound();

        $this->assertDatabaseHas('referral_codes', ['id' => $referral->id, 'click_count' => 0]);
    }

    public function test_registration_ignores_a_stale_referral_session_outside_the_release_scope(): void
    {
        Notification::fake();
        [, $organization] = $this->member(MembershipRole::Owner);
        $referral = ReferralCode::query()->create([
            'organization_id' => $organization->id,
            'code' => 'stale-'.Str::lower(Str::random(8)),
            'active' => true,
        ]);
        config(['opsdeck.release_features.referrals' => false]);

        $this->withSession(['referral_code' => $referral->code])
            ->post(route('register.store'), [
                'name' => '紹介停止後の登録',
                'email' => 'release-disabled-'.Str::uuid().'@example.test',
                'password' => 'secure-password-123',
                'password_confirmation' => 'secure-password-123',
                'organization_name' => '紹介停止後の組織',
            ])
            ->assertRedirect(route('onboarding.index'));

        $registeredOrganization = Organization::query()->where('name', '紹介停止後の組織')->firstOrFail();
        $this->assertDatabaseMissing('referral_conversions', [
            'referral_code_id' => $referral->id,
            'organization_id' => $registeredOrganization->id,
        ]);
        $this->assertDatabaseMissing('product_events', [
            'organization_id' => $registeredOrganization->id,
            'event_key' => 'referral_registered',
        ]);
    }

    public function test_inactive_referral_code_is_not_available(): void
    {
        $referral = ReferralCode::query()->create(['organization_id' => $this->member(MembershipRole::Owner)[1]->id, 'code' => 'inactive-'.Str::lower(Str::random(8)), 'active' => false]);
        $this->get(route('referrals.redirect', $referral->code))->assertNotFound();
    }

    public function test_referral_redirects_are_rate_limited_without_counting_rejected_requests(): void
    {
        [, $organization] = $this->member(MembershipRole::Owner);
        $referral = ReferralCode::query()->create(['organization_id' => $organization->id, 'code' => 'limited-'.Str::lower(Str::random(8)), 'active' => true]);
        config(['opsdeck.referrals.redirect_attempts_per_minute' => 1]);

        $this->get(route('referrals.redirect', $referral->code))->assertRedirect(route('register'));
        $this->get(route('referrals.redirect', $referral->code))->assertTooManyRequests();

        $this->assertDatabaseHas('referral_codes', ['id' => $referral->id, 'click_count' => 1]);
        $this->assertSame(0, ProductEvent::query()->where('organization_id', $organization->id)->where('event_key', 'referral_clicked')->count());
    }

    public function test_referral_registration_is_recorded_without_referrer_personal_data(): void
    {
        [, $organization] = $this->member(MembershipRole::Owner);
        $referral = ReferralCode::query()->create(['organization_id' => $organization->id, 'code' => 'convert-'.Str::lower(Str::random(8)), 'active' => true]);

        $this->get(route('referrals.redirect', $referral->code));
        $this->post(route('register.store'), ['name' => '紹介登録', 'email' => 'conversion-'.Str::uuid().'@example.test', 'password' => 'secure-password-123', 'password_confirmation' => 'secure-password-123', 'organization_name' => '紹介組織'])->assertRedirect(route('onboarding.index'));

        $conversion = ReferralConversion::query()->where('referral_code_id', $referral->id)->firstOrFail();
        $this->assertSame($referral->organization_id, $conversion->referralCode->organization_id);
        $this->assertDatabaseHas('product_events', ['event_key' => 'referral_registered', 'organization_id' => $conversion->organization_id]);
    }

    /** @return array{0: User, 1: Organization} */
    private function member(MembershipRole $role): array
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'referral-'.$suffix.'@example.test']);
        $organization = Organization::query()->create(['name' => 'Referral '.$suffix, 'slug' => 'referral-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }
}
