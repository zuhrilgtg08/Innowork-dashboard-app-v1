<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Python FastAPI computer-vision service (Ultralytics YOLO).
    'ml' => [
        'url' => env('ML_SERVICE_URL', 'http://127.0.0.1:8001'),
        'secret' => env('ML_CALLBACK_SECRET', ''),
        // Browser-facing MJPEG stream of the ICAM-300 (served by ml-service).
        'stream_url' => env('ML_STREAM_URL', 'http://127.0.0.1:8001/camera/stream'),
        'status_url' => env('ML_STATUS_URL', 'http://127.0.0.1:8001/camera/status'),
    ],

    // MQTT broker (Mosquitto/EMQX) — the real-time command & telemetry bus for
    // the robotic arm (Opsi A). The broker itself is hosted separately; Laravel
    // is only a publisher (dashboard/API commands) and consumer (mqtt:listen).
    'mqtt' => [
        'host' => env('MQTT_HOST', '127.0.0.1'),
        'port' => (int) env('MQTT_PORT', 1883),
        'username' => env('MQTT_USERNAME'),
        'password' => env('MQTT_PASSWORD'),
        'client_id_prefix' => env('MQTT_CLIENT_ID_PREFIX', 'sortvision'),
        'use_tls' => (bool) env('MQTT_USE_TLS', false),
        // All arm topics live under this prefix, e.g. "arm/command".
        'base_topic' => env('MQTT_BASE_TOPIC', 'arm'),
        // Seconds to wait for a broker connection before degrading gracefully.
        'connect_timeout' => (int) env('MQTT_CONNECT_TIMEOUT', 3),
    ],

];
