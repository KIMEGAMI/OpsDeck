<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\StripeWebhookEvent;
use App\Services\PlanAccessService;
use App\Services\ProductEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class BillingController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $this->organization($request);
        $this->authorize('manageBilling', $organization);
        $failedStripeEventQuery = StripeWebhookEvent::query()
            ->where('organization_id', $organization->id)
            ->whereNotNull('processing_error');
        if ($organization->stripe_last_event_created_at !== null) {
            $failedStripeEventQuery->where('stripe_created_at', '>=', $organization->stripe_last_event_created_at);
        }
        $failedStripeEvent = $failedStripeEventQuery->orderByDesc('stripe_created_at')->first();
        $effectivePlanCode = app(PlanAccessService::class)->effectivePlanCode($organization);
        $billingGraceDeadline = null;
        if ($organization->billing_status === 'past_due') {
            $pastDueSince = $organization->billing_past_due_since;
            if ($pastDueSince !== null) {
                $pastDueSince = CarbonImmutable::instance($pastDueSince);
            } elseif ($organization->stripe_last_event_created_at !== null) {
                $pastDueSince = CarbonImmutable::createFromTimestamp($organization->stripe_last_event_created_at);
            }

            if ($pastDueSince !== null) {
                $billingGraceDeadline = $pastDueSince
                    ->addDays(max(0, (int) config('opsdeck.billing.past_due_grace_days')))
                    ->setTimezone($organization->timezone);
            }
        }

        return view('billing.index', compact('organization', 'effectivePlanCode', 'billingGraceDeadline', 'failedStripeEvent'));
    }

    public function checkout(Request $request): RedirectResponse|Response
    {
        $organization = $this->organization($request);
        $this->authorize('manageBilling', $organization);
        $effectivePlanCode = app(PlanAccessService::class)->effectivePlanCode($organization);
        abort_unless(
            $effectivePlanCode !== config('opsdeck.premium_plan_code')
                && ! in_array($organization->billing_status, ['active', 'trialing', 'past_due'], true),
            409,
            '現在の契約状態では新しいCheckoutを開始できません。Billing画面から契約内容を確認してください。',
        );
        $premiumPriceId = config('services.stripe.prices.premium');
        abort_unless(config('services.stripe.secret') && is_string($premiumPriceId) && $premiumPriceId !== '', 503, 'Stripe Billingが設定されていません。');
        try {
            $stripePrice = Http::withBasicAuth(config('services.stripe.secret'), '')
                ->timeout(10)
                ->get('https://api.stripe.com/v1/prices/'.rawurlencode($premiumPriceId), ['expand[]' => 'product']);
        } catch (ConnectionException) {
            return $this->stripeUnavailableResponse('price_validation');
        }
        if (! (
            $stripePrice->successful()
                && $stripePrice->json('active') === true
                && $stripePrice->json('type') === 'recurring'
                && $stripePrice->json('billing_scheme') === 'per_unit'
                && $stripePrice->json('currency') === 'jpy'
                && $stripePrice->json('unit_amount') === (int) config('opsdeck.plans.premium.monthly_price_jpy')
                && $stripePrice->json('tax_behavior') === 'exclusive'
                && $stripePrice->json('recurring.interval') === 'month'
                && (int) $stripePrice->json('recurring.interval_count') === 1
        )) {
            return $this->stripeUnavailableResponse('price_validation');
        }
        try {
            $stripeTaxSettings = Http::withBasicAuth(config('services.stripe.secret'), '')
                ->timeout(10)
                ->get('https://api.stripe.com/v1/tax/settings');
        } catch (ConnectionException) {
            return $this->stripeUnavailableResponse('tax_validation');
        }

        $productTaxCode = $stripePrice->json('product.tax_code');
        $defaultTaxCode = $stripeTaxSettings->json('defaults.tax_code');
        $hasTaxCode = (is_string($productTaxCode) && trim($productTaxCode) !== '')
            || (is_string($defaultTaxCode) && trim($defaultTaxCode) !== '');
        if (! $stripeTaxSettings->successful() || $stripeTaxSettings->json('status') !== 'active' || ! $hasTaxCode) {
            return $this->stripeUnavailableResponse('tax_validation');
        }

        $customerId = $organization->stripe_customer_id;
        if ($customerId === null) {
            $customerParameters = ['name' => $organization->name, 'metadata[organization_id]' => (string) $organization->id];
            try {
                $customer = Http::withBasicAuth(config('services.stripe.secret'), '')
                    ->withHeader('Idempotency-Key', $this->stripeIdempotencyKey('customer', (int) $organization->id, $customerParameters))
                    ->asForm()
                    ->timeout(10)
                    ->post('https://api.stripe.com/v1/customers', $customerParameters);
            } catch (ConnectionException) {
                return $this->stripeUnavailableResponse('customer_create');
            }
            if (! $customer->successful() || ! is_string($customer->json('id'))) {
                return $this->stripeUnavailableResponse('customer_create');
            }
            $customerId = $customer->json('id');
            $organization->update(['stripe_customer_id' => $customerId]);
        }
        $checkoutParameters = [
            'mode' => 'subscription',
            'customer' => $customerId,
            'client_reference_id' => (string) $organization->id,
            'billing_address_collection' => 'required',
            'automatic_tax[enabled]' => true,
            'line_items[0][price]' => $premiumPriceId,
            'line_items[0][quantity]' => 1,
            'success_url' => route('billing.index').'?checkout=success',
            'cancel_url' => route('billing.index').'?checkout=cancelled',
            'metadata[organization_id]' => (string) $organization->id,
            'metadata[plan_code]' => config('opsdeck.premium_plan_code'),
            'subscription_data[metadata][organization_id]' => (string) $organization->id,
            'subscription_data[metadata][plan_code]' => config('opsdeck.premium_plan_code'),
        ];
        try {
            $checkout = Http::withBasicAuth(config('services.stripe.secret'), '')
                ->withHeader('Idempotency-Key', $this->stripeIdempotencyKey('checkout', (int) $organization->id, $checkoutParameters))
                ->asForm()
                ->timeout(10)
                ->post('https://api.stripe.com/v1/checkout/sessions', $checkoutParameters);
        } catch (ConnectionException) {
            return $this->stripeUnavailableResponse('checkout_create');
        }
        if (! $checkout->successful() || ! is_string($checkout->json('url'))) {
            return $this->stripeUnavailableResponse('checkout_create');
        }
        app(ProductEventRecorder::class)->recordOnce('billing_checkout_started', $organization);

        return redirect()->away($checkout->json('url'));
    }

    public function portal(Request $request): RedirectResponse|Response
    {
        $organization = $this->organization($request);
        $this->authorize('manageBilling', $organization);
        abort_unless(config('services.stripe.secret') && $organization->stripe_customer_id, 422, 'Stripe Customerが登録されていません。');
        try {
            $portal = Http::withBasicAuth(config('services.stripe.secret'), '')->asForm()->timeout(10)->post('https://api.stripe.com/v1/billing_portal/sessions', ['customer' => $organization->stripe_customer_id, 'return_url' => route('billing.index')]);
        } catch (ConnectionException) {
            return $this->stripeUnavailableResponse('portal_session');
        }
        if (! $portal->successful() || ! is_string($portal->json('url'))) {
            return $this->stripeUnavailableResponse('portal_session');
        }

        return redirect()->away($portal->json('url'));
    }

    private function organization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');

        return $organization;
    }

    private function stripeUnavailableResponse(string $operation): Response
    {
        $errorReference = 'BILLING-'.Str::upper((string) Str::uuid());
        try {
            Log::warning('Stripe billing operation failed.', [
                'error_reference' => $errorReference,
                'operation' => $operation,
            ]);
        } catch (Throwable) {
            // Keep the customer-facing recovery response available if logging is unavailable.
        }

        return response()->view('billing.unavailable', [
            'errorReference' => $errorReference,
            'supportEmail' => config('opsdeck.support_email'),
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }

    /**
     * @param  array<string, bool|int|string>  $parameters
     */
    private function stripeIdempotencyKey(string $operation, int $organizationId, array $parameters): string
    {
        return 'opsdeck-'.$operation.'-'.$organizationId.'-'.hash('sha256', json_encode($parameters, JSON_THROW_ON_ERROR));
    }
}
