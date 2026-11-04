<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'HEAD', 'POST', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Content-Type', 'X-Headquarters-Key', 'X-Headquarters-Client-Id', 'X-Headquarters-Name', 'X-Apishadai-Key-Id', 'X-Apishadai-Timestamp', 'X-Apishadai-Nonce', 'X-Apishadai-Signature'],
    'exposed_headers' => ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'],
    'max_age' => 0,
    'supports_credentials' => false,
];
