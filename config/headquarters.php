<?php

return [
    'apishadai_key_id' => env('APISHADAI_KEY_ID', 'apishadai'),
    'apishadai_secret' => env('APISHADAI_SIGNING_SECRET'),
    'apishadai_ips' => array_filter(array_map('trim', explode(',', env('APISHADAI_ALLOWED_IPS', '127.0.0.1,::1')))),
    'apishadai_url' => env('APISHADAI_API_URL'),
    'registration_minutes' => 10,
    'max_code_attempts' => 5,
];
