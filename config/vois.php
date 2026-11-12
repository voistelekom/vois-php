<?php

return [
    // SMS API: user/password given by VOIS (not your panel login). https://docs.vois.com.tr/sms/
    'sms' => [
        'username' => env('VOIS_SMS_USERNAME'),
        'password' => env('VOIS_SMS_PASSWORD'),
        'base_uri' => env('VOIS_SMS_BASE_URI', 'https://api.vois.com.tr/'),
    ],

    // Voice API: key created in the VOIS panel › Ayarlar › Açık REST API & Webhook. https://docs.vois.com.tr/voice/
    'voice' => [
        'api_key'  => env('VOIS_API_KEY'),
        'base_uri' => env('VOIS_VOICE_BASE_URI', 'https://api.vois.com.tr/v1/voice/'),
    ],

    // Webhook secret shown when you add the webhook (or "göster" in the panel). Used by the `vois.webhook` middleware.
    'webhook' => [
        'secret'    => env('VOIS_WEBHOOK_SECRET'),
        'tolerance' => (int) env('VOIS_WEBHOOK_TOLERANCE', 300),
    ],
];
