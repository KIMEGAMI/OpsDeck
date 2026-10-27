<?php

namespace Tests\Feature;

use Tests\TestCase;

class StripeEnvironmentConfigTest extends TestCase
{
    public function test_test_mode_uses_only_test_stripe_credentials_and_prices(): void
    {
        $stripe = $this->loadStripeConfiguration([
            'OPSDECK_STRIPE_ENVIRONMENT' => 'test',
            'OPSDECK_STRIPE_TEST_SECRET_KEY' => 'sk_test_opsdeck',
            'OPSDECK_STRIPE_TEST_PRICE_PREMIUM' => 'price_test_premium',
            'OPSDECK_STRIPE_TEST_PRICE_STORAGE_ADDON_GIB' => 'price_test_storage',
            'OPSDECK_STRIPE_TEST_WEBHOOK_SECRET' => 'whsec_test_opsdeck',
            'OPSDECK_STRIPE_LIVE_SECRET_KEY' => 'sk_live_opsdeck',
            'OPSDECK_STRIPE_LIVE_PRICE_PREMIUM' => 'price_live_premium',
            'OPSDECK_STRIPE_LIVE_PRICE_STORAGE_ADDON_GIB' => 'price_live_storage',
            'OPSDECK_STRIPE_LIVE_WEBHOOK_SECRET' => 'whsec_live_opsdeck',
        ]);

        $this->assertSame('test', $stripe['environment']);
        $this->assertSame('sk_test_opsdeck', $stripe['secret']);
        $this->assertSame('price_test_premium', $stripe['prices']['premium']);
        $this->assertSame('price_test_storage', $stripe['prices']['storage_addon_gib']);
        $this->assertSame('whsec_test_opsdeck', $stripe['webhook_secret']);
    }

    public function test_live_mode_uses_only_live_stripe_credentials_and_prices(): void
    {
        $stripe = $this->loadStripeConfiguration([
            'OPSDECK_STRIPE_ENVIRONMENT' => 'live',
            'OPSDECK_STRIPE_TEST_SECRET_KEY' => 'sk_test_opsdeck',
            'OPSDECK_STRIPE_TEST_PRICE_PREMIUM' => 'price_test_premium',
            'OPSDECK_STRIPE_TEST_PRICE_STORAGE_ADDON_GIB' => 'price_test_storage',
            'OPSDECK_STRIPE_TEST_WEBHOOK_SECRET' => 'whsec_test_opsdeck',
            'OPSDECK_STRIPE_LIVE_SECRET_KEY' => 'sk_live_opsdeck',
            'OPSDECK_STRIPE_LIVE_PRICE_PREMIUM' => 'price_live_premium',
            'OPSDECK_STRIPE_LIVE_PRICE_STORAGE_ADDON_GIB' => 'price_live_storage',
            'OPSDECK_STRIPE_LIVE_WEBHOOK_SECRET' => 'whsec_live_opsdeck',
        ]);

        $this->assertSame('live', $stripe['environment']);
        $this->assertSame('sk_live_opsdeck', $stripe['secret']);
        $this->assertSame('price_live_premium', $stripe['prices']['premium']);
        $this->assertSame('price_live_storage', $stripe['prices']['storage_addon_gib']);
        $this->assertSame('whsec_live_opsdeck', $stripe['webhook_secret']);
    }

    public function test_secret_key_with_the_wrong_mode_prefix_is_rejected(): void
    {
        $stripe = $this->loadStripeConfiguration([
            'OPSDECK_STRIPE_ENVIRONMENT' => 'test',
            'OPSDECK_STRIPE_TEST_SECRET_KEY' => 'sk_live_wrong_mode',
            'OPSDECK_STRIPE_TEST_PRICE_PREMIUM' => 'price_test_premium',
            'OPSDECK_STRIPE_TEST_PRICE_STORAGE_ADDON_GIB' => 'price_test_storage',
            'OPSDECK_STRIPE_TEST_WEBHOOK_SECRET' => 'whsec_test_opsdeck',
        ]);

        $this->assertNull($stripe['secret']);
        $this->assertSame('price_test_premium', $stripe['prices']['premium']);
    }

