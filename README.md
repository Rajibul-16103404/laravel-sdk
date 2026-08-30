# OmniCast Laravel SDK

[![Latest Version on Packagist](https://img.shields.io/packagist/v/omnicast/laravel-sdk.svg?style=flat-square)](https://packagist.org/packages/omnicast/laravel-sdk)
[![Total Downloads](https://img.shields.io/packagist/dt/omnicast/laravel-sdk.svg?style=flat-square)](https://packagist.org/packages/omnicast/laravel-sdk)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue.svg?style=flat-square)](https://php.net)
[![License](https://img.shields.io/packagist/l/omnicast/laravel-sdk.svg?style=flat-square)](LICENSE)

A production-ready, secure Laravel SDK for interacting with the **OmniCast WebRTC media server**.

---

## Requirements

- PHP `^8.1`
- Laravel `^10.0 | ^11.0 | ^12.0`

---

## Installation

```bash
composer require omnicast/laravel-sdk
```

The service provider and facade are auto-discovered by Laravel. No manual registration needed.

---

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=omnicast-config
```

Add these to your `.env` file:

```env
OMNICAST_API_URL=https://omnilive.lolipoplive.top/api
OMNICAST_API_KEY=your-api-key
OMNICAST_API_SECRET=your-api-secret
OMNICAST_JWT_SECRET=your-jwt-secret

# Optional
OMNICAST_TIMEOUT=30
OMNICAST_JWT_TTL=86400
```

---

## Usage

### Using the Facade

```php
use Omnicast\LaravelSdk\Facades\Omnicast;

// Generate a host JWT token
$token = Omnicast::generateHostToken('room-123', 'user-456', [
    'display_name' => 'Alice',
]);

// Generate a viewer / co-host join token
$viewerToken  = Omnicast::generateJoinToken('room-123', 'user-789');
$coHostToken  = Omnicast::generateJoinToken('room-123', 'user-321', 'co-host');

// Room list and count
$rooms     = Omnicast::getRooms();       // array
$roomCount = Omnicast::getRoomCount();   // int

// Participants
$participants = Omnicast::getParticipants('room-123'); // array
$count        = Omnicast::getParticipantCount('room-123'); // int
```

### Using Dependency Injection

```php
use Omnicast\LaravelSdk\OmnicastService;
use Omnicast\LaravelSdk\Exceptions\OmnicastException;

class StreamController extends Controller
{
    public function __construct(private readonly OmnicastService $omnicast) {}

    public function join(string $roomId, Request $request): JsonResponse
    {
        try {
            $token = $this->omnicast->generateJoinToken(
                roomId: $roomId,
                userId: (string) $request->user()->id,
                role: $request->input('role', 'viewer'),
            );

            return response()->json(['token' => $token]);
        } catch (OmnicastException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
```

---

## JWT Token Payload

Every issued token contains:

```json
{
  "api_key":  "your-api-key",
  "room_id":  "room-123",
  "user_id":  "user-456",
  "role":     "host | viewer | co-host",
  "metadata": {},
  "iat":      1234567890,
  "exp":      1234654290
}
```

- **Algorithm**: `HS256`
- **Default TTL**: 86400 seconds (24 hours) — configurable via `OMNICAST_JWT_TTL`

---

## Available Methods

| Method | Description |
|---|---|
| `generateHostToken(roomId, userId, metadata)` | JWT with `role = host` |
| `generateJoinToken(roomId, userId, role, metadata)` | JWT with `role = viewer\|co-host` |
| `getRooms()` | `GET /rooms` → list of rooms |
| `getRoomCount()` | Total active room count |
| `getParticipants(roomId)` | `GET /rooms/{roomId}/participants` |
| `getParticipantCount(roomId)` | Participant count for a room |

---

## Exception Handling

All exceptions extend `OmnicastException` and expose `getHttpStatusCode()` and `getResponseBody()`:

```php
try {
    $rooms = Omnicast::getRooms();
} catch (\Omnicast\LaravelSdk\Exceptions\OmnicastException $e) {
    // $e->getMessage()        — human-readable message
    // $e->getHttpStatusCode() — HTTP status (or null)
    // $e->getResponseBody()   — raw API response body (or null)
}
```

---

## Testing

```bash
composer install
./vendor/bin/phpunit --testdox
```

---

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
