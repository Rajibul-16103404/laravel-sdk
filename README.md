# OmniCast Media Server — Laravel SDK

[![Latest Version on Packagist](https://img.shields.io/packagist/v/omnicast/laravel-sdk.svg?style=flat-square)](https://packagist.org/packages/omnicast/laravel-sdk)
[![Total Downloads](https://img.shields.io/packagist/dt/omnicast/laravel-sdk.svg?style=flat-square)](https://packagist.org/packages/omnicast/laravel-sdk)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue.svg?style=flat-square)](https://php.net)
[![License](https://img.shields.io/packagist/l/omnicast/laravel-sdk.svg?style=flat-square)](LICENSE)

A production-ready, feature-complete Laravel SDK and service wrapper for the **OmniCast Go live streaming media server** (WebRTC SFU + HTTP REST + WebSocket signaling + Webhooks).

---

## Features

- **Authentication & Token Generation**: Primary `POST /api/auth/token` with ICE servers, LiveKit-compatible `POST /api/livekit/token`, and offline zero-latency JWT creation.
- **Room Management**: `createRoom`, `listRooms`, `getRoom`, `getRoomCount`, `getParticipants`, `getParticipantCount`.
- **STUN / TURN ICE Servers**: Fetch time-limited HMAC-SHA1 credentials or generate them locally (RFC 5766 TURN REST API).
- **Admin Endpoints**: `adminListRooms` with live uptime metrics, `forceEndRoom` / `closeRoom`.
- **Gift / In-App Engagement**: Inject virtual gifts into live rooms via `POST /api/gift` for real-time WebSocket broadcast.
- **Health Checks**: Inspect server health and draining state before routing users.
- **WebSocket Signaling Helpers**: URL generator (`ws://` / `wss://`), action & event constants, standard message envelope formatter.
- **Webhook Verification & Event Handling**: Secure HMAC-SHA256 signature verification and structured `WebhookEvent` DTO.
- **Developer-Friendly**: Full Laravel facade support with autocomplete `@method` annotations, strict typing, PHPStan Level 8 clean, and Pint styled.

---

## Requirements

- PHP `^8.1`
- Laravel `^10.0 | ^11.0 | ^12.0`

---

## Installation

```bash
composer require omnicast/laravel-sdk
```

The service provider and facade are auto-discovered by Laravel.

---

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=omnicast-config
```

Add the following environment variables to your `.env` file:

```env
OMNICAST_BASE_URL=http://127.0.0.1:8080
OMNICAST_API_KEY=dev_api_key_123
OMNICAST_API_SECRET=dev_api_secret_456
OMNICAST_JWT_SECRET=live_media_server_jwt_secret_key_2026

# STUN / TURN credentials (RFC 5766)
OMNICAST_TURN_SECRET=my_super_secure_turn_secret_999
OMNICAST_TURN_REALM=omnicast.live
OMNICAST_TURN_PORT=3478

# Webhook signature verification
OMNICAST_WEBHOOK_SECRET=your_webhook_secret_for_verification

# Optional settings
OMNICAST_TIMEOUT=30
OMNICAST_JWT_TTL=86400
```

---

## Quick Start & Usage

### 1. Generating User Token & ICE Servers (Primary Flow)

Before a host or viewer connects to the media server, request a signed JWT token and ICE server configuration:

```php
use Omnicast\LaravelSdk\Facades\Omnicast;
use Omnicast\LaravelSdk\Constants\Role;

$response = Omnicast::generateToken(
    userId: 'user_101',
    userName: 'Meharab Islam',
    avatarUrl: 'https://cdn.example.com/avatar.jpg',
    role: Role::VIEWER, // 'host', 'cohost', 'publisher', 'viewer', 'user'
    roomId: 'room_live_abc123',
    canPublish: false,
    canSubscribe: true,
);

// Returns:
// [
//   'status' => 'success',
//   'token' => 'eyJhbGciOi...',
//   'user_id' => 'user_101',
//   'expires_in' => 86400,
//   'ice_servers' => [ ... ]
// ]
```

### 2. Room Management

```php
// Create a new room (host calls this before WebSocket connection)
$room = Omnicast::createRoom(
    roomId: 'room_live_abc123',
    hostId: 'user_101',
    roomName: "Meharab's Live Show",
    roomType: 'live',
);

// List all currently active rooms
$rooms = Omnicast::listRooms();

// Get single room details
$room = Omnicast::getRoom('room_live_abc123');

// Calculate room uptime in seconds
$uptimeSeconds = Omnicast::getRoomUptime($room['created_at']);
```

### 3. STUN / TURN ICE Servers

```php
// Query ICE servers from media server
$iceServers = Omnicast::getIceServers('user_101');

// Or generate RFC 5766 TURN credentials locally (zero network overhead):
$turnCreds = Omnicast::generateTurnCredentials('user_101', ttl: 86400);
// Returns: ['uris' => [...], 'username' => '...', 'password' => '...', 'ttl' => 86400]
```

### 4. Admin Management

```php
// Admin overview with uptime_seconds and total active counts
$overview = Omnicast::adminListRooms();

// Forcefully terminate and close an active room
$result = Omnicast::forceEndRoom('room_live_abc123');
```

### 5. In-App Virtual Gifts

Inject gifts purchased via your in-app currency into the live stream:

```php
Omnicast::sendGift(
    roomId: 'room_live_abc123',
    senderId: 'user_202',
    senderName: 'Ahmed',
    giftId: 'gift_rose_001',
    giftName: 'Rose',
    receiverId: 'user_101', // target host
    coins: 50,
    points: 50,
    amount: 1,
);
```

### 6. Health & Server Status

```php
if (Omnicast::isDraining()) {
    // Server is draining connections for maintenance, avoid scheduling new streams
}

$health = Omnicast::healthCheck();
```

### 7. WebSocket Signaling Protocol

Generate connection URLs and format messaging envelopes:

```php
use Omnicast\LaravelSdk\Constants\WsAction;

// Generate WS / WSS connection URL
$wsUrl = Omnicast::getWebSocketUrl($token);
// => ws://127.0.0.1:8080/ws?token=eyJhbGciOi...

// Format client message envelope
$message = Omnicast::formatWebSocketMessage(
    action: WsAction::CHAT,
    roomId: 'room_live_abc123',
    userId: 'user_101',
    payload: ['message' => 'Hello everyone!'],
    extra: ['user_name' => 'Meharab Islam'],
);
```

### 8. Webhook Verification & Handling

Configure a webhook route in `routes/api.php`:

```php
use Illuminate\Http\Request;
use Omnicast\LaravelSdk\Facades\Omnicast;
use Omnicast\LaravelSdk\Constants\WebhookType;

Route::post('/omnicast/webhook', function (Request $request) {
    $payload = $request->getContent();
    $signature = (string) $request->header('X-Signature');

    // Validates HMAC-SHA256 signature and returns WebhookEvent DTO
    $event = Omnicast::handleWebhook($payload, $signature);

    match ($event->eventType) {
        WebhookType::ROOM_STARTED => logger("Room started: {$event->roomId}"),
        WebhookType::ROOM_ENDED   => logger("Room ended: {$event->roomId}"),
        WebhookType::PARTICIPANT_JOINED => logger("User {$event->userId} joined {$event->roomId}"),
        WebhookType::PARTICIPANT_LEFT   => logger("User {$event->userId} left {$event->roomId}"),
        WebhookType::GIFT_SENT    => logger("Gift sent in {$event->roomId}"),
        default => null,
    };

    return response()->json(['success' => true]);
});
```

---

## Available Methods Reference

| Method | Description |
|---|---|
| `generateToken(...)` | `POST /api/auth/token` — Main token generation with ICE credentials |
| `generateLivekitToken(...)` | `POST /api/livekit/token` — LiveKit-compatible token generation |
| `getDemoToken(...)` | `GET /auth/demo-token` — Quick development test token |
| `generateHostToken(...)` | Offline local host JWT generation |
| `generateJoinToken(...)` | Offline local viewer/co-host JWT generation |
| `generateLocalToken(...)` | Offline local token generation with custom permissions |
| `createRoom(...)` | `POST /api/rooms` — Create room before stream starts |
| `listRooms()` / `getRooms()` | `GET /api/rooms` — List active rooms |
| `getRoom(roomId)` | `GET /api/rooms/:id` — Single room details |
| `getRoomCount()` | Total active rooms count |
| `getIceServers(userId)` | `GET /api/ice-servers` — STUN / TURN server configurations |
| `getTurnCredentials(userId)` | `GET /api/turn_credentials` — TURN URIs and credentials |
| `generateTurnCredentials(...)`| Zero-latency RFC 5766 HMAC-SHA1 TURN credential generation |
| `adminListRooms()` | `GET /api/admin/rooms` — Admin overview with uptime_seconds |
| `forceEndRoom(roomId)` | `POST /api/admin/rooms/:id/end` — Terminate and close a live room |
| `sendGift(...)` | `POST /api/gift` — Broadcast virtual gifts to live rooms |
| `healthCheck()` | `GET /health` — Check media server status |
| `isHealthy()` | Returns `true` if server is active and not draining |
| `isDraining()` | Returns `true` if server is draining connections |
| `getWebSocketUrl(token)` | Generates `ws://` / `wss://` signaling connection URL |
| `formatWebSocketMessage(...)` | Prepares JSON message envelope for signaling |
| `verifyWebhookSignature(...)` | Verifies `X-Signature` HMAC-SHA256 hash |
| `handleWebhook(...)` | Validates signature and returns `WebhookEvent` DTO |
| `getRoomUptime(createdAt)` | Calculates uptime seconds from `created_at` ISO string |

---

## Exception Handling

All API errors, network issues, and validation failures throw `OmnicastException`:

```php
use Omnicast\LaravelSdk\Facades\Omnicast;
use Omnicast\LaravelSdk\Exceptions\OmnicastException;

try {
    Omnicast::createRoom('room_1', 'host_1');
} catch (OmnicastException $e) {
    if ($e->isConflict()) {
        // Room already exists (409)
    } elseif ($e->isDraining()) {
        // Server is draining connections (503)
    } elseif ($e->isNotFound()) {
        // Resource not found (404)
    } elseif ($e->isUnauthorized()) {
        // Invalid API Key / Secret (401)
    }

    $statusCode = $e->getHttpStatusCode();
    $rawBody    = $e->getResponseBody();
}
```

---

## Testing & Quality Assurance

```bash
# Run unit tests
./vendor/bin/phpunit

# Run static analysis (Level 8)
./vendor/bin/phpstan analyse

# Check and fix code style
./vendor/bin/pint
```

A Postman collection with all REST and Webhook requests is available in [`postman_collection.json`](postman_collection.json).

---

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
