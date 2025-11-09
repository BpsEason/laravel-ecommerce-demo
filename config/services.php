<?php

return [

    // ... 其他服務 ...

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    // Mock Payment Gateway (雖然是內部服務，但為了配置的一致性，可以放在這裡或單獨的配置檔案)
    'mock_payment_gateway' => [
        'url' => env('MOCK_PAYMENT_GATEWAY_URL', 'http://host.docker.internal:8080'), // 注意 Docker 內部網路
        'api_key' => env('MOCK_PAYMENT_GATEWAY_API_KEY'),
    ],

];