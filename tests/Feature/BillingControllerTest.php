<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingControllerTest extends TestCase
{
    public function test_owner_can_start_checkout_without_exposing_secret(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $customerId = 'cus_'.Str::uuid();
        config(['services.stripe.secret' => 'sk_test_secret', 'services.stripe.prices.premium' => 'price_test']);
        Http::fake([
            'https://api.stripe.com/v1/prices/price_test' => Http::response([
                'active' => true,
                'type' => 'recurring',
                'billing_scheme' => 'per_unit',
                'currency' => 'jpy',
                'unit_amount' => 9800,
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ], 200),
            'https://api.stripe.com/v1/customers' => Http::response(['id' => $customerId], 200),
            'https://api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.test/session'], 200),
        ]);

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('billing.checkout'));
        $response->assertRedirect('https://checkout.stripe.test/session');
        $this->assertSame($customerId, $organization->refresh()->stripe_customer_id);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && $request['line_items[0][price]'] === 'price_test'
            && $request['client_reference_id'] === (string) $organization->id
            && $request['subscription_data[metadata][plan_code]'] === 'premium');
        Http::assertSent(fn ($request): bool => $request->method() === 'GET' && $request->url() === 'https://api.stripe.com/v1/prices/price_test');
        Http::assertSent(fn ($request): bool => ! str_contains($request->body(), 'sk_test_secret'));
    }

    public function test_checkout_attempts_are_rate_limited_per_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Owner);
        config([
            'opsdeck.billing.checkout_attempts_per_minute' => 1,
            'services.stripe.secret' => 'sk_test_secret',
            'services.stripe.prices.premium' => 'price_test',
        ]);
        Http::fake([
            'https://api.stripe.com/v1/prices/price_test' => Http::response([
                'active' => true,
                'type' => 'recurring',
                'billing_scheme' => 'per_unit',
                'currency' => 'jpy',
                'unit_amount' => 9800,
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ], 200),
            'https://api.stripe.com/v1/customers' => Http::response(['id' => 'cus_'.Str::uuid()], 200),
            'https://api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.test/session'], 200),
        ]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.checkout'))
            ->assertRedirect('https://checkout.stripe.test/session');

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.checkout'))
            ->assertTooManyRequests();

        Http::assertSentCount(3);
    }

    public function test_checkout_retries_use_the_same_stripe_idempotency_key(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $organization->update(['stripe_customer_id' => 'cus_existing']);
        config([
            'opsdeck.billing.checkout_attempts_per_minute' => 2,
            'services.stripe.secret' => 'sk_test_secret',
            'services.stripe.prices.premium' => 'price_test',
        ]);
        $checkoutIdempotencyKeys = [];
        Http::fake(function (ClientRequest $request) use (&$checkoutIdempotencyKeys) {
            if ($request->url() === 'https://api.stripe.com/v1/prices/price_test') {
                return Http::response([
                    'active' => true,
                    'type' => 'recurring',
                    'billing_scheme' => 'per_unit',
                    'currency' => 'jpy',
                    'unit_amount' => 9800,
                    'recurring' => ['interval' => 'month', 'interval_count' => 1],
                ], 200);
            }

            if ($request->url() === 'https://api.stripe.com/v1/checkout/sessions') {
                $checkoutIdempotencyKeys[] = $request->header('Idempotency-Key')[0] ?? null;

                return Http::response(['url' => 'https://checkout.stripe.test/session'], 200);
            }

            return Http::response([], 404);
        });

        foreach (range(1, 2) as $_attempt) {
            $this->actingAs($user)
                ->withSession(['current_organization_id' => $organization->id])
                ->post(route('billing.checkout'))
                ->assertRedirect('https://checkout.stripe.test/session');
        }

        $this->assertCount(2, $checkoutIdempotencyKeys);
        $this->assertNotNull($checkoutIdempotencyKeys[0]);
        $this->assertSame($checkoutIdempotencyKeys[0], $checkoutIdempotencyKeys[1]);
    }

    public function test_checkout_fails_closed_when_stripe_price_does_not_match_the_advertised_monthly_price(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        config(['services.stripe.secret' => 'sk_test_secret', 'services.stripe.prices.premium' => 'price_test']);
        Http::fake([
            'https://api.stripe.com/v1/prices/price_test' => Http::response([
                'active' => true,
                'type' => 'recurring',
                'billing_scheme' => 'per_unit',
                'currency' => 'jpy',
                'unit_amount' => 980000,
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ], 200),
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.checkout'))
            ->assertServiceUnavailable();

        $this->assertNull($organization->refresh()->stripe_customer_id);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v1/customers') || str_contains($request->url(), '/v1/checkout/sessions'));
    }

    public function test_checkout_fails_closed_when_premium_price_is_not_configured(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        config(['services.stripe.secret' => 'sk_test_secret', 'services.stripe.prices.premium' => null]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('billing.checkout'))->assertServiceUnavailable();
    }

    public function test_billing_page_shows_current_plan_and_hides_duplicate_checkout_for_premium(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $organization->update(['plan_code' => 'premium', 'billing_status' => 'active', 'stripe_customer_id' => 'cus_'.Str::uuid()]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('現在のプラン')
            ->assertSee('Premium')
            ->assertSee('利用中')
            ->assertSee('Customer Portalを開く')
            ->assertDontSee('Stripe Checkoutを開始');
    }

    public function test_past_due_billing_page_shows_recovery_steps_and_portal_action(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $pastDueSince = now()->subDays(2);
        $organization->update(['plan_code' => 'premium', 'billing_status' => 'past_due', 'billing_past_due_since' => $pastDueSince, 'billing_failure_event_id' => 'evt_payment_failed_123', 'stripe_customer_id' => 'cus_'.Str::uuid()]);
        $expectedDeadline = $pastDueSince->copy()->addDays(7)->setTimezone($organization->timezone)->format('Y/m/d H:i');

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('Stripeでお支払いを確認できていません。')
            ->assertSee('猶予期限：'.$expectedDeadline.'（'.$organization->timezone.'）')
            ->assertSee('支払い方法・請求状況を確認する')
            ->assertSee('Stripe照会番号：STRIPE-evt_payment_failed_123')
            ->assertSee(route('help.recovery', 'stripe'))
            ->assertSee('mailto:'.config('opsdeck.support_email'), false);
    }

    public function test_billing_page_shows_reference_for_a_current_failed_stripe_event(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $organization->update(['stripe_last_event_created_at' => 900]);
        $failedEvent = StripeWebhookEvent::query()->create([
            'organization_id' => $organization->id,
            'stripe_event_id' => 'evt_customer_support_123',
            'event_type' => 'invoice.paid',
            'payload_hash' => str_repeat('a', 64),
            'stripe_created_at' => 900,
            'processing_error' => 'event_processing_failed',
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('Stripeからの更新通知の処理に失敗しました。')
            ->assertSee('STRIPE-evt_customer_support_123');

        $failedEvent->delete();
    }

    public function test_billing_page_hides_a_stale_failed_stripe_event(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $organization->update(['stripe_last_event_created_at' => 901]);
        $otherOrganization = Organization::query()->create(['name' => 'Other Billing Org', 'slug' => 'other-billing-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => 'free', 'billing_status' => 'inactive', 'stripe_last_event_created_at' => 902]);
        $staleEvent = StripeWebhookEvent::query()->create([
            'organization_id' => $organization->id,
            'stripe_event_id' => 'evt_stale_support_123',
            'event_type' => 'invoice.payment_failed',
            'payload_hash' => str_repeat('b', 64),
            'stripe_created_at' => 900,
            'processing_error' => 'event_processing_failed',
        ]);
        $otherTenantEvent = StripeWebhookEvent::query()->create([
            'organization_id' => $otherOrganization->id,
            'stripe_event_id' => 'evt_other_tenant_support_123',
            'event_type' => 'invoice.payment_failed',
            'payload_hash' => str_repeat('c', 64),
            'stripe_created_at' => 902,
            'processing_error' => 'event_processing_failed',
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('billing.index'))
            ->assertOk()
            ->assertDontSee('Stripeからの更新通知の処理に失敗しました。')
            ->assertDontSee('STRIPE-evt_stale_support_123')
            ->assertDontSee('STRIPE-evt_other_tenant_support_123');

        $staleEvent->delete();
        $otherTenantEvent->delete();
    }

    public function test_billing_page_displays_prices_and_limits_from_the_plan_configuration(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        config([
            'opsdeck.plans.free.monthly_price_jpy' => 100,
            'opsdeck.plans.free.limits.employees' => 23,
            'opsdeck.plans.free.limits.assets' => 57,
            'opsdeck.plans.free.limits.saas_services' => 11,
            'opsdeck.plans.free.limits.administrators' => 2,
            'opsdeck.plans.premium.monthly_price_jpy' => 10800,
            'opsdeck.plans.premium.limits.employees' => 230,
            'opsdeck.plans.premium.limits.assets' => 570,
            'opsdeck.plans.premium.limits.saas_services' => 110,
            'opsdeck.plans.premium.limits.administrators' => 6,
            'opsdeck.plans.free.capabilities.xlsx_preview' => true,
            'opsdeck.plans.premium.capabilities.xlsx_preview' => false,
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('100円')
            ->assertSee('10,800円/月')
            ->assertSee('社員23・資産57・SaaS11・管理者2')
            ->assertSee('社員230・資産570・SaaS110・管理者6')
            ->assertSeeInOrder(['XLSXプレビュー', '利用可', '—']);
    }

    public function test_billing_page_labels_cover_every_configured_plan_capability(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $capabilityLabels = config('opsdeck.plan_capability_labels');

        $this->assertSame(array_keys(config('opsdeck.plans.free.capabilities')), array_keys($capabilityLabels));
        $this->assertSame(array_keys(config('opsdeck.plans.premium.capabilities')), array_keys($capabilityLabels));

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('billing.index'))
            ->assertOk();

        foreach ($capabilityLabels as $label) {
            $response->assertSee($label);
        }
    }

    public function test_past_due_billing_page_does_not_guess_deadline_without_a_start_timestamp(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $organization->update(['plan_code' => 'premium', 'billing_status' => 'past_due', 'stripe_last_event_created_at' => null]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('猶予期限を特定できません。');
    }

    public function test_past_due_billing_page_explains_when_free_limits_are_active(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $organization->update(['plan_code' => config('opsdeck.free_plan_code'), 'billing_status' => 'past_due', 'stripe_customer_id' => 'cus_'.Str::uuid()]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('猶予期間が終了し、現在はFreeプラン')
            ->assertSee('支払い方法・請求状況を確認する')
            ->assertDontSee('Stripe Checkoutを開始');
    }

    public function test_billing_page_reflects_expired_grace_before_scheduled_downgrade(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'past_due', 'billing_past_due_since' => now()->subDays(8), 'stripe_customer_id' => 'cus_'.Str::uuid()]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('猶予期間が終了し、現在はFreeプラン')
            ->assertDontSee('Stripe Checkoutを開始');

        $this->assertSame(config('opsdeck.premium_plan_code'), $organization->refresh()->plan_code);
    }

    public function test_checkout_rejects_new_subscription_when_billing_is_already_active(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.checkout'))
            ->assertStatus(409);
    }

    public function test_admin_and_viewer_cannot_manage_billing(): void
    {
        [$admin, $adminOrganization] = $this->member(MembershipRole::Admin);
        [$viewer, $viewerOrganization] = $this->member(MembershipRole::Viewer);

        $this->actingAs($admin)->withSession(['current_organization_id' => $adminOrganization->id])->get(route('billing.index'))->assertForbidden();
        $this->actingAs($viewer)->withSession(['current_organization_id' => $viewerOrganization->id])->get(route('billing.index'))->assertForbidden();
    }

    /** @return array{0: User, 1: Organization} */
    private function member(MembershipRole $role): array
    {
        $suffix = Str::lower(Str::random(8));
        $user = User::factory()->create(['email' => 'billing-'.Str::uuid().'@example.test']);
        $organization = Organization::query()->create(['name' => 'Billing '.$suffix, 'slug' => 'billing-'.$suffix, 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => 'inactive']);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => MembershipStatus::Active, 'joined_at' => now()]);

        return [$user, $organization];
    }
}
