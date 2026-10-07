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
        // Minimum mAP@50 (0–100) a freshly trained run must reach to be
        // auto-activated as the live model. 0 disables the gate.
        'min_map' => (float) env('ML_MIN_MAP50', 0),
        // Server-side only: the browser uses the same-origin Laravel proxy
        // (/ml/camera/*), so these internal addresses are never exposed.
        'stream_url' => env('ML_STREAM_URL', 'http://127.0.0.1:8001/camera/stream'),
        'raw_url' => env('ML_RAW_URL', 'http://127.0.0.1:8001/camera/raw'),
        'status_url' => env('ML_STATUS_URL', 'http://127.0.0.1:8001/camera/status'),
        // Annotated YOLO preview stream (bounding boxes; read-only, no actuation).
        'preview_url' => env('ML_PREVIEW_URL', 'http://127.0.0.1:8001/camera/preview'),
    ],

    // Legacy compatibility only: MQTT broker was the production real-time
    // command bus. The active architecture no longer uses MQTT for monitoring
    // (pull-based via /detections/latest + /stats/* endpoints). arm/command
    // publishing is opt-in via SORTING_MQTT_ENABLED when absolutely needed.
    'mqtt' => [
        'host' => env('MQTT_HOST', '127.0.0.1'),
        'port' => (int) env('MQTT_PORT', 1883),
        'username' => env('MQTT_USERNAME'),
        'password' => env('MQTT_PASSWORD'),
        'client_id_prefix' => env('MQTT_CLIENT_ID_PREFIX', 'sortvision'),
        'use_tls' => (bool) env('MQTT_USE_TLS', false),
        // Legacy compatibility only: the active architecture no longer uses
        // MQTT as the production real-time command bus. Monitoring is
        // pull-based via /detections/latest + /stats/* endpoints. arm/command
        // publishing is opt-in via SORTING_MQTT_ENABLED when absolutely needed.
        'legacy_only' => true,
    ],

    // Competition sorting integration (Opsi A). The ml-service publishes
    // arm/command; mock_hardware consumes it; mqtt:listen completes events.
    'sorting' => [
        'hardware_mode' => env('SORTING_HARDWARE_MODE', 'real'),
        'mock_hardware' => (bool) env('SORTING_MOCK_HARDWARE', false),
        'competition_mode' => (bool) env('COMPETITION_MODE', false),
        'max_objects_per_color' => (int) env('SORTING_MAX_OBJECTS_PER_COLOR', 3),
        'sort_min_confidence' => (float) env('SORTING_MIN_CONFIDENCE', 0.5),
        'sort_cooldown_ms' => (int) env('SORTING_COOLDOWN_MS', 1000),
    ],

];
