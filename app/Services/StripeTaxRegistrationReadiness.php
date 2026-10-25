<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class StripeTaxRegistrationReadiness
{
    private const PAGE_SIZE = 100;

    private const CONNECT_TIMEOUT_SECONDS = 3;

    private const REQUEST_TIMEOUT_SECONDS = 10;

    public function hasActiveRegistration(string $apiSecret, string $countryCode): bool
    {
        if (trim($apiSecret) === '' || preg_match('/^[A-Z]{2}$/D', $countryCode) !== 1) {
            return false;
        }

        $startingAfter = null;

        do {
            $parameters = ['status' => 'active', 'limit' => self::PAGE_SIZE];
            if ($startingAfter !== null) {
                $parameters['starting_after'] = $startingAfter;
            }

            try {
                $response = Http::withBasicAuth($apiSecret, '')
                    ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                    ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                    ->get('https://api.stripe.com/v1/tax/registrations', $parameters);
            } catch (\Throwable) {
                return false;
            }

            $registrations = $response->json('data');
            if (! $response->successful() || ! is_array($registrations)) {
                return false;
            }

            foreach ($registrations as $registration) {
                if (is_array($registration)
                    && ($registration['country'] ?? null) === $countryCode
                    && ($registration['status'] ?? null) === 'active') {
                    return true;
                }
            }

            if ($response->json('has_more') !== true) {
                return false;
            }

            $lastRegistration = end($registrations);
            $lastRegistrationId = is_array($lastRegistration) ? ($lastRegistration['id'] ?? null) : null;
            if (! is_string($lastRegistrationId) || $lastRegistrationId === $startingAfter) {
                return false;
            }

            $startingAfter = $lastRegistrationId;
        } while (true);
    }
}
