<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | OmniCast Media Server Base URL
    |--------------------------------------------------------------------------
    |
    | The base URL of your OmniCast Go media server REST API (e.g., http://127.0.0.1:8080).
    | Backwards-compatible with OMNICAST_API_URL if previously configured.
    |
    */
    'base_url' => env('OMNICAST_BASE_URL', env('OMNICAST_API_URL', 'http://localhost:8080')),

    /*
    |--------------------------------------------------------------------------
    | OmniCast API Key & Secret
    |--------------------------------------------------------------------------
    |
    | Credentials used for authenticating REST API and Admin requests.
    | Sent as X-API-KEY and X-API-SECRET headers.
    |
    */
    'api_key' => env('OMNICAST_API_KEY', 'dev_api_key_123'),
    'api_secret' => env('OMNICAST_API_SECRET', 'dev_api_secret_456'),

    /*
    |--------------------------------------------------------------------------
    | OmniCast JWT Secret
    |--------------------------------------------------------------------------
    |
    | Shared secret key used to sign and verify user access JWT tokens.
    |
    */
    'jwt_secret' => env('OMNICAST_JWT_SECRET', 'live_media_server_jwt_secret_key_2026'),

    /*
    |--------------------------------------------------------------------------
    | STUN / TURN Shared Secret & Config
    |--------------------------------------------------------------------------
    |
    | Shared HMAC-SHA1 secret for generating RFC 5766 REST API TURN credentials.
    |
    */
    'turn_secret' => env('OMNICAST_TURN_SECRET', 'my_super_secure_turn_secret_999'),
    'turn_realm' => env('OMNICAST_TURN_REALM', 'omnicast.live'),
    'turn_port' => (int) env('OMNICAST_TURN_PORT', 3478),

    /*
    |--------------------------------------------------------------------------
    | Webhook Verification Secret
    |--------------------------------------------------------------------------
    |
    | Secret key used to verify incoming HMAC-SHA256 signatures in X-Signature.
    |
    */
    'webhook_secret' => env('OMNICAST_WEBHOOK_SECRET', env('WEBHOOK_SECRET', '')),

    /*
    |--------------------------------------------------------------------------
    | HTTP Request Timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Maximum seconds to wait for an API response before timing out.
    |
    */
    'timeout' => (int) env('OMNICAST_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | JWT Token TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | Validity duration for issued JWT tokens. Defaults to 24 hours (86400s).
    |
    */
    'jwt_ttl' => (int) env('OMNICAST_JWT_TTL', 86400),

];