    public function test_invalid_mode_disables_all_selected_stripe_credentials(): void
    {
        $stripe = $this->loadStripeConfiguration([
            'OPSDECK_STRIPE_ENVIRONMENT' => 'production',
            'OPSDECK_STRIPE_TEST_SECRET_KEY' => 'sk_test_opsdeck',
            'OPSDECK_STRIPE_LIVE_SECRET_KEY' => 'sk_live_opsdeck',
        ]);

        $this->assertSame('production', $stripe['environment']);
        $this->assertNull($stripe['secret']);
        $this->assertNull($stripe['prices']['premium']);
        $this->assertNull($stripe['prices']['storage_addon_gib']);
        $this->assertNull($stripe['webhook_secret']);
    }

    public function test_legacy_generic_stripe_variables_are_not_used_as_fallbacks(): void
    {
        $stripe = $this->loadStripeConfiguration([
            'OPSDECK_STRIPE_ENVIRONMENT' => 'test',
            'STRIPE_SECRET' => 'sk_live_legacy',
            'STRIPE_PRICE_ID' => 'price_legacy',
            'STRIPE_PRICE_PREMIUM' => 'price_legacy_premium',
            'STRIPE_PRICE_STORAGE_ADDON_GIB' => 'price_legacy_storage',
            'STRIPE_WEBHOOK_SECRET' => 'whsec_legacy',
        ]);

        $this->assertNull($stripe['secret']);
        $this->assertNull($stripe['prices']['premium']);
        $this->assertNull($stripe['prices']['storage_addon_gib']);
        $this->assertNull($stripe['webhook_secret']);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, mixed>
     */
    private function loadStripeConfiguration(array $overrides): array
    {
        $environmentVariables = [
            'OPSDECK_STRIPE_ENVIRONMENT',
            'OPSDECK_STRIPE_TEST_SECRET_KEY',
            'OPSDECK_STRIPE_TEST_PRICE_PREMIUM',
            'OPSDECK_STRIPE_TEST_PRICE_STORAGE_ADDON_GIB',
            'OPSDECK_STRIPE_TEST_WEBHOOK_SECRET',
            'OPSDECK_STRIPE_LIVE_SECRET_KEY',
            'OPSDECK_STRIPE_LIVE_PRICE_PREMIUM',
            'OPSDECK_STRIPE_LIVE_PRICE_STORAGE_ADDON_GIB',
            'OPSDECK_STRIPE_LIVE_WEBHOOK_SECRET',
            'STRIPE_SECRET',
            'STRIPE_PRICE_ID',
            'STRIPE_PRICE_PREMIUM',
            'STRIPE_PRICE_STORAGE_ADDON_GIB',
            'STRIPE_WEBHOOK_SECRET',
        ];
        $previousValues = [];

        foreach ($environmentVariables as $name) {
            $previousValues[$name] = [
                'process' => getenv($name),
                'environment' => $_ENV[$name] ?? null,
                'server' => $_SERVER[$name] ?? null,
                'environment_exists' => array_key_exists($name, $_ENV),
                'server_exists' => array_key_exists($name, $_SERVER),
            ];
            $value = $overrides[$name] ?? '';
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        try {
            $services = require base_path('config/services.php');

            return $services['stripe'];
        } finally {
            foreach ($previousValues as $name => $previousValue) {
                if ($previousValue['process'] === false) {
                    putenv($name);
                } else {
                    putenv($name.'='.$previousValue['process']);
                }

                if ($previousValue['environment_exists']) {
                    $_ENV[$name] = $previousValue['environment'];
                } else {
                    unset($_ENV[$name]);
                }

                if ($previousValue['server_exists']) {
                    $_SERVER[$name] = $previousValue['server'];
                } else {
                    unset($_SERVER[$name]);
                }
            }
        }
    }
}
