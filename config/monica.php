<?php

// `php artisan vendor:publish --tag=monica-config` で config/monica.php に書き出せます。
// key はそのまま Monica\Client の option です（README の「オプション」）。
// 値が null か空文字の key は渡さず、Client の既定値に任せます。`auto_capture` は常に false です。

return [
    'dsn' => env('MONICA_DSN'),
    'environment' => env('MONICA_ENVIRONMENT') ?: env('APP_ENV') ?: 'production',
    'release' => env('MONICA_RELEASE'),
    'transport' => env('MONICA_TRANSPORT', 'shutdown'),
    'sample_rate' => env('MONICA_SAMPLE_RATE', 1.0),
];
