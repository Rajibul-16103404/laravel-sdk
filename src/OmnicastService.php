<?php

declare(strict_types=1);

namespace Omnicast\LaravelSdk;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Omnicast\LaravelSdk\Constants\Role;
use Omnicast\LaravelSdk\DTOs\WebhookEvent;
use Omnicast\LaravelSdk\Exceptions\OmnicastException;
use Throwable;

/**
 * OmnicastService
 *
 * Core service for the OmniCast Laravel SDK.
 *
 * Provides WebRTC token generation, Room management, STUN/TURN ICE server credentials,
 * Admin operations, Gift/Engagement API, Webhook verification, and WebSocket signaling helpers.
 */
class OmnicastService
{
    /**
     * Validated configuration snapshot injected at construction time.
     *
     * @var array{
     *     base_url:       string,
     *     api_key:        string,
     *     api_secret:     string,
     *     jwt_secret:     string,
     *     turn_secret:    string,
     *     turn_realm:     string,
     *     turn_port:      int,
     *     webhook_secret: string,
     *     timeout:        int,
     *     jwt_ttl:        int,
     * }
     */
    private readonly array $config;

    /**
     * @param  array<string, mixed>  $config  Raw package configuration (from config('omnicast')).
     *
     * @throws OmnicastException When a required configuration key is missing or empty.
     */
    public function __construct(array $config)
    {
        $this->config = $this->validateConfig($config);
    }

    // =========================================================================
    // 1. Authentication & Token Generation (REST API)
    // =========================================================================

    /**
     * Generate a signed JWT token and ICE servers credentials via OmniCast Media Server API.
     *
     * Endpoint: POST /api/auth/token
     *
     * @param  string  $userId  Unique user identifier.
     * @param  string  $userName  Display name of the user.
     * @param  string  $avatarUrl  URL to the user profile avatar.
     * @param  string  $role  Role: host, cohost, publisher, viewer, user.
     * @param  string  $roomId  Pre-bind token to a specific room ID.
     * @param  bool|null  $canPublish  Explicit publish override (optional).
     * @param  bool|null  $canSubscribe  Explicit subscribe override (optional).
     * @return array<string, mixed> Response containing {status, token, user_id, expires_in, ice_servers, ...}
     *
     * @throws OmnicastException
     */
    public function generateToken(
        string $userId,
        string $userName = '',
        string $avatarUrl = '',
        string $role = Role::VIEWER,
        string $roomId = '',
        ?bool $canPublish = null,
        ?bool $canSubscribe = null,
    ): array {
        $endpoint = '/api/auth/token';

        $payload = [
            'api_key' => $this->config['api_key'],
            'api_secret' => $this->config['api_secret'],
            'user_id' => $userId,
            'user_name' => $userName,
            'avatar_url' => $avatarUrl,
            'role' => $role,
            'room_id' => $roomId,
        ];

        if ($canPublish !== null) {
            $payload['can_publish'] = $canPublish;
        }
        if ($canSubscribe !== null) {
            $payload['can_subscribe'] = $canSubscribe;
        }

        return $this->sendPostRequest($endpoint, $payload);
    }

    /**
     * Generate LiveKit-compatible token via OmniCast Media Server API.
     *
     * Endpoint: POST /api/livekit/token (alias: POST /api/token)
     *
     *
     * @return array<string, mixed>
     *
     * @throws OmnicastException
     */
    public function generateLivekitToken(
        string $userId,
        string $userName = '',
        string $avatarUrl = '',
        string $role = Role::VIEWER,
        string $roomId = '',
        ?bool $canPublish = null,
        ?bool $canSubscribe = null,
    ): array {
        $endpoint = '/api/livekit/token';

        $payload = [
            'api_key' => $this->config['api_key'],
            'api_secret' => $this->config['api_secret'],
            'user_id' => $userId,
            'user_name' => $userName,
            'avatar_url' => $avatarUrl,
            'role' => $role,
            'room_id' => $roomId,
        ];

        if ($canPublish !== null) {
            $payload['can_publish'] = $canPublish;
        }
        if ($canSubscribe !== null) {
            $payload['can_subscribe'] = $canSubscribe;
        }

        return $this->sendPostRequest($endpoint, $payload);
    }

