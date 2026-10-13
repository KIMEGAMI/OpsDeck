<?php

namespace Tests\Unit;

use App\Services\TrustedProxyConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TrustedProxyConfigurationTest extends TestCase
{
    public function test_an_empty_proxy_list_preserves_the_framework_default(): void
    {
        $this->assertNull((new TrustedProxyConfiguration)->parse(' , '));
    }

    public function test_it_parses_explicit_ipv4_ipv6_and_cidr_entries(): void
    {
        $this->assertSame(
            ['192.0.2.10', '192.0.2.0/24', '2001:db8::1', '2001:db8::/32'],
            (new TrustedProxyConfiguration)->parse('192.0.2.10, 192.0.2.0/24, 2001:db8::1, 2001:db8::/32'),
        );
    }

    #[DataProvider('invalidProxyLists')]
    public function test_it_rejects_wildcards_and_malformed_ip_or_cidr_entries(string $proxyList): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TrustedProxyConfiguration)->parse($proxyList);
    }

    /** @return array<string, array{string}> */
    public static function invalidProxyLists(): array
    {
        return [
            'wildcard' => ['*'],
            'double wildcard' => ['**'],
            'invalid address' => ['not-an-ip'],
            'invalid IPv4 prefix' => ['192.0.2.0/33'],
            'invalid IPv6 prefix' => ['2001:db8::/129'],
            'IPv4 all-address CIDR' => ['0.0.0.0/0'],
            'IPv6 all-address CIDR' => ['::/0'],
            'invalid prefix text' => ['192.0.2.0/network'],
            'multiple separators' => ['192.0.2.0/24/1'],
            'zero string' => ['0'],
        ];
    }
}
