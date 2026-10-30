<?php

namespace Tests\Feature;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Services\StorageAddonCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_only_mode_hides_paid_offers_and_blocks_all_stripe_mutations(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $organization->update(['stripe_customer_id' => 'cus_free_mode_test']);
        config([
            'opsdeck.billing.enabled' => false,
            'opsdeck.storage_quotas.platform_limit_megabytes' => 1000000,
            'opsdeck.storage_quotas.storage_addon_max_gib' => 5,
            'services.stripe.secret' => 'sk_test_free_mode',
            'services.stripe.prices.premium' => 'price_test_free_mode',
            'services.stripe.prices.storage_addon_gib' => 'price_test_addon_free_mode',
        ]);
        Http::preventStrayRequests();
        $session = ['current_organization_id' => $organization->id, '_token' => Str::random(40)];

        $this->get('/')
            ->assertOk()
            ->assertSee('現在はFreeプランのみ提供しています。Premiumの新規申込みとStripe決済は停止中です。')
            ->assertDontSee('Premiumに申し込む')
            ->assertDontSee('9,800円');

        $this->actingAs($user)->withSession($session)
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('月額0円のFreeのみ')
            ->assertDontSee('9,800円')
            ->assertDontSee('Stripe Checkoutを開始')
            ->assertDontSee('Customer Portalを開く');

        $this->actingAs($user)->withSession($session)
            ->post(route('billing.checkout'), ['_token' => $session['_token']])
            ->assertNotFound();
        $this->actingAs($user)->withSession($session)
            ->post(route('billing.portal'), ['_token' => $session['_token']])
            ->assertNotFound();
        $this->actingAs($user)->withSession($session)
            ->post(route('billing.storage-addon.update'), [
                '_token' => $session['_token'],
                'quantity' => 1,
                'confirm_change' => '1',
            ])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_production_checkout_is_hidden_and_blocked_when_seller_disclosure_is_incomplete(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $this->app['env'] = 'production';
        config(['session.secure' => false]);
        config([
            'opsdeck.commercial_information.seller_name' => 'OpsDeck Test Seller',
            'opsdeck.commercial_information.seller_address' => 'Tokyo',
            'opsdeck.commercial_information.seller_phone' => '03-1234-5678',
            'opsdeck.commercial_information.seller_representative' => ' ',
        ]);
        Http::preventStrayRequests();

        $csrfToken = Str::random(40);
        $session = ['current_organization_id' => $organization->id, '_token' => $csrfToken];
        $this->actingAs($user)->withSession($session)
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('有料プランの申込みは現在準備中です。販売者情報の設定が完了するまでお申し込みいただけません。')
            ->assertDontSee('Stripe Checkoutを開始');

        $this->actingAs($user)->withSession($session)
            ->post(route('billing.checkout'), ['_token' => $csrfToken])
            ->assertRedirect(route('billing.index'));

        Http::assertNothingSent();
    }

    public function test_production_checkout_is_available_when_seller_disclosure_is_complete(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $this->app['env'] = 'production';
        config([
            'opsdeck.commercial_information.seller_name' => 'OpsDeck Test Seller',
            'opsdeck.commercial_information.seller_address' => 'Tokyo',
            'opsdeck.commercial_information.seller_phone' => '03-1234-5678',
            'opsdeck.commercial_information.seller_representative' => 'Test Representative',
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('Stripe Checkoutを開始');
    }

    public function test_owner_can_start_checkout_without_exposing_secret(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $customerId = 'cus_'.Str::uuid();
        config(['services.stripe.secret' => 'sk_test_secret', 'services.stripe.prices.premium' => 'price_test']);
        Http::fake([
            'https://api.stripe.com/v1/prices/price_test*' => Http::response([
                'active' => true,
                'type' => 'recurring',
                'billing_scheme' => 'per_unit',
                'currency' => 'jpy',
                'unit_amount' => 9800,
                'tax_behavior' => 'exclusive',
                'product' => ['id' => 'prod_test', 'tax_code' => 'txcd_test_service'],
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ], 200),
            'https://api.stripe.com/v1/tax/settings' => Http::response(['status' => 'active', 'defaults' => ['tax_code' => 'txcd_test_default']], 200),
            'https://api.stripe.com/v1/customers' => Http::response(['id' => $customerId], 200),
            'https://api.stripe.com/v1/checkout/sessions?*' => Http::response(['has_more' => false, 'data' => []], 200),
            'https://api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_initial_owner',
                'url' => 'https://checkout.stripe.test/session',
                'expires_at' => now()->addHours(24)->timestamp,
            ], 200),
        ]);

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])->post(route('billing.checkout'));
        $response->assertRedirect('https://checkout.stripe.test/session');
        $this->assertSame($customerId, $organization->refresh()->stripe_customer_id);
        $this->assertSame(0, $organization->storage_addon_checkout_reserved_gib);
        $this->assertSame('cs_initial_owner', $organization->storage_addon_checkout_session_id);
        $this->assertTrue($organization->storage_addon_checkout_reservation_expires_at->isFuture());
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && $request['line_items[0][price]'] === 'price_test'
            && $request['automatic_tax[enabled]'] === 'true'
            && $request['billing_address_collection'] === 'required'
            && $request['client_reference_id'] === (string) $organization->id
            && $request['subscription_data[metadata][plan_code]'] === 'premium');
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api.stripe.com/v1/prices/price_test')
            && str_contains($request->url(), 'expand'));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.stripe.com/v1/tax/settings');
        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.stripe.com/v1/tax/registrations'));
        Http::assertSent(fn ($request): bool => ! str_contains($request->body(), 'sk_test_secret'));
    }

    public function test_pending_checkout_reserves_global_capacity_before_a_second_company_can_subscribe(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$firstUser, $firstOrganization] = $this->member(MembershipRole::Owner);
        [$secondUser, $secondOrganization] = $this->member(MembershipRole::Owner);
        $premiumBaseQuotaMegabytes = (int) config('opsdeck.storage_quotas.plan_limit_megabytes.'.config('opsdeck.premium_plan_code'));
        config([
            'opsdeck.release_features.storage_addon' => true,
            'opsdeck.storage_quotas.platform_limit_megabytes' => $premiumBaseQuotaMegabytes,
            'opsdeck.storage_quotas.storage_addon_max_gib' => 5,
            'services.stripe.secret' => 'sk_test_secret',
            'services.stripe.prices.premium' => 'price_test',
        ]);
        Http::fake([
            'https://api.stripe.com/v1/prices/price_test*' => Http::response([
                'active' => true,
                'type' => 'recurring',
                'billing_scheme' => 'per_unit',
                'currency' => 'jpy',
                'unit_amount' => 9800,
                'tax_behavior' => 'exclusive',
                'product' => ['id' => 'prod_test', 'tax_code' => 'txcd_test_service'],
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ], 200),
            'https://api.stripe.com/v1/tax/settings' => Http::response(['status' => 'active', 'defaults' => ['tax_code' => 'txcd_test_default']], 200),
            'https://api.stripe.com/v1/customers' => Http::response(['id' => 'cus_first_checkout'], 200),
            'https://api.stripe.com/v1/checkout/sessions?*' => Http::response(['has_more' => false, 'data' => []], 200),
            'https://api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_first_checkout',
                'url' => 'https://checkout.stripe.test/first',
                'expires_at' => now()->addHours(24)->timestamp,
            ], 200),
        ]);

        $this->actingAs($firstUser)
            ->withSession(['current_organization_id' => $firstOrganization->id])
            ->post(route('billing.checkout'), ['storage_addon_gib' => 0])
            ->assertRedirect('https://checkout.stripe.test/first');

        $this->actingAs($secondUser)
            ->withSession(['current_organization_id' => $secondOrganization->id])
            ->post(route('billing.checkout'), ['storage_addon_gib' => 0])
            ->assertServiceUnavailable();

        $this->assertSame(0, $firstOrganization->refresh()->storage_addon_checkout_reserved_gib);
        $this->assertSame('cs_first_checkout', $firstOrganization->storage_addon_checkout_session_id);
        $this->assertNull($secondOrganization->refresh()->stripe_customer_id);
        Http::assertSentCount(6);
    }

    public function test_owner_can_select_storage_addon_for_the_initial_premium_checkout(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Owner);
        config([
            'opsdeck.storage_quotas.platform_limit_megabytes' => 1000000,
            'opsdeck.storage_quotas.storage_addon_max_gib' => 5,
            'services.stripe.secret' => 'sk_test_secret',
            'services.stripe.prices.premium' => 'price_test',
            'services.stripe.prices.storage_addon_gib' => 'price_storage_test',
        ]);
        Http::fake([
            'https://api.stripe.com/v1/prices/price_test*' => Http::response([
                'active' => true,
                'type' => 'recurring',
                'billing_scheme' => 'per_unit',
                'currency' => 'jpy',
                'unit_amount' => 9800,
                'tax_behavior' => 'exclusive',
                'product' => ['id' => 'prod_test', 'tax_code' => 'txcd_test_service'],
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ], 200),
            'https://api.stripe.com/v1/prices/price_storage_test*' => Http::response([
                'id' => 'price_storage_test',
                'active' => true,
                'type' => 'recurring',
                'billing_scheme' => 'per_unit',
                'currency' => 'jpy',
                'unit_amount' => 100,
                'tax_behavior' => 'exclusive',
                'product' => ['id' => 'prod_storage_test', 'tax_code' => 'txcd_test_service'],
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ], 200),
            'https://api.stripe.com/v1/tax/settings' => Http::response(['status' => 'active', 'defaults' => ['tax_code' => 'txcd_test_default']], 200),
            'https://api.stripe.com/v1/customers' => Http::response(['id' => 'cus_test'], 200),
            'https://api.stripe.com/v1/checkout/sessions?*' => Http::response(['has_more' => false, 'data' => []], 200),
            'https://api.stripe.com/v1/checkout/sessions' => function (ClientRequest $request) use ($organization) {
                $reservedOrganization = $organization->fresh();
                $this->assertSame(1, DB::transactionLevel());
                $this->assertSame(2, $reservedOrganization->storage_addon_checkout_reserved_gib);
                $this->assertNull($reservedOrganization->storage_addon_checkout_session_id);

                return Http::response([
                    'id' => 'cs_storage_addon_checkout',
                    'url' => 'https://checkout.stripe.test/session',
                    'expires_at' => now()->addHours(24)->timestamp,
                ], 200);
            },
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.checkout'), ['storage_addon_gib' => 2])
            ->assertRedirect('https://checkout.stripe.test/session');

        $reservedOrganization = $organization->fresh();
        $this->assertSame(2, $reservedOrganization->storage_addon_checkout_reserved_gib);
        $this->assertSame('cs_storage_addon_checkout', $reservedOrganization->storage_addon_checkout_session_id);
        $this->assertTrue($reservedOrganization->storage_addon_checkout_reservation_expires_at->isFuture());
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && $request['line_items[0][price]'] === 'price_test'
            && $request['line_items[0][quantity]'] === 1
            && $request['line_items[1][price]'] === 'price_storage_test'
            && $request['line_items[1][quantity]'] === 2
            && $request['automatic_tax[enabled]'] === 'true');
    }

    public function test_initial_checkout_rejects_addon_quantity_when_platform_capacity_is_full(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        config([
            'opsdeck.storage_quotas.platform_limit_megabytes' => (int) config('opsdeck.storage_quotas.plan_limit_megabytes.'.config('opsdeck.premium_plan_code')),
            'opsdeck.storage_quotas.storage_addon_max_gib' => 5,
            'services.stripe.prices.storage_addon_gib' => 'price_storage_test',
        ]);
        Http::preventStrayRequests();

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->from(route('billing.index'))
            ->post(route('billing.checkout'), ['storage_addon_gib' => 1])
            ->assertRedirect(route('billing.index'))
            ->assertSessionHasErrors('storage_addon_gib');

        Http::assertNothingSent();
    }

    public function test_initial_checkout_rejects_zero_addon_when_platform_cannot_reserve_the_premium_base_quota(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $premiumBaseQuotaMegabytes = (int) config('opsdeck.storage_quotas.plan_limit_megabytes.'.config('opsdeck.premium_plan_code'));
        config([
            'opsdeck.release_features.storage_addon' => true,
            'opsdeck.storage_quotas.platform_limit_megabytes' => $premiumBaseQuotaMegabytes - 1,
            'opsdeck.storage_quotas.storage_addon_max_gib' => 5,
            'services.stripe.prices.premium' => 'price_test',
            'services.stripe.prices.storage_addon_gib' => 'price_storage_test',
        ]);
        Http::preventStrayRequests();

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('保存容量の空き状況を確認できないため、Premiumの新規申込みを一時停止しています。')
            ->assertDontSee('Stripe Checkoutを開始');

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.checkout'), ['storage_addon_gib' => 0])
            ->assertServiceUnavailable();

        Http::assertNothingSent();
    }

    public function test_billing_page_calculates_premium_capacity_only_once(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        config([
            'opsdeck.billing.enabled' => true,
            'opsdeck.release_features.storage_addon' => true,
            'services.stripe.prices.storage_addon_gib' => 'price_storage_test',
        ]);
        $capacityService = \Mockery::mock(StorageAddonCapacityService::class);
        $capacityService->shouldReceive('maximumQuantity')->twice()->andReturn(5);
        $capacityService->shouldReceive('maximumCheckoutQuantity')
            ->once()
            ->with(\Mockery::type(Organization::class))
            ->andReturn(null);
        $this->app->instance(StorageAddonCapacityService::class, $capacityService);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('保存容量の空き状況を確認できないため、Premiumの新規申込みを一時停止しています。');

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('保存容量の空き状況を確認できないため、Premiumの新規申込みを一時停止しています。');
    }

    public function test_billing_capacity_display_cache_expires_after_its_short_ttl(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        config([
            'opsdeck.billing.enabled' => true,
            'opsdeck.release_features.storage_addon' => true,
            'services.stripe.prices.storage_addon_gib' => 'price_storage_test',
        ]);
        $capacityService = \Mockery::mock(StorageAddonCapacityService::class);
        $capacityService->shouldReceive('maximumQuantity')->times(3)->andReturn(5);
        $capacityService->shouldReceive('maximumCheckoutQuantity')
            ->twice()
            ->with(\Mockery::type(Organization::class))
            ->andReturn(5, 4);
        $this->app->instance(StorageAddonCapacityService::class, $capacityService);
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($user)->withSession($session)->get(route('billing.index'))->assertOk();
        $this->actingAs($user)->withSession($session)->get(route('billing.index'))->assertOk();

        $this->travel(16)->seconds();

        $this->actingAs($user)->withSession($session)->get(route('billing.index'))->assertOk();
    }

    public function test_checkout_rechecks_capacity_instead_of_using_the_billing_display_cache(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        config([
            'opsdeck.billing.enabled' => true,
            'opsdeck.release_features.storage_addon' => true,
            'services.stripe.prices.storage_addon_gib' => 'price_storage_test',
        ]);
        Http::preventStrayRequests();
        $capacityService = \Mockery::mock(StorageAddonCapacityService::class);
        $capacityService->shouldReceive('maximumQuantity')->once()->andReturn(5);
        $capacityService->shouldReceive('maximumCheckoutQuantity')
            ->twice()
            ->with(\Mockery::type(Organization::class))
            ->andReturn(5, 0);
        $this->app->instance(StorageAddonCapacityService::class, $capacityService);
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($user)->withSession($session)
            ->get(route('billing.index'))
            ->assertOk();

        $this->actingAs($user)->withSession($session)
            ->post(route('billing.checkout'), ['storage_addon_gib' => 1])
            ->assertSessionHasErrors('storage_addon_gib');

        Http::assertNothingSent();
    }

    public function test_initial_checkout_calculates_premium_capacity_only_once(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        config([
            'opsdeck.billing.enabled' => true,
            'opsdeck.release_features.storage_addon' => true,
            'services.stripe.prices.storage_addon_gib' => 'price_storage_test',
        ]);
        Http::preventStrayRequests();
        $capacityService = \Mockery::mock(StorageAddonCapacityService::class);
        $capacityService->shouldReceive('maximumCheckoutQuantity')
            ->once()
            ->with(\Mockery::type(Organization::class))
            ->andReturn(null);
        $this->app->instance(StorageAddonCapacityService::class, $capacityService);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.checkout'), ['storage_addon_gib' => 0])
            ->assertServiceUnavailable();

        Http::assertNothingSent();
    }

    public function test_checkout_accepts_unspecified_price_tax_behavior_when_account_default_is_exclusive(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        config(['services.stripe.secret' => 'sk_test_secret', 'services.stripe.prices.premium' => 'price_test']);
        Http::fake([
            'https://api.stripe.com/v1/prices/price_test*' => Http::response([
                'active' => true,
                'type' => 'recurring',
                'billing_scheme' => 'per_unit',
                'currency' => 'jpy',
                'unit_amount' => 9800,
                'tax_behavior' => 'unspecified',
                'product' => ['id' => 'prod_test', 'tax_code' => 'txcd_test_service'],
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ], 200),
            'https://api.stripe.com/v1/tax/settings' => Http::response([
                'status' => 'active',
                'defaults' => ['tax_behavior' => 'exclusive', 'tax_code' => 'txcd_test_default'],
            ], 200),
            'https://api.stripe.com/v1/tax/registrations*' => Http::response(['has_more' => false, 'data' => [['id' => 'taxreg_japan', 'country' => 'JP', 'status' => 'active']]], 200),
            'https://api.stripe.com/v1/customers' => Http::response(['id' => 'cus_test'], 200),
            'https://api.stripe.com/v1/checkout/sessions?*' => Http::response(['has_more' => false, 'data' => []], 200),
            'https://api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.test/session'], 200),
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.checkout'))
            ->assertRedirect('https://checkout.stripe.test/session');
    }

    public function test_checkout_rejects_unspecified_price_tax_behavior_when_account_default_is_inclusive(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        config(['services.stripe.secret' => 'sk_test_secret', 'services.stripe.prices.premium' => 'price_test']);
        Log::spy();
        Http::fake([
            'https://api.stripe.com/v1/prices/price_test*' => Http::response([
                'active' => true,
                'type' => 'recurring',
                'billing_scheme' => 'per_unit',
                'currency' => 'jpy',
                'unit_amount' => 9800,
                'tax_behavior' => 'unspecified',
                'product' => ['id' => 'prod_test', 'tax_code' => 'txcd_test_service'],
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ], 200),
            'https://api.stripe.com/v1/tax/settings' => Http::response([
                'status' => 'active',
                'defaults' => ['tax_behavior' => 'inclusive', 'tax_code' => 'txcd_test_default'],
            ], 200),
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.checkout'))
            ->assertServiceUnavailable();

        $this->assertNull($organization->refresh()->stripe_customer_id);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v1/customers') || str_contains($request->url(), '/v1/checkout/sessions'));
        Log::shouldHaveReceived('warning')->once()->with('Stripe billing operation failed.', \Mockery::on(static fn (array $context): bool => ($context['operation'] ?? null) === 'price_validation'
            && ($context['failure'] ?? null) === 'price_mismatch'
            && ($context['failed_checks'] ?? null) === ['tax_behavior']));
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
            'https://api.stripe.com/v1/prices/price_test*' => Http::response([
                'active' => true,
                'type' => 'recurring',
                'billing_scheme' => 'per_unit',
                'currency' => 'jpy',
                'unit_amount' => 9800,
                'tax_behavior' => 'exclusive',
                'product' => ['id' => 'prod_test', 'tax_code' => 'txcd_test_service'],
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ], 200),
            'https://api.stripe.com/v1/tax/settings' => Http::response(['status' => 'active', 'defaults' => ['tax_code' => 'txcd_test_default']], 200),
            'https://api.stripe.com/v1/customers' => Http::response(['id' => 'cus_'.Str::uuid()], 200),
            'https://api.stripe.com/v1/checkout/sessions?*' => Http::response(['has_more' => false, 'data' => []], 200),
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

        Http::assertSentCount(6);
        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.stripe.com/v1/tax/registrations'));
    }

    public function test_customer_portal_attempts_are_rate_limited_per_organization(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active', 'stripe_customer_id' => 'cus_portal_'.Str::uuid()]);
        config([
            'opsdeck.billing.portal_attempts_per_minute' => 1,
            'services.stripe.secret' => 'sk_test_secret',
        ]);
        Http::fake(['https://api.stripe.com/v1/billing_portal/sessions' => Http::response(['url' => 'https://billing.stripe.test/session'], 200)]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.portal'))
            ->assertRedirect('https://billing.stripe.test/session');
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.portal'))
            ->assertTooManyRequests();

        Http::assertSentCount(1);
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
        $openSession = null;
        Http::fake(function (ClientRequest $request) use (&$checkoutIdempotencyKeys, &$openSession, $organization) {
            if (str_starts_with($request->url(), 'https://api.stripe.com/v1/prices/price_test')) {
                return Http::response([
                    'active' => true,
                    'type' => 'recurring',
                    'billing_scheme' => 'per_unit',
                    'currency' => 'jpy',
                    'unit_amount' => 9800,
                    'tax_behavior' => 'exclusive',
                    'product' => ['id' => 'prod_test', 'tax_code' => 'txcd_test_service'],
                    'recurring' => ['interval' => 'month', 'interval_count' => 1],
                ], 200);
            }

            if ($request->url() === 'https://api.stripe.com/v1/tax/settings') {
                return Http::response(['status' => 'active', 'defaults' => ['tax_code' => 'txcd_test_default']], 200);
            }

            if (str_starts_with($request->url(), 'https://api.stripe.com/v1/tax/registrations')) {
                return Http::response(['has_more' => false, 'data' => [['id' => 'taxreg_japan', 'country' => 'JP', 'status' => 'active']]], 200);
            }

            if ($request->method() === 'GET' && str_starts_with($request->url(), 'https://api.stripe.com/v1/checkout/sessions?')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return Http::response([
                    'has_more' => false,
                    'data' => ($query['status'] ?? null) === 'open' && $openSession !== null ? [$openSession] : [],
                ], 200);
            }

            if ($request->url() === 'https://api.stripe.com/v1/checkout/sessions') {
                $checkoutIdempotencyKeys[] = $request->header('Idempotency-Key')[0] ?? null;
                $openSession = [
                    'id' => 'cs_reused',
                    'status' => 'open',
                    'url' => 'https://checkout.stripe.test/session',
                    'mode' => 'subscription',
                    'metadata' => [
                        'organization_id' => (string) $organization->id,
                        'opsdeck_checkout_fingerprint' => isset($request['metadata[opsdeck_checkout_fingerprint]'])
                            ? $request['metadata[opsdeck_checkout_fingerprint]']
                            : 'legacy',
                    ],
                ];

                return Http::response($openSession, 200);
            }

            return Http::response([], 404);
        });

        foreach (range(1, 2) as $_attempt) {
            $this->actingAs($user)
                ->withSession(['current_organization_id' => $organization->id])
                ->post(route('billing.checkout'))
                ->assertRedirect('https://checkout.stripe.test/session');
        }

        $this->assertCount(1, $checkoutIdempotencyKeys);
        $this->assertNotNull($checkoutIdempotencyKeys[0]);
    }

    public function test_checkout_expires_a_different_open_selection_before_creating_a_new_session(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $organization->update(['stripe_customer_id' => 'cus_existing']);
        config([
            'opsdeck.billing.checkout_attempts_per_minute' => 2,
            'opsdeck.release_features.storage_addon' => true,
            'opsdeck.storage_quotas.platform_limit_megabytes' => 1000000,
            'opsdeck.storage_quotas.storage_addon_max_gib' => 5,
            'services.stripe.secret' => 'sk_test_secret',
            'services.stripe.prices.premium' => 'price_test',
            'services.stripe.prices.storage_addon_gib' => 'price_storage_test',
        ]);
        $openSession = null;
        $createdCheckoutRequests = [];
        $expiredSessionIds = [];
        Http::fake(function (ClientRequest $request) use ($organization, &$openSession, &$createdCheckoutRequests, &$expiredSessionIds) {
            if (str_starts_with($request->url(), 'https://api.stripe.com/v1/prices/price_test')) {
                return Http::response([
                    'active' => true,
                    'type' => 'recurring',
                    'billing_scheme' => 'per_unit',
                    'currency' => 'jpy',
                    'unit_amount' => 9800,
                    'tax_behavior' => 'exclusive',
                    'product' => ['id' => 'prod_test', 'tax_code' => 'txcd_test_service'],
                    'recurring' => ['interval' => 'month', 'interval_count' => 1],
                ], 200);
            }

            if (str_starts_with($request->url(), 'https://api.stripe.com/v1/prices/price_storage_test')) {
                return Http::response([
                    'id' => 'price_storage_test',
                    'active' => true,
                    'type' => 'recurring',
                    'billing_scheme' => 'per_unit',
                    'currency' => 'jpy',
                    'unit_amount' => 100,
                    'tax_behavior' => 'exclusive',
                    'product' => ['id' => 'prod_storage_test', 'tax_code' => 'txcd_test_service'],
                    'recurring' => ['interval' => 'month', 'interval_count' => 1],
                ], 200);
            }

            if ($request->url() === 'https://api.stripe.com/v1/tax/settings') {
                return Http::response(['status' => 'active', 'defaults' => ['tax_code' => 'txcd_test_default']], 200);
            }

            if ($request->method() === 'GET' && str_starts_with($request->url(), 'https://api.stripe.com/v1/checkout/sessions?')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return Http::response([
                    'has_more' => false,
                    'data' => ($query['status'] ?? null) === 'open' && $openSession !== null ? [$openSession] : [],
                ], 200);
            }

            if ($request->method() === 'POST' && preg_match('#/v1/checkout/sessions/(cs_[^/]+)/expire$#', $request->url(), $matches) === 1) {
                $expiredSessionIds[] = $matches[1];
                $openSession = null;

                return Http::response(['id' => $matches[1], 'status' => 'expired'], 200);
            }

            if ($request->method() === 'POST' && $request->url() === 'https://api.stripe.com/v1/checkout/sessions') {
                $createdCheckoutRequests[] = $request;
                $sessionId = 'cs_'.count($createdCheckoutRequests);
                $openSession = [
                    'id' => $sessionId,
                    'status' => 'open',
                    'url' => 'https://checkout.stripe.test/'.$sessionId,
                    'mode' => 'subscription',
                    'metadata' => [
                        'organization_id' => (string) $organization->id,
                        'opsdeck_checkout_fingerprint' => isset($request['metadata[opsdeck_checkout_fingerprint]'])
                            ? $request['metadata[opsdeck_checkout_fingerprint]']
                            : 'legacy',
                    ],
                ];

                return Http::response($openSession, 200);
            }

            return Http::response([], 404);
        });

        $firstResponse = $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.checkout'), ['storage_addon_gib' => 1]);
        $firstResponse->assertRedirect('https://checkout.stripe.test/cs_1');

        $secondResponse = $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.checkout'), ['storage_addon_gib' => 2]);
        $secondResponse->assertRedirect('https://checkout.stripe.test/cs_2');

        $this->assertCount(2, $createdCheckoutRequests);
        $this->assertSame(['cs_1'], $expiredSessionIds);
        $this->assertSame(2, $createdCheckoutRequests[1]['line_items[1][quantity]']);
    }

    public function test_checkout_waits_for_a_recent_completed_session_webhook_before_starting_another(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $lastStripeEventCreatedAt = now()->timestamp;
        $organization->forceFill([
            'created_at' => now()->subDays(2),
            'stripe_customer_id' => 'cus_existing',
            'stripe_last_event_created_at' => $lastStripeEventCreatedAt,
        ])->save();
        config([
            'opsdeck.billing.checkout_attempts_per_minute' => 2,
            'services.stripe.secret' => 'sk_test_secret',
            'services.stripe.prices.premium' => 'price_test',
        ]);
        $checkoutSessionListStatuses = [];
        Http::fake(function (ClientRequest $request) use ($organization, $lastStripeEventCreatedAt, &$checkoutSessionListStatuses) {
            if (str_starts_with($request->url(), 'https://api.stripe.com/v1/prices/price_test')) {
                return Http::response([
                    'active' => true,
                    'type' => 'recurring',
                    'billing_scheme' => 'per_unit',
                    'currency' => 'jpy',
                    'unit_amount' => 9800,
                    'tax_behavior' => 'exclusive',
                    'product' => ['id' => 'prod_test', 'tax_code' => 'txcd_test_service'],
                    'recurring' => ['interval' => 'month', 'interval_count' => 1],
                ], 200);
            }

            if ($request->url() === 'https://api.stripe.com/v1/tax/settings') {
                return Http::response(['status' => 'active', 'defaults' => ['tax_code' => 'txcd_test_default']], 200);
            }

            if ($request->method() === 'GET' && str_starts_with($request->url(), 'https://api.stripe.com/v1/checkout/sessions?')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $checkoutSessionListStatuses[] = $query['status'] ?? null;
                $sessionCreatedAt = $lastStripeEventCreatedAt - 60;
                $isRecentCompletedSession = count($checkoutSessionListStatuses) === 2
                    && ($query['status'] ?? null) === 'complete'
                    && isset($query['created']['gte'])
                    && (int) $query['created']['gte'] <= $sessionCreatedAt;

                return Http::response([
                    'has_more' => false,
                    'data' => $isRecentCompletedSession ? [[
                        'id' => 'cs_recently_completed',
                        'mode' => 'subscription',
                        'created' => $sessionCreatedAt,
                        'subscription' => 'sub_pending_webhook',
                        'metadata' => ['organization_id' => (string) $organization->id],
                    ]] : [],
                ], 200);
            }

            return Http::response([], 404);
        });

        $response = $this->followingRedirects()
            ->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.checkout'));

        $response->assertOk()
            ->assertSee('別の決済手続きが進行中、または決済完了通知を確認しています。');
        $this->assertSame(['open', 'complete'], $checkoutSessionListStatuses);
        Http::assertNotSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.stripe.com/v1/checkout/sessions');
    }

    public function test_checkout_fails_closed_when_stripe_price_does_not_match_the_advertised_monthly_price(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        config(['services.stripe.secret' => 'sk_test_secret', 'services.stripe.prices.premium' => 'price_test']);
        Log::spy();
        Http::fake([
            'https://api.stripe.com/v1/prices/price_test*' => Http::response([
                'active' => true,
                'type' => 'recurring',
                'billing_scheme' => 'per_unit',
                'currency' => 'jpy',
                'unit_amount' => 980000,
                'tax_behavior' => 'exclusive',
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ], 200),
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.checkout'))
            ->assertServiceUnavailable();

        $this->assertNull($organization->refresh()->stripe_customer_id);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v1/customers') || str_contains($request->url(), '/v1/checkout/sessions'));
        Log::shouldHaveReceived('warning')->once()->with('Stripe billing operation failed.', \Mockery::on(static fn (array $context): bool => ($context['operation'] ?? null) === 'price_validation'
            && ($context['failure'] ?? null) === 'price_mismatch'
            && ($context['failed_checks'] ?? null) === ['unit_amount']));
    }

    public function test_checkout_logs_safe_metadata_when_stripe_rejects_premium_price_request(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        config(['services.stripe.secret' => 'sk_test_secret', 'services.stripe.prices.premium' => 'price_test']);
        Log::spy();
        Http::fake([
            'https://api.stripe.com/v1/prices/price_test*' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'code' => 'resource_missing',
                    'message' => 'PRIVATE_STRIPE_DETAIL',
                ],
            ], 404, ['Request-Id' => 'req_test123']),
        ]);

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.checkout'));

        $response->assertServiceUnavailable()
            ->assertDontSee('PRIVATE_STRIPE_DETAIL')
            ->assertDontSee('sk_test_secret');
        Log::shouldHaveReceived('warning')->once()->with('Stripe billing operation failed.', \Mockery::on(static fn (array $context): bool => ($context['operation'] ?? null) === 'price_validation'
            && ($context['failure'] ?? null) === 'stripe_rejected'
            && ($context['http_status'] ?? null) === 404
            && ($context['stripe_error_code'] ?? null) === 'resource_missing'
            && ($context['stripe_request_id'] ?? null) === 'req_test123'));
        $this->assertNull($organization->refresh()->stripe_customer_id);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v1/customers') || str_contains($request->url(), '/v1/checkout/sessions'));
    }

    public function test_checkout_fails_closed_when_stripe_price_is_not_tax_exclusive(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        config(['services.stripe.secret' => 'sk_test_secret', 'services.stripe.prices.premium' => 'price_test']);
        Http::fake([
            'https://api.stripe.com/v1/prices/price_test*' => Http::response([
                'active' => true,
                'type' => 'recurring',
                'billing_scheme' => 'per_unit',
                'currency' => 'jpy',
                'unit_amount' => 9800,
                'tax_behavior' => 'inclusive',
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ], 200),
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.checkout'))
            ->assertServiceUnavailable();

        $this->assertNull($organization->refresh()->stripe_customer_id);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v1/customers') || str_contains($request->url(), '/v1/checkout/sessions'));
    }

    public function test_checkout_fails_closed_when_neither_product_nor_account_has_a_tax_code(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        config(['services.stripe.secret' => 'sk_test_secret', 'services.stripe.prices.premium' => 'price_test']);
        Http::fake([
            'https://api.stripe.com/v1/prices/price_test*' => Http::response([
                'active' => true,
                'type' => 'recurring',
                'billing_scheme' => 'per_unit',
                'currency' => 'jpy',
                'unit_amount' => 9800,
                'tax_behavior' => 'exclusive',
                'product' => ['id' => 'prod_test', 'tax_code' => null],
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ], 200),
            'https://api.stripe.com/v1/tax/settings' => Http::response(['status' => 'active', 'defaults' => ['tax_code' => null]], 200),
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.checkout'))
            ->assertServiceUnavailable();

        $this->assertNull($organization->refresh()->stripe_customer_id);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v1/customers') || str_contains($request->url(), '/v1/checkout/sessions'));
    }

    public function test_checkout_fails_closed_when_product_tax_code_is_missing_even_if_account_default_is_set(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        config(['services.stripe.secret' => 'sk_test_secret', 'services.stripe.prices.premium' => 'price_test']);
        Http::fake([
            'https://api.stripe.com/v1/prices/price_test*' => Http::response([
                'active' => true,
                'type' => 'recurring',
                'billing_scheme' => 'per_unit',
                'currency' => 'jpy',
                'unit_amount' => 9800,
                'tax_behavior' => 'exclusive',
                'product' => ['id' => 'prod_test', 'tax_code' => null],
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ], 200),
            'https://api.stripe.com/v1/tax/settings' => Http::response(['status' => 'active', 'defaults' => ['tax_code' => 'txcd_test_default']], 200),
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.checkout'))
            ->assertServiceUnavailable();

        $this->assertNull($organization->refresh()->stripe_customer_id);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v1/customers') || str_contains($request->url(), '/v1/checkout/sessions'));
    }

    public function test_checkout_fails_closed_when_storage_addon_product_tax_code_is_missing_even_if_account_default_is_set(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        config([
            'opsdeck.storage_quotas.platform_limit_megabytes' => 1000000,
            'opsdeck.storage_quotas.storage_addon_max_gib' => 5,
            'services.stripe.secret' => 'sk_test_secret',
            'services.stripe.prices.premium' => 'price_test',
            'services.stripe.prices.storage_addon_gib' => 'price_storage_test',
        ]);
        Http::fake([
            'https://api.stripe.com/v1/prices/price_test*' => Http::response([
                'active' => true,
                'type' => 'recurring',
                'billing_scheme' => 'per_unit',
                'currency' => 'jpy',
                'unit_amount' => 9800,
                'tax_behavior' => 'exclusive',
                'product' => ['id' => 'prod_test', 'tax_code' => 'txcd_test_service'],
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ], 200),
            'https://api.stripe.com/v1/prices/price_storage_test*' => Http::response([
                'active' => true,
                'type' => 'recurring',
                'billing_scheme' => 'per_unit',
                'currency' => 'jpy',
                'unit_amount' => 100,
                'tax_behavior' => 'exclusive',
                'product' => ['id' => 'prod_storage_test', 'tax_code' => null],
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ], 200),
            'https://api.stripe.com/v1/tax/settings' => Http::response(['status' => 'active', 'defaults' => ['tax_code' => 'txcd_test_default']], 200),
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.checkout'), ['storage_addon_gib' => 1])
            ->assertServiceUnavailable();

        $this->assertNull($organization->refresh()->stripe_customer_id);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v1/customers') || str_contains($request->url(), '/v1/checkout/sessions'));
    }

    public function test_checkout_fails_closed_when_stripe_tax_settings_are_not_active(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        config(['services.stripe.secret' => 'sk_test_secret', 'services.stripe.prices.premium' => 'price_test']);
        Http::fake([
            'https://api.stripe.com/v1/prices/price_test*' => Http::response([
                'active' => true,
                'type' => 'recurring',
                'billing_scheme' => 'per_unit',
                'currency' => 'jpy',
                'unit_amount' => 9800,
                'tax_behavior' => 'exclusive',
                'product' => ['id' => 'prod_test', 'tax_code' => 'txcd_test_service'],
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ], 200),
            'https://api.stripe.com/v1/tax/settings' => Http::response(['status' => 'pending', 'defaults' => ['tax_code' => null]], 200),
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.checkout'))
            ->assertServiceUnavailable();

        $this->assertNull($organization->refresh()->stripe_customer_id);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v1/customers') || str_contains($request->url(), '/v1/checkout/sessions'));
    }

    public function test_checkout_fails_closed_when_japan_tax_registration_is_missing(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        config(['services.stripe.environment' => 'live', 'services.stripe.secret' => 'sk_live_secret', 'services.stripe.prices.premium' => 'price_test']);
        Http::fake([
            'https://api.stripe.com/v1/prices/price_test*' => Http::response([
                'active' => true,
                'type' => 'recurring',
                'billing_scheme' => 'per_unit',
                'currency' => 'jpy',
                'unit_amount' => 9800,
                'tax_behavior' => 'exclusive',
                'product' => ['id' => 'prod_test', 'tax_code' => 'txcd_test_service'],
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ], 200),
            'https://api.stripe.com/v1/tax/settings' => Http::response(['status' => 'active', 'defaults' => ['tax_code' => 'txcd_test_default']], 200),
            'https://api.stripe.com/v1/tax/registrations*' => Http::response(['has_more' => false, 'data' => [['id' => 'taxreg_us', 'country' => 'US', 'status' => 'active']]], 200),
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.checkout'))
            ->assertServiceUnavailable();

        $this->assertNull($organization->refresh()->stripe_customer_id);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v1/customers') || str_contains($request->url(), '/v1/checkout/sessions'));
    }

    public function test_checkout_logs_safe_metadata_when_stripe_rejects_checkout_session_request(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $organization->update(['stripe_customer_id' => 'cus_existing']);
        config(['services.stripe.secret' => 'sk_test_secret', 'services.stripe.prices.premium' => 'price_test']);
        Log::spy();
        Http::fake([
            'https://api.stripe.com/v1/prices/price_test*' => Http::response([
                'active' => true,
                'type' => 'recurring',
                'billing_scheme' => 'per_unit',
                'currency' => 'jpy',
                'unit_amount' => 9800,
                'tax_behavior' => 'exclusive',
                'product' => ['id' => 'prod_test', 'tax_code' => 'txcd_test_service'],
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ], 200),
            'https://api.stripe.com/v1/tax/settings' => Http::response(['status' => 'active', 'defaults' => ['tax_code' => 'txcd_test_default']], 200),
            'https://api.stripe.com/v1/checkout/sessions?*' => Http::response(['has_more' => false, 'data' => []], 200),
            'https://api.stripe.com/v1/checkout/sessions' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'code' => 'parameter_invalid_empty',
                    'message' => 'PRIVATE_STRIPE_DETAIL',
                ],
            ], 400, ['Request-Id' => 'req_checkout123']),
        ]);

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.checkout'));

        $response->assertServiceUnavailable()
            ->assertDontSee('PRIVATE_STRIPE_DETAIL')
            ->assertDontSee('sk_test_secret');
        Log::shouldHaveReceived('warning')->once()->with('Stripe billing operation failed.', \Mockery::on(static fn (array $context): bool => ($context['operation'] ?? null) === 'checkout_create'
            && ($context['failure'] ?? null) === 'stripe_rejected'
            && ($context['http_status'] ?? null) === 400
            && ($context['stripe_error_code'] ?? null) === 'parameter_invalid_empty'
            && ($context['stripe_request_id'] ?? null) === 'req_checkout123'));
    }

    public function test_checkout_connection_failure_shows_safe_recovery_instructions(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        config(['services.stripe.secret' => 'sk_test_secret', 'services.stripe.prices.premium' => 'price_test']);
        Log::spy();
        Http::fake(static function () {
            throw new ConnectionException('PRIVATE_STRIPE_ERROR');
        });

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.checkout'));

        $response->assertServiceUnavailable()
            ->assertSee(route('help.recovery', 'stripe'))
            ->assertSee('照会番号 <strong>BILLING-', false)
            ->assertSee('Billing手続きを開始できませんでした')
            ->assertDontSee('PRIVATE_STRIPE_ERROR')
            ->assertDontSee('sk_test_secret');
        preg_match('/BILLING-[A-F0-9-]{36}/', (string) $response->getContent(), $errorReferenceMatches);
        $this->assertNotEmpty($errorReferenceMatches);
        $response->assertSee(route('support.create', ['category' => 'billing', 'error_reference' => $errorReferenceMatches[0]]));
        Log::shouldHaveReceived('warning')->once()->with('Stripe billing operation failed.', [
            'error_reference' => $errorReferenceMatches[0],
            'operation' => 'price_validation',
            'failure' => 'connection',
        ]);
        $this->assertNull($organization->refresh()->stripe_customer_id);
        $this->assertDatabaseMissing('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'billing_checkout_started',
        ]);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v1/customers') || str_contains($request->url(), '/v1/checkout/sessions'));
    }

    public function test_checkout_creation_connection_failure_shows_safe_recovery_instructions(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $organization->update(['stripe_customer_id' => 'cus_existing_'.Str::uuid()]);
        config(['services.stripe.secret' => 'sk_test_secret', 'services.stripe.prices.premium' => 'price_test']);
        Http::fake(static function (ClientRequest $request) {
            if (str_starts_with($request->url(), 'https://api.stripe.com/v1/prices/price_test')) {
                return Http::response([
                    'active' => true,
                    'type' => 'recurring',
                    'billing_scheme' => 'per_unit',
                    'currency' => 'jpy',
                    'unit_amount' => 9800,
                    'tax_behavior' => 'exclusive',
                    'product' => ['id' => 'prod_test', 'tax_code' => 'txcd_test_service'],
                    'recurring' => ['interval' => 'month', 'interval_count' => 1],
                ], 200);
            }

            if ($request->url() === 'https://api.stripe.com/v1/tax/settings') {
                return Http::response(['status' => 'active', 'defaults' => ['tax_code' => 'txcd_test_default']], 200);
            }

            if (str_starts_with($request->url(), 'https://api.stripe.com/v1/tax/registrations')) {
                return Http::response(['has_more' => false, 'data' => [['id' => 'taxreg_japan', 'country' => 'JP', 'status' => 'active']]], 200);
            }

            throw new ConnectionException('PRIVATE_STRIPE_ERROR');
        });

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.checkout'));

        $response->assertServiceUnavailable()
            ->assertSee(route('help.recovery', 'stripe'))
            ->assertDontSee('PRIVATE_STRIPE_ERROR')
            ->assertDontSee('sk_test_secret');
        $this->assertDatabaseMissing('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'billing_checkout_started',
        ]);
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.stripe.com/v1/prices/price_test'));
    }

    public function test_checkout_creation_timeout_keeps_capacity_reserved_before_the_external_request(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $organization->update(['stripe_customer_id' => 'cus_existing_'.Str::uuid()]);
        config([
            'opsdeck.release_features.storage_addon' => true,
            'opsdeck.storage_quotas.platform_limit_megabytes' => 1000000,
            'opsdeck.storage_quotas.storage_addon_max_gib' => 5,
            'services.stripe.secret' => 'sk_test_secret',
            'services.stripe.prices.premium' => 'price_test',
            'services.stripe.prices.storage_addon_gib' => 'price_storage_test',
            'services.stripe.environment' => 'test',
        ]);
        Http::fake(function (ClientRequest $request) use ($organization) {
            if ($request->method() === 'GET' && str_starts_with($request->url(), 'https://api.stripe.com/v1/prices/price_test')) {
                return Http::response([
                    'active' => true,
                    'type' => 'recurring',
                    'billing_scheme' => 'per_unit',
                    'currency' => 'jpy',
                    'unit_amount' => 9800,
                    'tax_behavior' => 'exclusive',
                    'product' => ['id' => 'prod_test', 'tax_code' => 'txcd_test_service'],
                    'recurring' => ['interval' => 'month', 'interval_count' => 1],
                ], 200);
            }

            if ($request->method() === 'GET' && $request->url() === 'https://api.stripe.com/v1/tax/settings') {
                return Http::response(['status' => 'active', 'defaults' => ['tax_code' => 'txcd_test_default']], 200);
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/v1/checkout/sessions')) {
                $this->assertPlatformStorageQuotaLockAvailable();

                return Http::response(['has_more' => false, 'data' => []], 200);
            }

            if ($request->method() === 'POST' && $request->url() === 'https://api.stripe.com/v1/checkout/sessions') {
                $this->assertPlatformStorageQuotaLockAvailable();

                $reservedOrganization = $organization->fresh();
                $this->assertSame(1, DB::transactionLevel());
                $this->assertSame(0, $reservedOrganization->storage_addon_checkout_reserved_gib);
                $this->assertNull($reservedOrganization->storage_addon_checkout_session_id);

                throw new ConnectionException('PRIVATE_STRIPE_TIMEOUT');
            }

            return Http::response([], 500);
        });

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.checkout'), ['storage_addon_gib' => 0]);

        $response->assertServiceUnavailable()
            ->assertDontSee('PRIVATE_STRIPE_TIMEOUT');
        $reservedOrganization = $organization->fresh();
        $this->assertSame(0, $reservedOrganization->storage_addon_checkout_reserved_gib);
        $this->assertNull($reservedOrganization->storage_addon_checkout_session_id);
        $this->assertTrue($reservedOrganization->storage_addon_checkout_reservation_expires_at->isFuture());
        $this->assertDatabaseMissing('product_events', [
            'organization_id' => $organization->id,
            'event_key' => 'billing_checkout_started',
        ]);
    }

    public function test_customer_portal_connection_failure_shows_safe_recovery_instructions(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $organization->update(['plan_code' => 'premium', 'billing_status' => 'active', 'stripe_customer_id' => 'cus_existing_'.Str::uuid()]);
        config(['services.stripe.secret' => 'sk_test_secret']);
        Http::fake(static function () {
            throw new ConnectionException('PRIVATE_STRIPE_ERROR');
        });

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.portal'));

        $response->assertServiceUnavailable()
            ->assertSee(route('help.recovery', 'stripe'))
            ->assertDontSee('PRIVATE_STRIPE_ERROR')
            ->assertDontSee('sk_test_secret');
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
            ->assertSee('プラン・お支払い')
            ->assertSee('現在のプラン')
            ->assertSee('Premium')
            ->assertSee('利用中')
            ->assertSee('Customer Portalを開く')
            ->assertDontSee('Stripe Checkoutを開始');
    }

    public function test_billing_page_shows_period_end_cancellation_and_disables_new_storage_changes(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $cancelAt = now()->addMonth();
        $organization->update([
            'plan_code' => 'premium',
            'billing_status' => 'active',
            'stripe_customer_id' => 'cus_'.Str::uuid(),
            'stripe_subscription_id' => 'sub_'.Str::uuid(),
            'billing_cancel_at_period_end' => true,
            'billing_cancel_at' => $cancelAt,
            'storage_addon_gib' => 2,
        ]);
        config([
            'opsdeck.storage_quotas.platform_limit_megabytes' => 1000000,
            'opsdeck.storage_quotas.storage_addon_max_gib' => 5,
            'services.stripe.secret' => 'sk_test_secret',
            'services.stripe.prices.premium' => 'price_premium_test',
            'services.stripe.prices.storage_addon_gib' => 'price_storage_test',
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('Premiumの解約が予約されています。')
            ->assertSee($cancelAt->setTimezone($organization->timezone)->format('Y/m/d'))
            ->assertSee('その日までは現在のプランと保存容量をご利用いただけます。')
            ->assertSee('現在の上限')
            ->assertDontSee('容量の変更を次回更新日に予約する');

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.storage-addon.update'), ['quantity' => 3, 'confirm_change' => '1'])
            ->assertServiceUnavailable();

        $organization->refresh();
        $this->assertSame(2, $organization->storage_addon_gib);
        $this->assertNull($organization->storage_addon_pending_gib);
        Http::assertNothingSent();
    }

    public function test_billing_page_does_not_expose_an_unrecognized_billing_status(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $unknownStatus = 'provider_specific_status';
        $organization->update(['billing_status' => $unknownStatus]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('<dd>状態を確認してください</dd>', false)
            ->assertDontSee('<dd>'.$unknownStatus.'</dd>', false);
    }

    public function test_checkout_return_state_gives_clear_guidance_without_trusting_unknown_values(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $session = ['current_organization_id' => $organization->id];

        $this->actingAs($user)->withSession($session)
            ->get(route('billing.index', ['checkout' => 'success']))
            ->assertOk()
            ->assertSee('Stripe Checkoutから戻りました。')
            ->assertSee('Stripeからの契約通知後に反映されます。')
            ->assertSee('role="status"', false);

        $this->actingAs($user)->withSession($session)
            ->get(route('billing.index', ['checkout' => 'cancelled']))
            ->assertOk()
            ->assertSee('今回の申し込みは完了していません。')
            ->assertSee('Stripe Checkoutを開始');

        $this->actingAs($user)->withSession($session)
            ->get(route('billing.index', ['checkout' => 'unexpected']))
            ->assertOk()
            ->assertDontSee('Stripe Checkoutから戻りました。')
            ->assertDontSee('今回の申し込みは完了していません。');
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

    public function test_free_member_can_choose_storage_addon_quantity_for_premium_checkout(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        config([
            'opsdeck.storage_quotas.platform_limit_megabytes' => 1000000,
            'opsdeck.storage_quotas.storage_addon_max_gib' => 5,
            'services.stripe.prices.storage_addon_gib' => 'price_storage_test',
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('name="storage_addon_gib"', false)
            ->assertSee('1GiB（追加 月額100円・税別）', false)
            ->assertSee('選択した追加料金はPremiumの初回請求から加算され')
            ->assertSee('Premiumは1か月ごとに自動更新されます。解約は次回更新日前にBilling画面のCustomer Portalで手続きできます。')
            ->assertSee('解約後も次回更新日までは利用でき、決済済み期間の利用料は日割り返金しません')
            ->assertSee(route('legal.terms'))
            ->assertSee(route('legal.commercial-transactions'));
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

    public function test_billing_page_does_not_advertise_features_excluded_from_release_scope(): void
    {
        [$user, $organization] = $this->member(MembershipRole::Owner);
        config([
            'opsdeck.release_features.google_sheets' => false,
            'opsdeck.release_features.impact_report' => false,
        ]);

        $response = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('billing.index'))
            ->assertOk();

        $response->assertSeeInOrder(['Google Sheets連携', '—', '—']);
        $response->assertSeeInOrder(['導入効果レポート', '—', '—']);
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

    public function test_owner_can_schedule_storage_addon_for_the_next_renewal(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $subscriptionId = 'sub_'.Str::uuid();
        $customerId = 'cus_'.Str::uuid();
        $scheduleId = 'sub_sched_'.Str::uuid();
        $periodStart = now()->subDays(10)->timestamp;
        $periodEnd = now()->addDays(20)->timestamp;
        $scheduleUpdateIdempotencyKeys = [];
        $scheduleUpdateAttempts = 0;
        $organization->update([
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
            'stripe_customer_id' => $customerId,
            'stripe_subscription_id' => $subscriptionId,
            'storage_addon_gib' => 3,
        ]);
        config([
            'opsdeck.storage_quotas.platform_limit_megabytes' => 1000000,
            'opsdeck.storage_quotas.storage_addon_max_gib' => 5,
            'services.stripe.secret' => 'sk_test_secret',
            'services.stripe.prices.premium' => 'price_premium_test',
            'services.stripe.prices.storage_addon_gib' => 'price_storage_test',
        ]);
        $scheduleUpdateBody = null;
        Http::fake(static function (ClientRequest $request) use ($organization, $subscriptionId, $scheduleId, $periodStart, $periodEnd, &$scheduleUpdateBody, &$scheduleUpdateIdempotencyKeys, &$scheduleUpdateAttempts) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/prices/price_storage_test')) {
                return Http::response([
                    'id' => 'price_storage_test',
                    'active' => true,
                    'type' => 'recurring',
                    'billing_scheme' => 'per_unit',
                    'currency' => 'jpy',
                    'unit_amount' => 100,
                    'tax_behavior' => 'exclusive',
                    'recurring' => ['interval' => 'month', 'interval_count' => 1],
                ]);
            }
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/subscriptions/'.$subscriptionId)) {
                return Http::response([
                    'id' => $subscriptionId,
                    'customer' => $organization->stripe_customer_id,
                    'status' => 'active',
                    'metadata' => ['organization_id' => (string) $organization->id],
                    'items' => ['data' => [
                        ['price' => ['id' => 'price_premium_test'], 'quantity' => 1],
                        ['price' => ['id' => 'price_storage_test'], 'quantity' => 3],
                    ]],
                    'schedule' => null,
                ]);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/subscription_schedules')) {
                return Http::response(['id' => $scheduleId]);
            }
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/subscription_schedules/'.$scheduleId)) {
                return Http::response([
                    'id' => $scheduleId,
                    'subscription' => $subscriptionId,
                    'status' => 'active',
                    'metadata' => ['organization_id' => (string) $organization->id, 'purpose' => 'storage_capacity_addon'],
                    'current_phase' => ['start_date' => $periodStart, 'end_date' => $periodEnd],
                    'phases' => [[
                        'start_date' => $periodStart,
                        'end_date' => $periodEnd,
                        'automatic_tax' => ['enabled' => true],
                        'items' => [
                            ['price' => ['id' => 'price_premium_test'], 'quantity' => 1, 'metadata' => ['line_type' => 'base'], 'tax_rates' => []],
                            ['price' => ['id' => 'price_storage_test'], 'quantity' => 3, 'metadata' => [], 'tax_rates' => []],
                        ],
                    ]],
                ]);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/subscription_schedules/'.$scheduleId)) {
                $scheduleUpdateAttempts++;
                $scheduleUpdateIdempotencyKeys[] = $request->header('Idempotency-Key')[0] ?? null;
                if ($scheduleUpdateAttempts === 1) {
                    throw new ConnectionException('PRIVATE_STRIPE_TIMEOUT');
                }

                $scheduleUpdateBody = $request->body();
                parse_str($scheduleUpdateBody, $submittedSchedule);

                return Http::response([
                    'id' => $scheduleId,
                    'subscription' => $subscriptionId,
                    'status' => 'active',
                    'metadata' => ['organization_id' => (string) $organization->id, 'purpose' => 'storage_capacity_addon'],
                    'current_phase' => ['start_date' => $periodStart, 'end_date' => $periodEnd],
                    'phases' => [
                        [
                            'start_date' => $periodStart,
                            'end_date' => $periodEnd,
                            'items' => $submittedSchedule['phases'][0]['items'],
                        ],
                        [
                            'start_date' => $periodEnd,
                            'end_date' => $periodEnd + 2592000,
                            'items' => $submittedSchedule['phases'][1]['items'],
                        ],
                    ],
                ]);
            }

            return Http::response(['error' => ['message' => 'Unexpected request']], 500);
        });

        $actingAsOwner = $this->actingAs($user)->withSession(['current_organization_id' => $organization->id]);
        $actingAsOwner
            ->post(route('billing.storage-addon.update'), ['quantity' => 1, 'confirm_change' => '1'])
            ->assertStatus(503);

        $this->assertSame(1, $scheduleUpdateAttempts);
        $this->assertNull($organization->refresh()->storage_addon_pending_gib);

        $response = $actingAsOwner
            ->post(route('billing.storage-addon.update'), ['quantity' => 1, 'confirm_change' => '1']);

        $response->assertRedirect(route('billing.index'))
            ->assertSessionHas('status.storage_addon_scheduled', true);
        $organization->refresh();
        $this->assertSame(3, $organization->storage_addon_gib);
        $this->assertSame(1, $organization->storage_addon_pending_gib);
        $this->assertSame($periodEnd, $organization->storage_addon_effective_at->timestamp);
        $this->assertSame(2, $scheduleUpdateAttempts);
        $this->assertNotNull($scheduleUpdateIdempotencyKeys[0]);
        $this->assertSame($scheduleUpdateIdempotencyKeys[0], $scheduleUpdateIdempotencyKeys[1]);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/subscription_schedules/'.$scheduleId)
            && str_contains($request->body(), 'price_storage_test')
            && str_contains($request->body(), 'quantity'));
        parse_str((string) $scheduleUpdateBody, $scheduledParameters);
        $this->assertSame('1', $scheduledParameters['phases'][1]['items'][1]['quantity']);
        $this->assertSame('none', $scheduledParameters['phases'][1]['proration_behavior']);
        $this->assertSame('base', $scheduledParameters['phases'][1]['items'][0]['metadata']['line_type']);
    }

    public function test_storage_addon_change_requires_explicit_confirmation_and_does_not_call_stripe(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $organization->update(['plan_code' => 'premium', 'billing_status' => 'active']);
        config([
            'opsdeck.storage_quotas.platform_limit_megabytes' => 1000000,
            'opsdeck.storage_quotas.storage_addon_max_gib' => 5,
            'services.stripe.secret' => 'sk_test_secret',
            'services.stripe.prices.premium' => 'price_premium_test',
            'services.stripe.prices.storage_addon_gib' => 'price_storage_test',
        ]);
        Http::preventStrayRequests();

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->from(route('billing.index'))
            ->post(route('billing.storage-addon.update'), ['quantity' => 1])
            ->assertRedirect(route('billing.index'))
            ->assertSessionHasErrors('confirm_change');

        $this->assertNull($organization->refresh()->storage_addon_pending_gib);
        Http::assertNothingSent();
    }

    public function test_storage_addon_purchase_is_hidden_until_capacity_and_stripe_configuration_are_complete(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $organization->update(['plan_code' => config('opsdeck.premium_plan_code'), 'billing_status' => 'active']);
        config([
            'opsdeck.storage_quotas.platform_limit_megabytes' => null,
            'opsdeck.storage_quotas.storage_addon_max_gib' => null,
            'services.stripe.secret' => null,
            'services.stripe.prices.premium' => null,
            'services.stripe.prices.storage_addon_gib' => null,
        ]);
        Http::preventStrayRequests();

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('追加容量の申込みは現在準備中です。')
            ->assertDontSee(route('billing.storage-addon.update'), false);

        Http::assertNothingSent();
    }

    public function test_storage_addon_is_unavailable_when_excluded_from_release_scope(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $organization->update([
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
            'stripe_customer_id' => 'cus_'.Str::uuid(),
            'stripe_subscription_id' => 'sub_'.Str::uuid(),
        ]);
        $previousStorageAddonReleaseStatus = config('opsdeck.release_features.storage_addon');
        try {
            config(['opsdeck.release_features.storage_addon' => false]);
            Http::preventStrayRequests();

            $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
                ->get(route('billing.index'))
                ->assertOk()
                ->assertDontSee('storage-addon-quantity', false)
                ->assertDontSee('月額100円（税別）')
                ->assertDontSee(route('billing.storage-addon.update'), false);

            $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
                ->post(route('billing.storage-addon.update'), ['quantity' => 'invalid'])
                ->assertNotFound();

            $this->assertNull($organization->refresh()->storage_addon_pending_gib);
            Http::assertNothingSent();
        } finally {
            config(['opsdeck.release_features.storage_addon' => $previousStorageAddonReleaseStatus]);
        }
    }

    public function test_storage_addon_form_shows_the_selected_monthly_price_and_projected_capacity(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $organization->update([
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
            'stripe_customer_id' => 'cus_'.Str::uuid(),
            'stripe_subscription_id' => 'sub_'.Str::uuid(),
            'storage_addon_gib' => 1,
        ]);
        config([
            'opsdeck.storage_quotas.platform_limit_megabytes' => 1000000,
            'opsdeck.storage_quotas.storage_addon_max_gib' => 5,
            'services.stripe.secret' => 'sk_test_secret',
            'services.stripe.prices.premium' => 'price_premium_test',
            'services.stripe.prices.storage_addon_gib' => 'price_storage_test',
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('月額100円（税別）')
            ->assertSee('11.0GiB')
            ->assertSee('data-storage-addon-quantity', false)
            ->assertSee('data-storage-addon-capacity-warning', false);
    }

    public function test_expired_storage_addon_schedule_is_shown_as_pending_confirmation(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $organization->update([
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
            'stripe_customer_id' => 'cus_'.Str::uuid(),
            'stripe_subscription_id' => 'sub_'.Str::uuid(),
            'storage_addon_gib' => 1,
            'storage_addon_pending_gib' => 3,
            'storage_addon_effective_at' => now()->subDay(),
        ]);
        config([
            'opsdeck.storage_quotas.platform_limit_megabytes' => 1000000,
            'opsdeck.storage_quotas.storage_addon_max_gib' => 5,
            'services.stripe.secret' => 'sk_test_secret',
            'services.stripe.prices.premium' => 'price_premium_test',
            'services.stripe.prices.storage_addon_gib' => 'price_storage_test',
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('反映確認中の追加容量')
            ->assertSee('現在有効な追加容量は1GiBです。請求と契約内容を確認しています。')
            ->assertDontSee('次回更新後の予定');
    }

    public function test_storage_addon_does_not_modify_a_schedule_owned_by_another_workflow(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        [$user, $organization] = $this->member(MembershipRole::Owner);
        $subscriptionId = 'sub_'.Str::uuid();
        $customerId = 'cus_'.Str::uuid();
        $scheduleId = 'sub_sched_'.Str::uuid();
        $organization->update([
            'plan_code' => config('opsdeck.premium_plan_code'),
            'billing_status' => 'active',
            'stripe_customer_id' => $customerId,
            'stripe_subscription_id' => $subscriptionId,
        ]);
        config([
            'opsdeck.storage_quotas.platform_limit_megabytes' => 1000000,
            'opsdeck.storage_quotas.storage_addon_max_gib' => 5,
            'services.stripe.secret' => 'sk_test_secret',
            'services.stripe.prices.premium' => 'price_premium_test',
            'services.stripe.prices.storage_addon_gib' => 'price_storage_test',
        ]);
        Http::fake([
            'https://api.stripe.com/v1/prices/price_storage_test*' => Http::response([
                'id' => 'price_storage_test',
                'active' => true,
                'type' => 'recurring',
                'billing_scheme' => 'per_unit',
                'currency' => 'jpy',
                'unit_amount' => 100,
                'tax_behavior' => 'exclusive',
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ]),
            'https://api.stripe.com/v1/subscriptions/'.$subscriptionId => Http::response([
                'id' => $subscriptionId,
                'customer' => $customerId,
                'status' => 'active',
                'metadata' => ['organization_id' => (string) $organization->id],
                'items' => ['data' => [['price' => ['id' => 'price_premium_test'], 'quantity' => 1]]],
                'schedule' => $scheduleId,
            ]),
            'https://api.stripe.com/v1/subscription_schedules/'.$scheduleId => Http::response([
                'id' => $scheduleId,
                'subscription' => $subscriptionId,
                'status' => 'active',
                'metadata' => ['organization_id' => 'another-organization', 'purpose' => 'other_workflow'],
            ]),
        ]);

        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id])
            ->post(route('billing.storage-addon.update'), ['quantity' => 1, 'confirm_change' => '1'])
            ->assertStatus(503);

        $this->assertNull($organization->refresh()->storage_addon_pending_gib);
        Http::assertNotSent(static fn (ClientRequest $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/subscription_schedules/'.$scheduleId));
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

    private function assertPlatformStorageQuotaLockAvailable(): void
    {
        $lock = Cache::lock('opsdeck:platform-storage-quota', 10);
        $this->assertTrue($lock->get(), 'Stripe requests must not hold the shared storage quota lock.');
        $lock->release();
    }
}