    /**
     * Quickly generate a demo test token (Development Only).
     *
     * Endpoint: GET /auth/demo-token
     *
     *
     * @return array<string, mixed>
     *
     * @throws OmnicastException
     */
    public function getDemoToken(
        string $userId = '',
        string $role = Role::VIEWER,
        string $roomId = '',
    ): array {
        $endpoint = '/auth/demo-token';
        $params = [
            'role' => $role,
        ];

        if ($userId !== '') {
            $params['user_id'] = $userId;
        }
        if ($roomId !== '') {
            $params['room_id'] = $roomId;
        }

        return $this->sendGetRequest($endpoint, $params, includeAuthHeaders: false);
    }

    // =========================================================================
    // 2. Local JWT Token Generation (Offline / Zero-Latency)
    // =========================================================================

    /**
     * Generate a local signed JWT token granting host-level access to a room.
     *
     * @param  string  $roomId  The target room ID.
     * @param  string  $userId  The host's user ID.
     * @param  array<string, mixed>  $metadata  Optional arbitrary metadata embedded in the token.
     * @return string Signed JWT string.
     *
     * @throws OmnicastException On JWT signing failure.
     */
    public function generateHostToken(
        string $roomId,
        string $userId,
        array $metadata = [],
    ): string {
        return $this->generateLocalToken(
            userId: $userId,
            userName: $userId,
            role: Role::HOST,
            roomId: $roomId,
            canPublish: true,
            canSubscribe: true,
            metadata: $metadata,
        );
    }

    /**
     * Generate a local signed JWT token granting viewer or co-host access to a room.
     *
     * @param  string  $roomId  The target room ID.
     * @param  string  $userId  The participant's user ID.
     * @param  string  $role  Role ('viewer', 'cohost', 'co-host', etc.).
     * @param  array<string, mixed>  $metadata  Optional arbitrary metadata embedded in the token.
     * @return string Signed JWT string.
     *
     * @throws OmnicastException On JWT signing failure or invalid role.
     */
    public function generateJoinToken(
        string $roomId,
        string $userId,
        string $role = Role::VIEWER,
        array $metadata = [],
    ): string {
        $normalizedRole = $role === 'co-host' ? Role::COHOST : $role;
        $allowedRoles = [Role::VIEWER, Role::COHOST, Role::USER, Role::PUBLISHER];

        if (! in_array($normalizedRole, $allowedRoles, strict: true)) {
            throw OmnicastException::jwtError(
                "Invalid role [{$role}] for generateJoinToken(). Allowed: "
                .implode(', ', $allowedRoles),
            );
        }

        $canPublish = in_array($normalizedRole, [Role::COHOST, Role::PUBLISHER], true);

        return $this->generateLocalToken(
            userId: $userId,
            userName: $userId,
            role: $normalizedRole,
            roomId: $roomId,
            canPublish: $canPublish,
            canSubscribe: true,
            metadata: $metadata,
        );
    }

    /**
     * Generate a signed JWT token matching OmniCast's exact payload specification.
     *
     * @param  array<string, mixed>  $metadata
     * @return string Signed JWT token string.
     *
     * @throws OmnicastException
     */
    public function generateLocalToken(
        string $userId,
        string $userName = '',
        string $avatarUrl = '',
        string $role = Role::VIEWER,
        string $roomId = '',
        ?bool $canPublish = null,
        ?bool $canSubscribe = null,
        array $metadata = [],
    ): string {
        $now = time();

        // Default permissions based on role if not explicitly provided
        $isPublisher = in_array($role, [Role::HOST, Role::COHOST, Role::PUBLISHER, 'co-host'], true);
        $publishPermission = $canPublish ?? $isPublisher;
        $subscribePermission = $canSubscribe ?? true;

        $payload = [
            'api_key' => $this->config['api_key'],
            'user_id' => $userId,
            'user_name' => $userName !== '' ? $userName : $userId,
            'avatar_url' => $avatarUrl,
            'role' => $role,
            'room_id' => $roomId,
            'can_publish' => $publishPermission,
            'can_subscribe' => $subscribePermission,
            'iss' => 'omnicast',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $this->config['jwt_ttl'],
        ];

        if (! empty($metadata)) {
            $payload['metadata'] = $metadata;
        }

        try {
            return JWT::encode($payload, $this->config['jwt_secret'], 'HS256');
        } catch (Throwable $e) {
            throw OmnicastException::jwtError($e->getMessage(), previous: $e);
        }
    }

