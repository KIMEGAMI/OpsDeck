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
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class BillingController extends Controller
{
    private const STRIPE_CHECKOUT_SESSION_LIST_LIMIT = 100;

    private const STRIPE_CHECKOUT_SESSION_MAX_LIFETIME_SECONDS = 86400;

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
        $checkoutPremiumCapacityAvailable = true;
        $checkoutStorageAddonPriceId = config('services.stripe.prices.storage_addon_gib');
        if (config('opsdeck.release_features.storage_addon')) {
            $storageAddonCapacityService = app(StorageAddonCapacityService::class);
            $maximumCheckoutQuantity = $this->maximumCheckoutQuantityForDisplay($organization, $storageAddonCapacityService);
            $checkoutStorageAddonMaximumQuantity = is_string($checkoutStorageAddonPriceId)
                && trim($checkoutStorageAddonPriceId) !== ''
                ? $maximumCheckoutQuantity
                : null;
            $checkoutPremiumCapacityAvailable = $maximumCheckoutQuantity !== null;
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

        return view('billing.index', compact('organization', 'effectivePlanCode', 'checkoutReturn', 'billingGraceDeadline', 'failedStripeEvent', 'canSubmitCancellationFeedback', 'checkoutAvailable', 'storageQuota', 'storageAddonAvailable', 'storageAddonMaximumQuantity', 'checkoutStorageAddonMaximumQuantity', 'checkoutPremiumCapacityAvailable'));
    }

    private function maximumCheckoutQuantityForDisplay(Organization $organization, StorageAddonCapacityService $capacityService): ?int
    {
        $cacheTtlSeconds = config('opsdeck.storage_quotas.capacity_display_cache_ttl_seconds');
        if (! is_int($cacheTtlSeconds) || $cacheTtlSeconds < 1) {
            return $capacityService->maximumCheckoutQuantity($organization);
        }

        $cacheKey = $this->billingCapacityDisplayCacheKey($organization);
        try {
            $cachedCapacity = Cache::get($cacheKey);
        } catch (Throwable) {
            return $capacityService->maximumCheckoutQuantity($organization);
        }

        if (is_array($cachedCapacity)
            && array_key_exists('maximum_quantity', $cachedCapacity)
            && ($cachedCapacity['maximum_quantity'] === null || is_int($cachedCapacity['maximum_quantity']))) {
            return $cachedCapacity['maximum_quantity'];
        }

        $maximumQuantity = $capacityService->maximumCheckoutQuantity($organization);

        try {
            Cache::put($cacheKey, ['maximum_quantity' => $maximumQuantity], $cacheTtlSeconds);
        } catch (Throwable) {
            return $maximumQuantity;
        }

        return $maximumQuantity;
    }

    private function billingCapacityDisplayCacheKey(Organization $organization): string
    {
        $cacheContext = [
            'organization_id' => $organization->getKey(),
            'organization_updated_at' => $organization->updated_at?->getTimestamp(),
            'premium_plan_code' => config('opsdeck.premium_plan_code'),
            'platform_limit_megabytes' => config('opsdeck.storage_quotas.platform_limit_megabytes'),
            'premium_limit_megabytes' => config('opsdeck.storage_quotas.plan_limit_megabytes.'.config('opsdeck.premium_plan_code')),
            'storage_addon_max_gib' => config('opsdeck.storage_quotas.storage_addon_max_gib'),
        ];

        return 'opsdeck:billing:capacity-display:'.hash('sha256', json_encode($cacheContext, JSON_THROW_ON_ERROR));
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
        $storageAddonCapacityMaximumQuantity = config('opsdeck.release_features.storage_addon')
            ? $storageAddonCapacityService->maximumCheckoutQuantity($organization)
            : null;
        $checkoutStorageAddonMaximumQuantity = is_string($storageAddonPriceId)
            && trim($storageAddonPriceId) !== ''
            ? $storageAddonCapacityMaximumQuantity
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
        if (config('opsdeck.release_features.storage_addon')
            && ($storageAddonCapacityMaximumQuantity === null
                || $storageAddonQuantity > $storageAddonCapacityMaximumQuantity)) {
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
        $checkoutFingerprint = hash('sha256', json_encode([
            'organization_id' => (string) $organization->id,
            'premium_price_id' => $premiumPriceId,
            'storage_addon_price_id' => $storageAddonQuantity > 0 ? $storageAddonPriceId : null,
            'storage_addon_gib' => $storageAddonQuantity,
            'plan_code' => config('opsdeck.premium_plan_code'),
        ], JSON_THROW_ON_ERROR));
        $checkoutParameters['metadata[opsdeck_checkout_fingerprint]'] = $checkoutFingerprint;
        $checkoutResult = $this->checkoutSessionUrl(
            $organization,
            $customerId,
            $checkoutParameters,
            $checkoutFingerprint,
            $storageAddonQuantity,
        );
        if ($checkoutResult instanceof Response) {
            return $checkoutResult;
        }
        app(ProductEventRecorder::class)->recordOnce('billing_checkout_started', $organization);

        return redirect()->away($checkoutResult);
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

    /**
     * @param  array<string, bool|int|string>  $checkoutParameters
     */
    private function checkoutSessionUrl(
        Organization $organization,
        string $customerId,
        array $checkoutParameters,
        string $checkoutFingerprint,
        int $storageAddonQuantity,
    ): string|Response {
        try {
            $organizationLockKey = 'opsdeck:organization-storage-quota:'.hash('sha256', (string) $organization->getKey());
            $result = Cache::lock($organizationLockKey, (int) config('opsdeck.storage_quotas.lock_seconds'))
                ->block((int) config('opsdeck.storage_quotas.lock_wait_seconds'), fn (): array => Cache::lock(
                    'opsdeck:platform-storage-quota',
                    (int) config('opsdeck.storage_quotas.lock_seconds'),
                )->block((int) config('opsdeck.storage_quotas.lock_wait_seconds'), function () use (
                    $organization,
                    $customerId,
                    $checkoutParameters,
                    $checkoutFingerprint,
                    $storageAddonQuantity,
                ): array {
                    $reservationResult = DB::transaction(function () use (
                        $organization,
                        $customerId,
                        $checkoutFingerprint,
                        $storageAddonQuantity,
                    ): array {
                        $lockedOrganization = Organization::query()
                            ->lockForUpdate()
                            ->findOrFail($organization->id);
                        $effectivePlanCode = app(PlanAccessService::class)->effectivePlanCode($lockedOrganization);
                        if ($effectivePlanCode === config('opsdeck.premium_plan_code')
                            || in_array($lockedOrganization->billing_status, ['active', 'trialing', 'past_due'], true)) {
                            return ['status' => 'processing'];
                        }

                        $lastStripeEventCreatedAt = (int) ($lockedOrganization->stripe_last_event_created_at ?? 0);
                        $createdAfter = max(
                            (int) ($lockedOrganization->created_at?->timestamp ?? 0),
                            $lastStripeEventCreatedAt > 0
                                ? $lastStripeEventCreatedAt - self::STRIPE_CHECKOUT_SESSION_MAX_LIFETIME_SECONDS
                                : 0,
                        );
                        $openSessions = $this->listStripeCheckoutSessions($customerId, 'open');
                        if (! $openSessions->successful()
                            || ! is_array($openSessions->json('data'))
                            || ! is_bool($openSessions->json('has_more'))) {
                            return [
                                'status' => 'unavailable',
                                'operation' => 'checkout_session_reconciliation',
                                'diagnostics' => $this->stripeResponseDiagnostics($openSessions),
                            ];
                        }
                        if ($openSessions->json('has_more')) {
                            return [
                                'status' => 'unavailable',
                                'operation' => 'checkout_session_reconciliation',
                                'diagnostics' => ['failure' => 'session_list_incomplete'],
                            ];
                        }

                        $organizationOpenSessions = $this->organizationCheckoutSessions($openSessions->json('data'), (int) $lockedOrganization->id);
                        if (count($organizationOpenSessions) > 1) {
                            return ['status' => 'processing'];
                        }

                        $existingSessionUrl = null;
                        if ($organizationOpenSessions !== []) {
                            $existingSession = $organizationOpenSessions[0];
                            $existingFingerprint = data_get($existingSession, 'metadata.opsdeck_checkout_fingerprint');
                            if (is_string($existingFingerprint) && hash_equals($checkoutFingerprint, $existingFingerprint)) {
                                $existingUrl = $existingSession['url'] ?? null;
                                if (is_string($existingUrl) && $existingUrl !== '') {
                                    $existingSessionUrl = $existingUrl;
                                } else {
                                    return ['status' => 'processing'];
                                }
                            } else {
                                $sessionId = $existingSession['id'] ?? null;
                                if (! is_string($sessionId) || $sessionId === '') {
                                    return ['status' => 'processing'];
                                }

                                $expiredSession = Http::withBasicAuth(config('services.stripe.secret'), '')
                                    ->asForm()
                                    ->timeout(10)
                                    ->post('https://api.stripe.com/v1/checkout/sessions/'.rawurlencode($sessionId).'/expire');
                                if (! $expiredSession->successful() || $expiredSession->json('status') !== 'expired') {
                                    return ['status' => 'processing'];
                                }
                            }
                        }

                        $completedSessions = $this->listStripeCheckoutSessions($customerId, 'complete', $createdAfter);
                        if (! $completedSessions->successful()
                            || ! is_array($completedSessions->json('data'))
                            || ! is_bool($completedSessions->json('has_more'))) {
                            return [
                                'status' => 'unavailable',
                                'operation' => 'checkout_session_reconciliation',
                                'diagnostics' => $this->stripeResponseDiagnostics($completedSessions),
                            ];
                        }
                        if ($completedSessions->json('has_more')) {
                            return [
                                'status' => 'unavailable',
                                'operation' => 'checkout_session_reconciliation',
                                'diagnostics' => ['failure' => 'session_list_incomplete'],
                            ];
                        }
                        if ($this->hasUnprocessedCompletedCheckout($completedSessions->json('data'), (int) $lockedOrganization->id)) {
                            return ['status' => 'processing'];
                        }

                        $capacityService = app(StorageAddonCapacityService::class);
                        if (config('opsdeck.release_features.storage_addon')) {
                            $maximumCheckoutQuantity = $capacityService->maximumCheckoutQuantity($lockedOrganization);
                            if ($maximumCheckoutQuantity === null || $storageAddonQuantity > $maximumCheckoutQuantity) {
                                return ['status' => 'capacity_unavailable'];
                            }
                        }

                        if ($existingSessionUrl !== null) {
                            $existingSession = $organizationOpenSessions[0];
                            $this->reserveCheckoutCapacity(
                                $lockedOrganization,
                                $storageAddonQuantity,
                                data_get($existingSession, 'id'),
                                data_get($existingSession, 'expires_at'),
                            );

                            return ['status' => 'ready', 'url' => $existingSessionUrl];
                        }

                        if (config('opsdeck.release_features.storage_addon')) {
                            $this->reserveCheckoutCapacity($lockedOrganization, $storageAddonQuantity, null);
                        }

                        return ['status' => 'create'];
                    }, 1);

                    if ($reservationResult['status'] !== 'create') {
                        return $reservationResult;
                    }

                    try {
                        $checkout = Http::withBasicAuth(config('services.stripe.secret'), '')
                            ->withHeader('Idempotency-Key', $this->stripeIdempotencyKey('checkout', (int) $organization->id, $checkoutParameters))
                            ->asForm()
                            ->timeout(10)
                            ->post('https://api.stripe.com/v1/checkout/sessions', $checkoutParameters);
                    } catch (ConnectionException) {
                        return [
                            'status' => 'unavailable',
                            'operation' => 'checkout_create',
                            'diagnostics' => [],
                        ];
                    }
                    if (! $checkout->successful() || ! is_string($checkout->json('url'))) {
                        if ($checkout->clientError() && config('opsdeck.release_features.storage_addon')) {
                            DB::transaction(function () use ($organization): void {
                                $lockedOrganization = Organization::query()
                                    ->lockForUpdate()
                                    ->findOrFail($organization->id);
                                $this->clearCheckoutCapacityReservation($lockedOrganization);
                            }, 1);
                        }

                        return [
                            'status' => 'unavailable',
                            'operation' => 'checkout_create',
                            'diagnostics' => $this->stripeResponseDiagnostics($checkout),
                        ];
                    }

                    if (config('opsdeck.release_features.storage_addon')) {
                        DB::transaction(function () use ($organization, $storageAddonQuantity, $checkout): void {
                            $lockedOrganization = Organization::query()
                                ->lockForUpdate()
                                ->findOrFail($organization->id);
                            if ($lockedOrganization->storage_addon_checkout_reserved_gib === null
                                || $lockedOrganization->storage_addon_checkout_reservation_expires_at === null
                                || ! $lockedOrganization->storage_addon_checkout_reservation_expires_at->isFuture()) {
                                return;
                            }

                            $this->reserveCheckoutCapacity(
                                $lockedOrganization,
                                $storageAddonQuantity,
                                $checkout->json('id'),
                                $checkout->json('expires_at'),
                            );
                        }, 1);
                    }

                    return ['status' => 'ready', 'url' => $checkout->json('url')];
                }));
        } catch (LockTimeoutException) {
            return $this->stripeUnavailableResponse('storage_addon_capacity', ['failure' => 'capacity_lock_unavailable']);
        } catch (ConnectionException) {
            return $this->stripeUnavailableResponse('checkout_session_reconciliation', ['failure' => 'connection']);
        }

        if ($result['status'] === 'processing') {
            return to_route('billing.index')
                ->withInput(['storage_addon_gib' => $storageAddonQuantity])
                ->withErrors([
                    'checkout' => '別の決済手続きが進行中、または決済完了通知を確認しています。二重請求防止のため、新しいCheckoutは開始しません。時間をおいてBilling画面を再読み込みしてください。',
                ]);
        }
        if ($result['status'] === 'unavailable') {
            return $this->stripeUnavailableResponse(
                $result['operation'] ?? 'checkout_session_reconciliation',
                $result['diagnostics'] ?? [],
            );
        }
        if ($result['status'] === 'capacity_unavailable') {
            return $this->stripeUnavailableResponse('storage_addon_capacity', ['failure' => 'capacity_unavailable']);
        }

        return $result['url'];
    }

    private function reserveCheckoutCapacity(Organization $organization, int $quantity, mixed $sessionId, mixed $expiresAt = null): void
    {
        $sessionLifetimeSeconds = self::STRIPE_CHECKOUT_SESSION_MAX_LIFETIME_SECONDS;
        $reservationGraceSeconds = max(0, (int) config('opsdeck.storage_quotas.checkout_reservation_grace_seconds'));
        $expiryTimestamp = is_int($expiresAt) && $expiresAt > now()->timestamp
            ? $expiresAt
            : now()->timestamp + $sessionLifetimeSeconds;
        $reservationExpiresAt = CarbonImmutable::createFromTimestamp($expiryTimestamp)
            ->addSeconds($reservationGraceSeconds);

        $organization->forceFill([
            'storage_addon_checkout_reserved_gib' => $quantity,
            'storage_addon_checkout_reservation_expires_at' => $reservationExpiresAt,
            'storage_addon_checkout_session_id' => is_string($sessionId) && $sessionId !== '' ? $sessionId : null,
        ])->save();
    }

    private function clearCheckoutCapacityReservation(Organization $organization): void
    {
        $organization->forceFill([
            'storage_addon_checkout_reserved_gib' => null,
            'storage_addon_checkout_reservation_expires_at' => null,
            'storage_addon_checkout_session_id' => null,
        ])->save();
    }

    private function listStripeCheckoutSessions(string $customerId, string $status, ?int $createdAfter = null): ClientResponse
    {
        $parameters = [
            'customer' => $customerId,
            'status' => $status,
            'limit' => self::STRIPE_CHECKOUT_SESSION_LIST_LIMIT,
        ];
        if ($createdAfter !== null) {
            $parameters['created[gte]'] = $createdAfter;
        }

        return Http::withBasicAuth(config('services.stripe.secret'), '')
            ->timeout(10)
            ->get('https://api.stripe.com/v1/checkout/sessions', $parameters);
    }

    /** @param  array<int, mixed>  $sessions */
    private function hasUnprocessedCompletedCheckout(array $sessions, int $organizationId): bool
    {
        foreach ($sessions as $session) {
            if (! is_array($session) || ($session['mode'] ?? null) !== 'subscription') {
                continue;
            }

            $sessionOrganizationId = data_get($session, 'metadata.organization_id') ?? ($session['client_reference_id'] ?? null);
            if ((is_string($sessionOrganizationId) || is_int($sessionOrganizationId))
                && hash_equals((string) $organizationId, (string) $sessionOrganizationId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $sessions
     * @return list<array<string, mixed>>
     */
    private function organizationCheckoutSessions(array $sessions, int $organizationId): array
    {
        $organizationSessions = [];
        foreach ($sessions as $session) {
            if (! is_array($session) || ($session['mode'] ?? null) !== 'subscription') {
                continue;
            }

            $sessionOrganizationId = data_get($session, 'metadata.organization_id') ?? ($session['client_reference_id'] ?? null);
            if ((is_string($sessionOrganizationId) || is_int($sessionOrganizationId))
                && hash_equals((string) $organizationId, (string) $sessionOrganizationId)) {
                $organizationSessions[] = $session;
            }
        }

        return $organizationSessions;
    }
}
