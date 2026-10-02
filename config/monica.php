<?php

// `php artisan vendor:publish --tag=monica-config` で config/monica.php に書き出せます。
// key はそのまま Monica\Client の option です（README の「オプション」）。
// `auto_capture` だけは常に false で渡します（例外は Laravel の handler から拾うため）。

return [
    'dsn' => env('MONICA_DSN'),
    'environment' => env('MONICA_ENVIRONMENT', env('APP_ENV', 'production')),
    'release' => env('MONICA_RELEASE'),
    'transport' => env('MONICA_TRANSPORT', 'shutdown'),
    'sample_rate' => (float) env('MONICA_SAMPLE_RATE', 1.0),
];