    // =========================================================================
    // 3. Room Management (REST API)
    // =========================================================================

    /**
     * Create a new live room on the media server.
     *
     * Endpoint: POST /api/rooms
     *
     * @param  string  $roomId  Unique room identifier.
     * @param  string|null  $hostId  Host user ID (optional, defaults to host-<room_id>).
     * @param  string  $roomName  Human-readable room name.
     * @param  string  $roomType  Room type label (e.g. 'live', 'pk', 'event').
     * @return array<string, mixed> Response shape: { success: true, room: { ... } }
     *
     * @throws OmnicastException
     */
    public function createRoom(
        string $roomId,
        ?string $hostId = null,
        string $roomName = '',
        string $roomType = 'live',
    ): array {
        $endpoint = '/api/rooms';

        $payload = [
            'room_id' => $roomId,
            'room_name' => $roomName !== '' ? $roomName : $roomId,
            'room_type' => $roomType,
        ];

        if ($hostId !== null && $hostId !== '') {
            $payload['host_id'] = $hostId;
        }

        return $this->sendPostRequest($endpoint, $payload);
    }

    /**
     * List all currently active live rooms on the server.
     *
     * Endpoint: GET /api/rooms
     *
     * @return array<int, array<string, mixed>> List of room objects.
     *
     * @throws OmnicastException
     */
    public function listRooms(): array
    {
        $endpoint = '/api/rooms';
        $response = $this->sendGetRequest($endpoint);

        return is_array($response) ? $response : [];
    }

    /**
     * Alias for {@see listRooms()}.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws OmnicastException
     */
    public function getRooms(): array
    {
        return $this->listRooms();
    }

    /**
     * Get detailed information about a single active room.
     *
     * Endpoint: GET /api/rooms/:id
     *
     * @param  string  $roomId  Unique room identifier.
     * @return array<string, mixed> Room object details.
     *
     * @throws OmnicastException
     */
    public function getRoom(string $roomId): array
    {
        $endpoint = "/api/rooms/{$roomId}";

        return $this->sendGetRequest($endpoint);
    }

    /**
     * Return the total count of active rooms.
     *
     *
     * @throws OmnicastException
     */
    public function getRoomCount(): int
    {
        return count($this->listRooms());
    }

    /**
     * Retrieve participants in a given room.
     *
     * First attempts GET /rooms/:id/participants. If not supported, extracts viewers from GET /api/rooms/:id.
     *
     *
     * @return array<int, array<string, mixed>> List of participant objects.
     *
     * @throws OmnicastException
     */
    public function getParticipants(string $roomId): array
    {
        $endpoint = "/rooms/{$roomId}/participants";

        try {
            $result = $this->sendGetRequest($endpoint);
            if (is_array($result) && array_is_list($result)) {
                return $result;
            }
        } catch (OmnicastException $e) {
            // If the sub-endpoint does not exist, fall back to room details
            if ($e->getHttpStatusCode() === 404) {
                $room = $this->getRoom($roomId);
                if (isset($room['viewers_list']) && is_array($room['viewers_list'])) {
                    /** @var array<int, array<string, mixed>> */
                    return $room['viewers_list'];
                }

                return [];
            }

            throw $e;
        }

        return [];
    }

