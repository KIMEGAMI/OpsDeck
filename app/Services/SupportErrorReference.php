<?php

namespace App\Services;

class SupportErrorReference
{
    public const MAX_LENGTH = 80;

    private const REFERENCE_PATTERN = '/\A(?:[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}|BILLING-[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}|IMP-[1-9][0-9]*|STRIPE-evt_[a-z0-9_-]+)\z/i';

    public static function normalize(mixed $errorReference): ?string
    {
        if (! is_string($errorReference)
            || strlen($errorReference) > self::MAX_LENGTH
            || preg_match(self::REFERENCE_PATTERN, $errorReference) !== 1) {
            return null;
        }

        if (strncasecmp($errorReference, 'STRIPE-', 7) === 0) {
            return 'STRIPE-'.substr($errorReference, 7);
        }

        if (strncasecmp($errorReference, 'BILLING-', 8) === 0) {
            return 'BILLING-'.strtolower(substr($errorReference, 8));
        }

        if (strncasecmp($errorReference, 'IMP-', 4) === 0) {
            return 'IMP-'.substr($errorReference, 4);
        }

        return strtolower($errorReference);
    }
}
