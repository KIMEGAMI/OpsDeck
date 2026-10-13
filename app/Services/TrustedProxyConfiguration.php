<?php

namespace App\Services;

use InvalidArgumentException;

class TrustedProxyConfiguration
{
    private const BITS_PER_BYTE = 8;

    /** @return list<string>|null */
    public function parse(string $configuredProxies): ?array
    {
        $proxies = array_values(array_filter(
            array_map('trim', explode(',', $configuredProxies)),
            static fn (string $proxy): bool => $proxy !== '',
        ));

        if ($proxies === []) {
            return null;
        }

        if (array_intersect($proxies, ['*', '**']) !== []) {
            throw new InvalidArgumentException('Configure explicit trusted proxy IP addresses or CIDRs.');
        }

        foreach ($proxies as $proxy) {
            $parts = explode('/', $proxy);
            $address = $parts[0];
            $packedAddress = inet_pton($address);

            if (count($parts) > 2 || filter_var($address, FILTER_VALIDATE_IP) === false || $packedAddress === false) {
                throw new InvalidArgumentException('Configure trusted proxies as valid IP addresses or CIDRs.');
            }

            if (isset($parts[1])) {
                $prefixLength = preg_match('/\A\d+\z/', $parts[1]) === 1 ? (int) $parts[1] : false;
                $maximumPrefixLength = strlen($packedAddress) * self::BITS_PER_BYTE;

                if ($prefixLength === false || $prefixLength < 1 || $prefixLength > $maximumPrefixLength) {
                    throw new InvalidArgumentException('Configure trusted proxies with a valid CIDR prefix length.');
                }
            }
        }

        return $proxies;
    }
}