    /**
     * Return the participant count in a given room.
     *
     *
     *
     * @throws OmnicastException
     */
    public function getParticipantCount(string $roomId): int
    {
        try {
            $room = $this->getRoom($roomId);
            if (isset($room['viewers_count'])) {
                return (int) $room['viewers_count'];
            }
            if (isset($room['viewer_count'])) {
                return (int) $room['viewer_count'];
            }
        } catch (OmnicastException) {
            // fallback
        }

        return count($this->getParticipants($roomId));
    }

    // =========================================================================
    // 4. STUN / TURN ICE Servers
    // =========================================================================

    /**
     * Fetch WebRTC ICE servers configuration with time-limited HMAC-SHA1 credentials.
     *
     * Endpoint: GET /api/ice-servers
     *
     * @param  string  $userId  User identifier used to generate per-user TURN credentials.
     * @return array<string, mixed> Response containing { iceServers: [...] }
     *
     * @throws OmnicastException
     */
    public function getIceServers(string $userId = ''): array
    {
        $endpoint = '/api/ice-servers';
        $params = $userId !== '' ? ['user_id' => $userId] : [];

        return $this->sendGetRequest($endpoint, $params, includeAuthHeaders: false);
    }

    /**
     * Fetch TURN server URIs and credentials.
     *
     * Endpoint: GET /api/turn_credentials
     *
     *
     * @return array<string, mixed> Response: { uris: [...], username: "...", password: "...", ttl: 86400 }
     *
     * @throws OmnicastException
     */
    public function getTurnCredentials(string $userId = ''): array
    {
        $endpoint = '/api/turn_credentials';
        $params = $userId !== '' ? ['user_id' => $userId] : [];

        return $this->sendGetRequest($endpoint, $params, includeAuthHeaders: false);
    }

    /**
     * Generate RFC 5766 TURN REST API credentials locally without making a network request.
     *
     * Username format: <expiry_unix_timestamp>:<user_id>
     * Password: Base64(HMAC-SHA1(TURN_SECRET, username))
     *
     * @param  int  $ttl  Time to live in seconds (default 86400).
     * @param  string|null  $turnSecret  Override configured turn_secret.
     * @param  array<int, string>  $uris  Optional custom URIs.
     * @return array{
     *     uris:     array<int, string>,
     *     username: string,
     *     password: string,
     *     ttl:      int,
     * }
     */
    public function generateTurnCredentials(
        string $userId,
        int $ttl = 86400,
        ?string $turnSecret = null,
        array $uris = [],
    ): array {
        $secret = $turnSecret ?? $this->config['turn_secret'];
        $expiry = time() + $ttl;
        $username = "{$expiry}:{$userId}";
        $password = base64_encode(hash_hmac('sha1', $username, $secret, binary: true));

        if (empty($uris)) {
            $realm = $this->config['turn_realm'];
            $port = $this->config['turn_port'];
            $uris = [
                "turn:{$realm}:{$port}?transport=udp",
                "turn:{$realm}:{$port}?transport=tcp",
            ];
        }

        return [
            'uris' => $uris,
            'username' => $username,
            'password' => $password,
            'ttl' => $ttl,
        ];
    }

    // =========================================================================
    // 5. Admin Endpoints
    // =========================================================================

    /**
     * Get admin overview of all live rooms including uptime_seconds and metrics.
     *
     * Endpoint: GET /api/admin/rooms
     *
     * @return array<string, mixed> Shape: { status, total_active_rooms, timestamp, rooms: [...] }
     *
     * @throws OmnicastException
     */
    public function adminListRooms(): array
    {
        $endpoint = '/api/admin/rooms';

        return $this->sendAdminRequest('GET', $endpoint);
    }

    /**
     * Forcefully terminate and destroy an active live room (Admin operation).
     * All connected participants will be disconnected.
     *
     * Endpoint: POST /api/admin/rooms/:id/end
     *
     *
     * @return array<string, mixed> Shape: { status: "success", message: "...", room_id: "...", timestamp: ... }
     *
     * @throws OmnicastException
     */
    public function forceEndRoom(string $roomId): array
    {
        $endpoint = "/api/admin/rooms/{$roomId}/end";

        return $this->sendAdminRequest('POST', $endpoint);
    }

