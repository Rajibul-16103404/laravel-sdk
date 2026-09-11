# OmniCast Media Server — Laravel SDK

[![Latest Version](https://img.shields.io/badge/version-1.1.0-blue.svg?style=flat-square)](https://github.com/Rajibul-16103404/laravel-sdk/releases)
[![Software License](https://img.shields.io/badge/license-MIT-green.svg?style=flat-square)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-8892BF.svg?style=flat-square)](https://php.net)
[![Laravel Support](https://img.shields.io/badge/Laravel-10%20%7C%2011%20%7C%2012-FF2D20.svg?style=flat-square)](https://laravel.com)
[![Static Analysis](https://img.shields.io/badge/PHPStan-Level%208-brightgreen.svg?style=flat-square)](https://phpstan.org)

A production-ready, feature-complete Laravel SDK and service wrapper for the **OmniCast Go WebRTC Live Streaming Media Server**.

This package makes it effortless to manage WebRTC rooms, generate signed JWT user tokens with ICE credentials, inject real-time virtual gifts, monitor server health, verify incoming webhooks, and interact with the WebSocket signaling protocol.

---

## Table of Contents

- [Architecture Overview](#architecture-overview)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [End-to-End Implementation Guide](#end-to-end-implementation-guide)
  - [1. Stream Controller (Host, Viewer, Gifts, End Room)](#1-stream-controller-host-viewer-gifts-end-room)
  - [2. Webhook Event Listener (Receiving Server Events)](#2-webhook-event-listener-receiving-server-events)
  - [3. Frontend Client Integration (WebSockets + WebRTC)](#3-frontend-client-integration-websockets--webrtc)
- [Complete API Reference](#complete-api-reference)
  - [Authentication & Tokens](#authentication--tokens)
  - [Room Management](#room-management)
  - [STUN / TURN ICE Servers](#stun--turn-ice-servers)
  - [Admin Operations](#admin-operations)
  - [Gift / Engagement API](#gift--engagement-api)
  - [Health & Maintenance](#health--maintenance)
  - [WebSocket Signaling Helpers](#websocket-signaling-helpers)
  - [Webhooks & Signature Verification](#webhooks--signature-verification)
- [Exception & Error Handling](#exception--error-handling)
- [Postman Collection](#postman-collection)
- [Testing & Quality Assurance](#testing--quality-assurance)
- [License](#license)

---

## Architecture Overview

```
 ┌──────────────────────┐          REST (Auth / Token / Rooms)        ┌─────────────────────────┐
 │                      │ ──────────────────────────────────────────> │                         │
 │   Laravel Backend    │                                             │   OmniCast Media Server │
 │    (This SDK)        │ <────────────────────────────────────────── │        (Go SFU)         │
 │                      │           Webhook Events (HMAC-SHA256)      │                         │
 └──────────────────────┘                                             └─────────────────────────┘
            │                                                                      ▲
            │ Token, ICE Credentials & ws_url                                      │ WebRTC Media Tracks
            ▼                                                                      │ (Video / Audio) +
 ┌──────────────────────┐          WebSocket Signaling (ws://.../ws?token=)        │ SDP Offer/Answer
 │                      │ ─────────────────────────────────────────────────────────┘
 │   Frontend Client    │
 │ (Web, iOS, Android)  │
 └──────────────────────┘
```

---

## Features

- **Primary Token Generation**: Calls `POST /api/auth/token` on the media server and receives JWT + ready-to-use ICE servers (STUN/TURN).
- **LiveKit Compatible**: Supports LiveKit token endpoint (`POST /api/livekit/token`).
- **Zero-Latency Offline JWT Signing**: Fallback local token generator (`generateHostToken`, `generateJoinToken`, `generateLocalToken`) signed with HMAC-SHA256.
- **Room Lifecycle Management**: Create live rooms before streams start, list active rooms, fetch viewer statistics, and compute uptime.
- **STUN & TURN Support**: Fetch time-limited credentials or generate RFC 5766 HMAC-SHA1 TURN credentials offline without network overhead.
- **Admin Control**: View active rooms with duration and metrics, or forcefully end rooms to disconnect all participants.
- **Virtual Gift API**: Send gifts into rooms via REST; server broadcasts gift animations in real time across WebSockets.
- **Health & Draining Status**: Proactively detect graceful server draining (`503`) to redirect traffic during maintenance.
- **Signaling Helpers & Constants**: Action and event constants (`WsAction`, `WsEvent`, `Role`), WebSocket URL builder (`ws://` / `wss://`), and message envelope formatter.
- **HMAC-SHA256 Webhooks**: Secure incoming webhook signature verification with typed [`WebhookEvent`](src/DTOs/WebhookEvent.php) DTO.
- **Clean Architecture**: 100% test coverage (PHPUnit 11), PHPStan Level 8 clean, Laravel Pint styled, and full IDE Facade autocompletion.

---

## Requirements

- **PHP**: `^8.1`
- **Laravel Framework**: `^10.0`, `^11.0`, or `^12.0`

---

## Installation

Install the package via Composer:

```bash
composer require rajibul-16103404/omnicast-laravel-sdk
```

The package automatically registers its Service Provider (`OmnicastServiceProvider`) and Facade (`Omnicast`) via Laravel Package Discovery.

---

## Configuration

Publish the package configuration file to your project:

```bash
php artisan vendor:publish --tag=omnicast-config
```

This creates `config/omnicast.php`. Now add the corresponding environment variables to your `.env` file:

```env
# Base URL of your OmniCast Go Media Server
OMNICAST_BASE_URL=http://127.0.0.1:8080

# API Credentials for protected REST endpoints
OMNICAST_API_KEY=dev_api_key_123
OMNICAST_API_SECRET=dev_api_secret_456

# JWT secret shared with the Go server
OMNICAST_JWT_SECRET=live_media_server_jwt_secret_key_2026

# STUN / TURN credentials (RFC 5766)
OMNICAST_TURN_SECRET=my_super_secure_turn_secret_999
OMNICAST_TURN_REALM=omnicast.live
OMNICAST_TURN_PORT=3478

# Webhook verification secret
OMNICAST_WEBHOOK_SECRET=your_webhook_secret_here

# Request timeout and token TTL
OMNICAST_TIMEOUT=30
OMNICAST_JWT_TTL=86400
```

---

## End-to-End Implementation Guide

Here is a complete, real-world example showing how to build a live streaming API in Laravel.

### 1. Stream Controller (Host, Viewer, Gifts, End Room)

Create `app/Http/Controllers/StreamController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Omnicast\LaravelSdk\Facades\Omnicast;
use Omnicast\LaravelSdk\Constants\Role;
use Omnicast\LaravelSdk\Exceptions\OmnicastException;

class StreamController extends Controller
{
    /**
     * 1. Host starts a live stream
     * POST /api/stream/start
     */
    public function startLive(Request $request): JsonResponse
    {
        $user = $request->user();
        $roomId = 'room_' . uniqid();

        try {
            // Step 1: Create room on media server
            $room = Omnicast::createRoom(
                roomId: $roomId,
                hostId: (string) $user->id,
                roomName: $request->input('title', "{$user->name}'s Live"),
                roomType: 'live'
            );

            // Step 2: Generate token with host permissions and ICE servers
            $authData = Omnicast::generateToken(
                userId: (string) $user->id,
                userName: $user->name,
                avatarUrl: $user->avatar_url ?? '',
                role: Role::HOST,
                roomId: $roomId,
                canPublish: true,
                canSubscribe: true
            );

            // Step 3: Generate WebSocket signaling URL
            $wsUrl = Omnicast::getWebSocketUrl($authData['token']);

            return response()->json([
                'success'     => true,
                'room_id'     => $roomId,
                'token'       => $authData['token'],
                'ice_servers' => $authData['ice_servers'],
                'ws_url'      => $wsUrl,
            ]);
        } catch (OmnicastException $e) {
            if ($e->isDraining()) {
                return response()->json(['error' => 'Server is currently undergoing maintenance.'], 503);
            }
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * 2. Viewer joins a live stream
     * POST /api/stream/join
     */
    public function joinLive(Request $request): JsonResponse
    {
        $user = $request->user();
        $roomId = $request->input('room_id');

        try {
            // Generate viewer token (canPublish: false)
            $authData = Omnicast::generateToken(
                userId: (string) $user->id,
                userName: $user->name,
                avatarUrl: $user->avatar_url ?? '',
                role: Role::VIEWER,
                roomId: $roomId,
                canPublish: false,
                canSubscribe: true
            );

            $wsUrl = Omnicast::getWebSocketUrl($authData['token']);

            return response()->json([
                'success'     => true,
                'token'       => $authData['token'],
                'ice_servers' => $authData['ice_servers'],
                'ws_url'      => $wsUrl,
            ]);
        } catch (OmnicastException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * 3. Send a virtual gift to the host
     * POST /api/stream/gift
     */
    public function sendGift(Request $request): JsonResponse
    {
        $sender = $request->user();
        $roomId = $request->input('room_id');
        $receiverId = $request->input('host_id');
        $coins = (int) $request->input('coins', 50);

        // Deduct coins from user balance in your database here...

        // Broadcast gift into the live room in real time
        $res = Omnicast::sendGift(
            roomId: $roomId,
            senderId: (string) $sender->id,
            senderName: $sender->name,
            giftId: $request->input('gift_id', 'rose_01'),
            giftName: $request->input('gift_name', 'Rose'),
            receiverId: $receiverId,
            coins: $coins,
            points: $coins,
            amount: 1
        );

        return response()->json($res);
    }

    /**
     * 4. End room (Admin or Host)
     * POST /api/stream/end
     */
    public function endLive(Request $request): JsonResponse
    {
        $roomId = $request->input('room_id');

        // Forcefully terminates room and disconnects all participants
        $res = Omnicast::forceEndRoom($roomId);

        return response()->json($res);
    }
}
```

---

### 2. Webhook Event Listener (Receiving Server Events)

When participants join/leave or rooms start/end, OmniCast POSTs event webhooks to your Laravel app with an HMAC-SHA256 signature.

Register the route in `routes/api.php`:

```php
use App\Http\Controllers\OmnicastWebhookController;

Route::post('/omnicast/webhook', [OmnicastWebhookController::class, 'handle']);
```

Create `app/Http/Controllers/OmnicastWebhookController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Omnicast\LaravelSdk\Facades\Omnicast;
use Omnicast\LaravelSdk\Constants\WebhookType;
use Omnicast\LaravelSdk\Exceptions\OmnicastException;

class OmnicastWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload   = $request->getContent();
        $signature = (string) $request->header('X-Signature');

        try {
            // Verifies X-Signature header and decodes into WebhookEvent DTO
            $event = Omnicast::handleWebhook($payload, $signature);

            match ($event->eventType) {
                WebhookType::ROOM_STARTED => $this->onRoomStarted($event->roomId, $event->userId),
                WebhookType::ROOM_ENDED   => $this->onRoomEnded($event->roomId),
                WebhookType::PARTICIPANT_JOINED => $this->onUserJoined($event->roomId, $event->userId),
                WebhookType::PARTICIPANT_LEFT   => $this->onUserLeft($event->roomId, $event->userId),
                WebhookType::GIFT_SENT    => $this->onGiftSent($event->data),
                default => null,
            };

            return response()->json(['status' => 'acknowledged']);
        } catch (OmnicastException $e) {
            // Returns 403 if signature is invalid or tampered
            return response()->json(['error' => $e->getMessage()], 403);
        }
    }

    private function onRoomStarted(string $roomId, string $hostId): void
    {
        // Update database: Stream status = 'live'
    }

    private function onRoomEnded(string $roomId): void
    {
        // Update database: Stream status = 'ended'
    }

    private function onUserJoined(string $roomId, string $userId): void
    {
        // Increment viewer counter in cache/database
    }

    private function onUserLeft(string $roomId, string $userId): void
    {
        // Decrement viewer counter
    }

    private function onGiftSent(array $data): void
    {
        // Log transaction history
    }
}
```

---

### 3. Frontend Client Integration (WebSockets + WebRTC)

Frontend connects to the media server using the data returned by your Laravel API:

```javascript
// 1. Fetch token and ws_url from Laravel backend
const res = await fetch('/api/stream/start', { method: 'POST' });
const { room_id, token, ice_servers, ws_url } = await res.json();

// 2. Open WebSocket connection
const ws = new WebSocket(ws_url);

ws.onopen = async () => {
    console.log('Connected to OmniCast signaling server!');

    // 3. Create WebRTC PeerConnection with ICE servers
    const pc = new RTCPeerConnection({ iceServers: ice_servers });

    // Add local mic/cam stream
    const localStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
    localStream.getTracks().forEach(track => pc.addTrack(track, localStream));

    // Create SDP Offer
    const offer = await pc.createOffer();
    await pc.setLocalDescription(offer);

    // Send create_room action with SDP offer to OmniCast
    ws.send(JSON.stringify({
        action: 'create_room',
        room_id: room_id,
        user_id: 'user_101',
        payload: {
            sdp: offer.sdp,
            type: 'offer'
        }
    }));
};

ws.onmessage = async (event) => {
    const msg = JSON.parse(event.data);

    // Handle SDP Answer from media server
    if (msg.event === 'answer') {
        await pc.setRemoteDescription(new RTCSessionDescription({
            type: 'answer',
            sdp: msg.payload.sdp
        }));
    }

    // Real-time gift animation broadcast
    if (msg.event === 'gift') {
        console.log(`Received gift ${msg.data.gift} from ${msg.data.sender_name}!`);
    }
};
```

---

## Complete API Reference

You can access all methods via the `Omnicast` facade or by injecting `OmnicastService`.

### Authentication & Tokens

```php
use Omnicast\LaravelSdk\Facades\Omnicast;
use Omnicast\LaravelSdk\Constants\Role;

// 1. Primary endpoint: POST /api/auth/token
$data = Omnicast::generateToken(
    userId: 'user_101',
    userName: 'Meharab Islam',
    avatarUrl: 'https://cdn.example.com/avatar.jpg',
    role: Role::VIEWER, // 'host', 'cohost', 'publisher', 'viewer', 'user'
    roomId: 'room_123',
    canPublish: false,
    canSubscribe: true
);

// 2. LiveKit compatible token: POST /api/livekit/token
$data = Omnicast::generateLivekitToken('user_101', 'Meharab', role: Role::HOST);

// 3. Development demo token: GET /auth/demo-token
$data = Omnicast::getDemoToken('test_user', Role::HOST, 'room_123');

// 4. Offline local JWT token generation (zero network latency)
$hostToken = Omnicast::generateHostToken('room_123', 'host_101', ['vip' => true]);
$joinToken = Omnicast::generateJoinToken('room_123', 'viewer_202', Role::VIEWER);
```

### Room Management

```php
// Create a live room
$room = Omnicast::createRoom(
    roomId: 'room_live_abc123',
    hostId: 'user_101',
    roomName: "Meharab's Live Show",
    roomType: 'live' // 'live', 'pk', 'event'
);

// List all active rooms
$rooms = Omnicast::listRooms(); // or Omnicast::getRooms();

// Get single room details
$room = Omnicast::getRoom('room_live_abc123');

// Get total count of active rooms
$count = Omnicast::getRoomCount();

// Get viewers / participant list
$participants = Omnicast::getParticipants('room_live_abc123');

// Get participant count
$viewerCount = Omnicast::getParticipantCount('room_live_abc123');

// Calculate uptime in seconds from created_at
$seconds = Omnicast::getRoomUptime($room['created_at']);
```

### STUN / TURN ICE Servers

```php
// Fetch ICE server config from media server
$ice = Omnicast::getIceServers(userId: 'user_101');

// Fetch TURN server credentials from media server
$turn = Omnicast::getTurnCredentials(userId: 'user_101');

// Generate RFC 5766 TURN REST API credentials locally (zero network overhead)
$creds = Omnicast::generateTurnCredentials(userId: 'user_101', ttl: 86400);
// Returns: ['uris' => [...], 'username' => '...', 'password' => '...', 'ttl' => 86400]
```

### Admin Operations

Admin endpoints authenticate using `X-API-Key`.

```php
// Overview of all active rooms with uptime_seconds and statistics
$overview = Omnicast::adminListRooms();

// Forcefully terminate and destroy an active room
$result = Omnicast::forceEndRoom('room_live_abc123'); // or Omnicast::closeRoom(...)
```

### Gift / Engagement API

```php
Omnicast::sendGift(
    roomId: 'room_live_abc123',
    senderId: 'user_202',
    senderName: 'Ahmed',
    giftId: 'gift_rose_001',
    giftName: 'Rose',
    receiverId: 'user_101', // target host ID
    coins: 50,
    points: 50,
    amount: 1
);
```

### Health & Maintenance

```php
// Raw health response: GET /health
$health = Omnicast::healthCheck();

// Check if server is operational
if (Omnicast::isHealthy()) {
    // Normal operation
}

// Check if server is draining connections for maintenance/restart
if (Omnicast::isDraining()) {
    // Don't schedule new streams on this node
}
```

### WebSocket Signaling Helpers

```php
use Omnicast\LaravelSdk\Constants\WsAction;
use Omnicast\LaravelSdk\Constants\WsEvent;

// Get WebSocket connection URL with token
$wsUrl = Omnicast::getWebSocketUrl($token);
// => ws://127.0.0.1:8080/ws?token=eyJhbGci...

// Build standard message envelope
$message = Omnicast::formatWebSocketMessage(
    action: WsAction::CHAT,
    roomId: 'room_123',
    userId: 'user_101',
    payload: ['message' => 'Hello World!'],
    extra: ['user_name' => 'Meharab']
);
```

### Webhooks & Signature Verification

```php
// Verify signature manually:
$isValid = Omnicast::verifyWebhookSignature($rawPayload, $signature);

// Verify signature and return typed WebhookEvent DTO:
$event = Omnicast::handleWebhook($rawPayload, $signature);

$type = $event->eventType; // e.g. RoomStarted, RoomEnded, ParticipantJoined, GiftSent
$room = $event->roomId;
$user = $event->userId;
$val  = $event->get('host_id');
```

---

## Exception & Error Handling

All REST errors and connection failures throw `OmnicastException`:

```php
use Omnicast\LaravelSdk\Facades\Omnicast;
use Omnicast\LaravelSdk\Exceptions\OmnicastException;

try {
    Omnicast::createRoom('room_1', 'user_101');
} catch (OmnicastException $e) {
    if ($e->isConflict()) {
        // Room already exists (409)
    } elseif ($e->isDraining()) {
        // Server is in maintenance/draining mode (503)
    } elseif ($e->isNotFound()) {
        // Room or endpoint not found (404)
    } elseif ($e->isUnauthorized()) {
        // Invalid API Key or Secret (401)
    }

    $statusCode = $e->getHttpStatusCode();
    $rawBody    = $e->getResponseBody();
}
```

---

## Postman Collection

A complete Postman v2.1 collection is included in this repository at [`postman_collection.json`](postman_collection.json).

It includes pre-configured requests with variables for:
- Token Generation (`POST /api/auth/token`, `POST /api/livekit/token`, `GET /auth/demo-token`)
- Room Management (`POST /api/rooms`, `GET /api/rooms`, `GET /api/rooms/:id`)
- STUN / TURN ICE Servers (`GET /api/ice-servers`, `GET /api/turn_credentials`)
- Admin Endpoints (`GET /api/admin/rooms`, `POST /api/admin/rooms/:id/end`)
- Gift Broadcast (`POST /api/gift`)
- Server Health Check (`GET /health`)
- Webhook simulation

Import `postman_collection.json` directly into Postman to begin testing.

---

## Testing & Quality Assurance

Run the automated test suite and static analysis tools:

```bash
# Run unit tests (31 tests, 93 assertions)
./vendor/bin/phpunit

# Run PHPStan static analysis (Level 8)
./vendor/bin/phpstan analyse

# Check code formatting with Laravel Pint
./vendor/bin/pint --test
```

---

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.
