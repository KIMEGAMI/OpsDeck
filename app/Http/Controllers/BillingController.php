<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStorageAddonRequest;
use App\Models\Organization;
use App\Models\StripeWebhookEvent;
use App\Services\CommercialInformationReadiness;
use App\Services\OrganizationStorageQuotaService;
use App\Services\PlanAccessService;
use App\Services\ProductEventRecorder;
use App\Services\StorageAddonCapacityService;
use App\Services\StorageAddonScheduleService;
use App\Services\StripeTaxRegistrationReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as ClientResponse;
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
        if (! (bool) config('opsdeck.billing.enabled')) {
            return view('billing.free-only');
        }

        $failedStripeEventQuery = StripeWebhookEvent::query()
            ->where('organization_id', $organization->id)
            ->whereNotNull('processing_error');
        if ($organization->stripe_last_event_created_at !== null) {
            $failedStripeEventQuery->where('stripe_created_at', '>=', $organization->stripe_last_event_created_at);
        }
        $failedStripeEvent = $failedStripeEventQuery->orderByDesc('stripe_created_at')->first();
        $effectivePlanCode = app(PlanAccessService::class)->effectivePlanCode($organization);
        $checkoutAvailable = ! app()->environment('production')
            || app(CommercialInformationReadiness::class)->isConfigured();
        $checkoutReturn = match ($request->query('checkout')) {
            'success' => 'success',
            'cancelled' => 'cancelled',
            default => null,
        };
        $canSubmitCancellationFeedback = in_array($organization->billing_status, ['active', 'trialing', 'past_due', 'cancelled'], true);
        $storageQuota = app(OrganizationStorageQuotaService::class)->summary($organization);
        $storageAddonService = app(StorageAddonScheduleService::class);
        $storageAddonAvailable = $storageAddonService->isAvailableFor($organization);
        $storageAddonMaximumQuantity = $storageAddonService->maximumQuantity();
        $checkoutStorageAddonMaximumQuantity = null;
        $checkoutStorageAddonPriceId = config('services.stripe.prices.storage_addon_gib');
        if (config('opsdeck.release_features.storage_addon')
            && is_string($checkoutStorageAddonPriceId)
            && trim($checkoutStorageAddonPriceId) !== '') {
            $checkoutStorageAddonMaximumQuantity = app(StorageAddonCapacityService::class)->maximumCheckoutQuantity();
        }
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

        return view('billing.index', compact('organization', 'effectivePlanCode', 'checkoutReturn', 'billingGraceDeadline', 'failedStripeEvent', 'canSubmitCancellationFeedback', 'checkoutAvailable', 'storageQuota', 'storageAddonAvailable', 'storageAddonMaximumQuantity', 'checkoutStorageAddonMaximumQuantity'));
    }

    public function updateStorageAddon(StoreStorageAddonRequest $request, StorageAddonScheduleService $storageAddonScheduleService): RedirectResponse|Response
    {
        abort_unless((bool) config('opsdeck.billing.enabled'), Response::HTTP_NOT_FOUND);
        $organization = $this->organization($request);
        $this->authorize('manageBilling', $organization);
        abort_unless(config('opsdeck.release_features.storage_addon'), Response::HTTP_NOT_FOUND);
        $quantity = (int) $request->validated('quantity');

        if (! $storageAddonScheduleService->isAvailableFor($organization)) {
            return $this->stripeUnavailableResponse('storage_addon_not_configured');
        }

        if (! $storageAddonScheduleService->maximumQuantity()) {
            return $this->stripeUnavailableResponse('storage_addon_not_configured');
        }

        try {
            $effectiveAt = $storageAddonScheduleService->schedule($organization, $quantity);
        } catch (Throwable) {
            return $this->stripeUnavailableResponse('storage_addon_schedule');
        }

        return to_route('billing.index')->with('status', [
            'storage_addon_scheduled' => true,
            'effective_at' => $effectiveAt->setTimezone($organization->timezone)->format('Y/m/d'),
            'quantity' => $quantity,
        ]);
    }

    public function checkout(Request $request, StripeTaxRegistrationReadiness $taxRegistrationReadiness): RedirectResponse|Response
    {
        abort_unless((bool) config('opsdeck.billing.enabled'), Response::HTTP_NOT_FOUND);
        $organization = $this->organization($request);
        $this->authorize('manageBilling', $organization);
        if (app()->environment('production') && ! app(CommercialInformationReadiness::class)->isConfigured()) {
            return to_route('billing.index');
        }

        $effectivePlanCode = app(PlanAccessService::class)->effectivePlanCode($organization);
        abort_unless(
            $effectivePlanCode !== config('opsdeck.premium_plan_code')
                && ! in_array($organization->billing_status, ['active', 'trialing', 'past_due'], true),
            409,
            '現在の契約状態では新しいCheckoutを開始できません。Billing画面から契約内容を確認してください。',
        );
        $storageAddonCapacityService = app(StorageAddonCapacityService::class);
        $storageAddonPriceId = config('services.stripe.prices.storage_addon_gib');
        $checkoutStorageAddonMaximumQuantity = config('opsdeck.release_features.storage_addon')
            && is_string($storageAddonPriceId)
            && trim($storageAddonPriceId) !== ''
            ? $storageAddonCapacityService->maximumCheckoutQuantity()
            : null;
        $validatedInput = $request->validate([
            'storage_addon_gib' => [
                'sometimes',
                'integer',
                'min:0',
                'max:'.($checkoutStorageAddonMaximumQuantity ?? 0),
            ],
        ]);
        $storageAddonQuantity = (int) ($validatedInput['storage_addon_gib'] ?? 0);
        if ($storageAddonQuantity > 0 && ! $storageAddonCapacityService->canStartPremiumSubscriptionWithAddon($storageAddonQuantity)) {
            return $this->stripeUnavailableResponse('storage_addon_capacity', ['failure' => 'capacity_unavailable']);
        }
        $premiumPriceId = config('services.stripe.prices.premium');
        abort_unless(config('services.stripe.secret') && is_string($premiumPriceId) && $premiumPriceId !== '', 503, 'Stripe Billingが設定されていません。');
        try {
            $stripePrice = Http::withBasicAuth(config('services.stripe.secret'), '')
                ->timeout(10)
                ->get('https://api.stripe.com/v1/prices/'.rawurlencode($premiumPriceId), ['expand[]' => 'product']);
        } catch (ConnectionException) {
            return $this->stripeUnavailableResponse('price_validation', ['failure' => 'connection']);
        }
        if (! $stripePrice->successful()) {
            return $this->stripeUnavailableResponse('price_validation', $this->stripeResponseDiagnostics($stripePrice));
        }

        $priceChecks = [
            'active' => $stripePrice->json('active') === true,
            'type' => $stripePrice->json('type') === 'recurring',
            'billing_scheme' => $stripePrice->json('billing_scheme') === 'per_unit',
            'currency' => $stripePrice->json('currency') === 'jpy',
            'unit_amount' => $stripePrice->json('unit_amount') === (int) config('opsdeck.plans.premium.monthly_price_jpy'),
            'recurring.interval' => $stripePrice->json('recurring.interval') === 'month',
            'recurring.interval_count' => (int) $stripePrice->json('recurring.interval_count') === 1,
        ];
        if ($stripePrice->json('tax_behavior') === 'inclusive') {
            $priceChecks['tax_behavior'] = false;
        }
        $failedPriceChecks = array_keys(array_filter($priceChecks, static fn (bool $isValid): bool => ! $isValid));
        if ($failedPriceChecks !== []) {
            return $this->stripeUnavailableResponse('price_validation', [
                'failure' => 'price_mismatch',
                'failed_checks' => $failedPriceChecks,
            ]);
        }
        $stripeStorageAddonPrice = null;
        if ($storageAddonQuantity > 0) {
            try {
                $stripeStorageAddonPrice = Http::withBasicAuth(config('services.stripe.secret'), '')
                    ->timeout(10)
                    ->get('https://api.stripe.com/v1/prices/'.rawurlencode((string) $storageAddonPriceId), ['expand[]' => 'product']);
            } catch (ConnectionException) {
                return $this->stripeUnavailableResponse('storage_addon_price_validation', ['failure' => 'connection']);
            }
            if (! $stripeStorageAddonPrice->successful()) {
                return $this->stripeUnavailableResponse('storage_addon_price_validation', $this->stripeResponseDiagnostics($stripeStorageAddonPrice));
            }

            $storageAddonPriceChecks = [
                'active' => $stripeStorageAddonPrice->json('active') === true,
                'type' => $stripeStorageAddonPrice->json('type') === 'recurring',
                'billing_scheme' => $stripeStorageAddonPrice->json('billing_scheme') === 'per_unit',
                'currency' => $stripeStorageAddonPrice->json('currency') === 'jpy',
                'unit_amount' => $stripeStorageAddonPrice->json('unit_amount') === (int) config('opsdeck.storage_quotas.storage_addon_monthly_price_jpy'),
                'tax_behavior' => $stripeStorageAddonPrice->json('tax_behavior') === 'exclusive',
                'recurring.interval' => $stripeStorageAddonPrice->json('recurring.interval') === 'month',
                'recurring.interval_count' => (int) $stripeStorageAddonPrice->json('recurring.interval_count') === 1,
            ];
            $failedStorageAddonPriceChecks = array_keys(array_filter($storageAddonPriceChecks, static fn (bool $isValid): bool => ! $isValid));
            if ($failedStorageAddonPriceChecks !== []) {
                return $this->stripeUnavailableResponse('storage_addon_price_validation', [
                    'failure' => 'price_mismatch',
                    'failed_checks' => $failedStorageAddonPriceChecks,
                ]);
            }
        }
        try {
            $stripeTaxSettings = Http::withBasicAuth(config('services.stripe.secret'), '')
                ->timeout(10)
                ->get('https://api.stripe.com/v1/tax/settings');
        } catch (ConnectionException) {
            return $this->stripeUnavailableResponse('tax_validation');
        }

        $productTaxCode = $stripePrice->json('product.tax_code');
        $hasTaxCode = is_string($productTaxCode) && trim($productTaxCode) !== '';
        $storageAddonProductTaxCode = $stripeStorageAddonPrice?->json('product.tax_code');
        $hasStorageAddonTaxCode = $storageAddonQuantity === 0
            || (is_string($storageAddonProductTaxCode) && trim($storageAddonProductTaxCode) !== '');
        if (! $stripeTaxSettings->successful() || $stripeTaxSettings->json('status') !== 'active' || ! $hasTaxCode || ! $hasStorageAddonTaxCode) {
            return $this->stripeUnavailableResponse('tax_validation');
        }
        $priceTaxBehavior = $stripePrice->json('tax_behavior');
        $effectiveTaxBehavior = match ($priceTaxBehavior) {
            'exclusive' => 'exclusive',
            'inclusive' => 'inclusive',
            'unspecified', null => $stripeTaxSettings->json('defaults.tax_behavior'),
            default => null,
        };
        if ($effectiveTaxBehavior !== 'exclusive') {
            return $this->stripeUnavailableResponse('price_validation', [
                'failure' => 'price_mismatch',
                'failed_checks' => ['tax_behavior'],
            ]);
        }
        if (config('services.stripe.environment') !== 'test' || app()->environment('production')) {
            if (! $taxRegistrationReadiness->hasActiveRegistration((string) config('services.stripe.secret'), 'JP')) {
                return $this->stripeUnavailableResponse('tax_validation');
            }
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
                return $this->stripeUnavailableResponse('customer_create', $this->stripeResponseDiagnostics($customer));
            }
            $customerId = $customer->json('id');
            $organization->update(['stripe_customer_id' => $customerId]);
        }
        $checkoutParameters = [
            'mode' => 'subscription',
            'customer' => $customerId,
            'client_reference_id' => (string) $organization->id,
            'billing_address_collection' => 'required',
            'automatic_tax[enabled]' => 'true',
            'line_items[0][price]' => $premiumPriceId,
            'line_items[0][quantity]' => 1,
            'success_url' => route('billing.index').'?checkout=success',
            'cancel_url' => route('billing.index').'?checkout=cancelled',
            'metadata[organization_id]' => (string) $organization->id,
            'metadata[plan_code]' => config('opsdeck.premium_plan_code'),
            'subscription_data[metadata][organization_id]' => (string) $organization->id,
            'subscription_data[metadata][plan_code]' => config('opsdeck.premium_plan_code'),
        ];
        if ($storageAddonQuantity > 0) {
            $checkoutParameters['line_items[1][price]'] = (string) $storageAddonPriceId;
            $checkoutParameters['line_items[1][quantity]'] = $storageAddonQuantity;
        }
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
            return $this->stripeUnavailableResponse('checkout_create', $this->stripeResponseDiagnostics($checkout));
        }
        app(ProductEventRecorder::class)->recordOnce('billing_checkout_started', $organization);

        return redirect()->away($checkout->json('url'));
    }

    public function portal(Request $request): RedirectResponse|Response
    {
        abort_unless((bool) config('opsdeck.billing.enabled'), Response::HTTP_NOT_FOUND);
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

    /**
     * @return array{failure: string, http_status?: int, stripe_error_code?: string, stripe_request_id?: string}
     */
    private function stripeResponseDiagnostics(ClientResponse $response): array
    {
        $diagnostics = [
            'failure' => 'stripe_rejected',
            'http_status' => $response->status(),
        ];
        $errorCode = $response->json('error.code');
        if (is_string($errorCode) && preg_match('/^[a-z0-9_]{1,64}$/D', $errorCode) === 1) {
            $diagnostics['stripe_error_code'] = $errorCode;
        }

        $requestId = $response->header('Request-Id');
        if (is_string($requestId) && preg_match('/^req_[A-Za-z0-9]{1,64}$/D', $requestId) === 1) {
            $diagnostics['stripe_request_id'] = $requestId;
        }

        return $diagnostics;
    }

    /**
     * @param  array{failure?: string, http_status?: int, stripe_error_code?: string, stripe_request_id?: string, failed_checks?: list<string>}  $diagnostics
     */
    private function stripeUnavailableResponse(string $operation, array $diagnostics = []): Response
    {
        $errorReference = 'BILLING-'.Str::upper((string) Str::uuid());
        try {
            Log::warning('Stripe billing operation failed.', array_merge([
                'error_reference' => $errorReference,
                'operation' => $operation,
            ], $diagnostics));
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
