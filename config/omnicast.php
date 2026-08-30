<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | OmniCast API Base URL
    |--------------------------------------------------------------------------
    |
    | The base URL of your OmniCast WebRTC media server REST API.
    |
    */
    'api_url' => env('OMNICAST_API_URL', 'https://omnilive.lolipoplive.top/api'),

    /*
    |--------------------------------------------------------------------------
    | OmniCast API Key
    |--------------------------------------------------------------------------
    |
    | Your OmniCast API key used for authenticating REST API requests.
    | This is sent as the X-API-Key header on every request.
    |
    */
    'api_key' => env('OMNICAST_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | OmniCast API Secret
    |--------------------------------------------------------------------------
    |
    | Your OmniCast API secret used alongside the API key for authentication.
    | This is sent as the X-API-Secret header on every request.
    |
    */
    'api_secret' => env('OMNICAST_API_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | OmniCast JWT Secret
    |--------------------------------------------------------------------------
    |
    | The secret key used to sign JWT tokens for room access (host/join tokens).
    | Keep this value strictly confidential.
    |
    */
    'jwt_secret' => env('OMNICAST_JWT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Request Timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | The number of seconds to wait for an API response before timing out.
    |
    */
    'timeout' => (int) env('OMNICAST_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | JWT Token TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | How long issued JWT tokens remain valid. Defaults to 24 hours.
    |
    */
    'jwt_ttl' => (int) env('OMNICAST_JWT_TTL', 86400),

];