    /**
     * Alias for {@see forceEndRoom()}.
     *
     *
     * @return array<string, mixed>
     *
     * @throws OmnicastException
     */
    public function closeRoom(string $roomId): array
    {
        return $this->forceEndRoom($roomId);
    }

    // =========================================================================
    // 6. Gift / Engagement API
    // =========================================================================

    /**
     * Send a virtual gift event into a live room via REST (no WebSocket required).
     * Broadcasts to all participants in real time.
     *
     * Endpoint: POST /api/gift
     *
     * @param  string  $roomId  Target room identifier.
     * @param  string  $senderId  User sending the gift.
     * @param  string  $senderName  Display name of the sender.
     * @param  string  $giftId  Gift SKU identifier.
     * @param  string  $giftName  Display name of the gift.
     * @param  string  $receiverId  Target recipient / host ID.
     * @param  int  $coins  Coin value of the gift.
     * @param  int  $points  Point value.
     * @param  int  $amount  Quantity of gifts sent.
     * @return array<string, mixed> Shape: { success: true, message: "Gift processed successfully" }
     *
     * @throws OmnicastException
     */
    public function sendGift(
        string $roomId,
        string $senderId = '',
        string $senderName = '',
        string $giftId = '',
        string $giftName = '',
        string $receiverId = '',
        int $coins = 0,
        int $points = 0,
        int $amount = 1,
    ): array {
        $endpoint = '/api/gift';

        $payload = [
            'room_id' => $roomId,
            'sender_id' => $senderId,
            'sender_name' => $senderName,
            'gift_id' => $giftId,
            'gift' => $giftName,
            'target_host_id' => $receiverId,
            'receiver_id' => $receiverId,
            'coins' => $coins,
            'points' => $points,
            'amount' => $amount,
        ];

        return $this->sendPostRequest($endpoint, $payload);
    }

    // =========================================================================
    // 7. Health Check
    // =========================================================================

    /**
     * Check media server health status.
     *
     * Endpoint: GET /health
     *
     * @return array<string, mixed> Status report: { status: "ok", active_rooms: 5, draining: false, system: {...} }
     *
     * @throws OmnicastException
     */
    public function healthCheck(): array
    {
        $endpoint = '/health';

        return $this->sendGetRequest($endpoint, includeAuthHeaders: false);
    }

