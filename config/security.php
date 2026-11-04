<?php

return [
    'rate_limits' => [
        'requests_per_minute' => max(1, (int) env('HTTP_RATE_LIMIT_PER_MINUTE', 600)),
        'headquarters_ip_per_minute' => max(1, (int) env('HQ_IP_RATE_LIMIT_PER_MINUTE', 300)),
        'headquarters_key_per_minute' => max(1, (int) env('HQ_KEY_RATE_LIMIT_PER_MINUTE', 120)),
        'internal_per_minute' => max(1, (int) env('HQ_INTERNAL_RATE_LIMIT_PER_MINUTE', 600)),
    ],
    'headers' => [
        'frame_options' => 'DENY',
        'permissions_policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        'content_security_policy' => "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        'hsts_max_age' => max(0, (int) env('HSTS_MAX_AGE', 31536000)),
    ],
];
