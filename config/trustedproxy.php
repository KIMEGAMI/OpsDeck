<?php

use App\Services\TrustedProxyConfiguration;

return [
    'proxies' => app(TrustedProxyConfiguration::class)->parse((string) env('OPSDECK_TRUSTED_PROXIES', '')),
];