    /**
     * Helper to verify if the media server is healthy and not draining.
     */
    public function isHealthy(): bool
    {
        try {
            $health = $this->healthCheck();

            return ($health['status'] ?? '') === 'ok' && ! ($health['draining'] ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Helper to verify if the media server is currently draining connections for maintenance.
     */
    public function isDraining(): bool
    {
        try {
            $health = $this->healthCheck();

            return (bool) ($health['draining'] ?? false);
        } catch (OmnicastException $e) {
            return $e->isDraining();
        } catch (Throwable) {
            return false;
        }
    }

    // =========================================================================
    // 8. WebSocket Signaling Protocol Helpers
    // =========================================================================

    /**
     * Get the complete WebSocket signaling URL with authentication query parameter.
     *
     * @param  string  $token  JWT user token.
     * @return string e.g. ws://127.0.0.1:8080/ws?token=eyJhbGci...
     */
    public function getWebSocketUrl(string $token): string
    {
        $baseUrl = $this->config['base_url'];

        // Replace http:// with ws:// and https:// with wss://
        if (str_starts_with($baseUrl, 'https://')) {
            $wsBase = 'wss://'.substr($baseUrl, 8);
        } elseif (str_starts_with($baseUrl, 'http://')) {
            $wsBase = 'ws://'.substr($baseUrl, 7);
        } else {
            $wsBase = 'ws://'.ltrim($baseUrl, '/');
        }

        return rtrim($wsBase, '/').'/ws?token='.urlencode($token);
    }

    /**
     * Format a WebSocket message following the standard OmniCast message envelope.
     *
     * @param  string  $action  Action name (e.g. create_room, join_room, chat)
     * @param  string  $roomId  Room ID
     * @param  string  $userId  User ID
     * @param  array<string, mixed>  $payload  Action-specific payload
     * @param  array<string, mixed>  $extra  Optional extra envelope fields (user_name, avatar_url, role)
     * @return array<string, mixed>
     */
    public function formatWebSocketMessage(
        string $action,
        string $roomId,
        string $userId,
        array $payload = [],
        array $extra = [],
    ): array {
        return array_merge([
            'action' => $action,
            'event' => $action,
            'room_id' => $roomId,
            'user_id' => $userId,
            'payload' => $payload,
        ], $extra);
    }

    // =========================================================================
    // 9. Webhook Signature Verification & Processing
    // =========================================================================

    /**
     * Verify incoming webhook signature from OmniCast server.
     *
     * @param  string  $payload  Raw JSON request body ($request->getContent()).
     * @param  string  $signature  Value from X-Signature header.
     * @param  string|null  $secret  Webhook secret (defaults to configured secret).
     * @return bool True if valid, false otherwise.
     */
    public function verifyWebhookSignature(string $payload, string $signature, ?string $secret = null): bool
    {
        $signingSecret = $secret ?? $this->config['webhook_secret'];

        if ($signingSecret === '') {
            return true; // No secret configured, skip verification
        }

        $expected = hash_hmac('sha256', $payload, $signingSecret);

        return hash_equals($expected, $signature);
    }

    /**
     * Validate webhook signature and return a structured WebhookEvent DTO.
     *
     * @param  string  $payload  Raw JSON body.
     * @param  string  $signature  Header X-Signature value.
     * @param  string|null  $secret  Optional override secret.
     *
     * @throws OmnicastException On invalid signature or invalid JSON.
     */
    public function handleWebhook(string $payload, string $signature, ?string $secret = null): WebhookEvent
    {
        if (! $this->verifyWebhookSignature($payload, $signature, $secret)) {
            throw OmnicastException::invalidWebhookSignature();
        }

        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            throw new OmnicastException('Invalid JSON payload in webhook request.', 400);
        }

        return WebhookEvent::fromArray($decoded);
    }

    // =========================================================================
    // 10. Utilities
    // =========================================================================

    /**
     * Calculate room duration / uptime in seconds from its created_at timestamp.
     *
     * @param  string  $createdAt  ISO 8601 or parseable timestamp.
     * @return int Uptime in seconds.
     */
    public function getRoomUptime(string $createdAt): int
    {
        $createdTime = strtotime($createdAt);
        if ($createdTime === false) {
            return 0;
        }

        return max(0, time() - $createdTime);
    }

    /**
     * Get the active configuration array.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    // =========================================================================
    // Internal HTTP Transport Helpers
    // =========================================================================

    /**
     * Send authenticated GET request.
     *
     * @param  array<string, mixed>  $query
     *
     * @throws OmnicastException
     */
    private function sendGetRequest(
        string $endpoint,
        array $query = [],
        bool $includeAuthHeaders = true,
    ): mixed {
        $client = $includeAuthHeaders ? $this->httpClient() : Http::acceptJson();

        try {
            $response = $client
                ->timeout($this->config['timeout'])
                ->get($this->resolveUrl($endpoint), $query);
        } catch (ConnectionException $e) {
            throw OmnicastException::connectionError($endpoint, previous: $e);
        } catch (RequestException $e) {
            throw OmnicastException::timeout($endpoint, previous: $e);
        }

        return $this->parseResponse($endpoint, $response);
    }

    /**
     * Send authenticated POST request.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws OmnicastException
     */
    private function sendPostRequest(
        string $endpoint,
        array $data = [],
        bool $includeAuthHeaders = true,
    ): mixed {
        $client = $includeAuthHeaders ? $this->httpClient() : Http::acceptJson();

        try {
            $response = $client
                ->timeout($this->config['timeout'])
                ->post($this->resolveUrl($endpoint), $data);
        } catch (ConnectionException $e) {
            throw OmnicastException::connectionError($endpoint, previous: $e);
        } catch (RequestException $e) {
            throw OmnicastException::timeout($endpoint, previous: $e);
        }

        return $this->parseResponse($endpoint, $response);
    }

    /**
     * Send admin HTTP request with X-API-Key header.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws OmnicastException
     */
    private function sendAdminRequest(string $method, string $endpoint, array $data = []): mixed
    {
        $client = Http::withHeaders([
            'X-API-Key' => $this->config['api_key'],
            'Accept' => 'application/json',
        ]);

        try {
            $url = $this->resolveUrl($endpoint);
            $response = match (strtoupper($method)) {
                'POST' => $client->timeout($this->config['timeout'])->post($url, $data),
                default => $client->timeout($this->config['timeout'])->get($url, $data),
            };
        } catch (ConnectionException $e) {
            throw OmnicastException::connectionError($endpoint, previous: $e);
        } catch (RequestException $e) {
            throw OmnicastException::timeout($endpoint, previous: $e);
        }

        return $this->parseResponse($endpoint, $response);
    }

    /**
     * Parse HTTP response and throw appropriate OmnicastException if failed.
     *
     * @throws OmnicastException
     */
    private function parseResponse(string $endpoint, Response $response): mixed
    {
        if ($response->failed()) {
            throw OmnicastException::fromResponse(
                endpoint: $endpoint,
                httpStatusCode: $response->status(),
                responseBody: $response->body(),
            );
        }

        return $response->json();
    }

    /**
     * Build pre-configured HTTP client with X-API-KEY and X-API-SECRET headers.
     */
    private function httpClient(): PendingRequest
    {
        return Http::withHeaders([
            'X-API-KEY' => $this->config['api_key'],
            'X-API-SECRET' => $this->config['api_secret'],
            'Accept' => 'application/json',
        ]);
    }

    /**
     * Resolve relative API endpoint against configured base URL.
     */
    private function resolveUrl(string $endpoint): string
    {
        return rtrim($this->config['base_url'], '/').'/'.ltrim($endpoint, '/');
    }

    /**
     * Validate and normalise the configuration array.
     *
     * @param  array<string, mixed>  $config
     * @return array{
     *     base_url:       string,
     *     api_key:        string,
     *     api_secret:     string,
     *     jwt_secret:     string,
     *     turn_secret:    string,
     *     turn_realm:     string,
     *     turn_port:      int,
     *     webhook_secret: string,
     *     timeout:        int,
     *     jwt_ttl:        int,
     * }
     *
     * @throws OmnicastException
     */
    private function validateConfig(array $config): array
    {
        // Support base_url or legacy api_url
        $baseUrl = $config['base_url'] ?? $config['api_url'] ?? '';
        if (empty($baseUrl)) {
            throw OmnicastException::missingConfiguration('omnicast.base_url');
        }

        $required = ['api_key', 'api_secret', 'jwt_secret'];
        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw OmnicastException::missingConfiguration("omnicast.{$key}");
            }
        }

        return [
            'base_url' => (string) $baseUrl,
            'api_key' => (string) $config['api_key'],
            'api_secret' => (string) $config['api_secret'],
            'jwt_secret' => (string) $config['jwt_secret'],
            'turn_secret' => isset($config['turn_secret']) ? (string) $config['turn_secret'] : 'my_super_secure_turn_secret_999',
            'turn_realm' => isset($config['turn_realm']) ? (string) $config['turn_realm'] : 'omnicast.live',
            'turn_port' => isset($config['turn_port']) ? (int) $config['turn_port'] : 3478,
            'webhook_secret' => isset($config['webhook_secret']) ? (string) $config['webhook_secret'] : '',
            'timeout' => isset($config['timeout']) ? (int) $config['timeout'] : 30,
            'jwt_ttl' => isset($config['jwt_ttl']) ? (int) $config['jwt_ttl'] : 86400,
        ];
    }
}
