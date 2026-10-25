<?php

namespace App\Services;

class PhpExtensionReadiness
{
    /** @var list<string> */
    private const REQUIRED_EXTENSIONS = ['mbstring', 'openssl', 'pdo_mysql', 'sodium', 'xmlreader', 'zip'];

    /** @param list<string>|null $loadedExtensions */
    public function missingExtensions(?array $loadedExtensions = null): array
    {
        $loadedExtensions ??= get_loaded_extensions();
        $loadedExtensionLookup = array_fill_keys(array_map('strtolower', $loadedExtensions), true);

        return array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            static fn (string $extension): bool => ! isset($loadedExtensionLookup[$extension]),
        ));
    }
}
