<?php

declare(strict_types=1);

namespace Omnicast\LaravelSdk;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Omnicast\LaravelSdk\Exceptions\OmnicastException;

/**
 * OmnicastService
 *
 * Core service for the OmniCast Laravel SDK.
 *
 * Provides JWT token generation for room access and REST API integration
 * for querying rooms and participants from the OmniCast WebRTC media server.
 */
class OmnicastService
{
    /**
     * Validated configuration snapshot injected at construction time.
     *
     * @var array{
     *     api_url:    string,
     *     api_key:    string,
     *     api_secret: string,
     *     jwt_secret: string,
     *     timeout:    int,
     *     jwt_ttl:    int,
     * }
     */
    private readonly array $config;

    /**
     * @param  array<string, mixed>  $config  Raw package configuration (from config('omnicast')).
     *
     * @throws OmnicastException  When a required configuration key is missing or empty.
     */
    public function __construct(array $config)
    {
        $this->config = $this->validateConfig($config);
    }

    // =========================================================================
    // JWT Token Generation
    // =========================================================================

    /**
     * Generate a JWT token granting host-level access to a room.
     *
     * @param  string               $roomId    The target room ID.
     * @param  string               $userId    The host's user ID.
     * @param  array<string, mixed> $metadata  Optional arbitrary metadata embedded in the token.
     *
     * @return string  Signed JWT string.
     *
     * @throws OmnicastException  On JWT signing failure.
     */
    public function generateHostToken(
        string $roomId,
        string $userId,
        array $metadata = [],
    ): string {
        return $this->buildToken(
            roomId: $roomId,
            userId: $userId,
            role: 'host',
            metadata: $metadata,
        );
    }

    /**
     * Generate a JWT token granting viewer or co-host access to a room.
     *
     * @param  string               $roomId    The target room ID.
     * @param  string               $userId    The participant's user ID.
     * @param  string               $role      Either 'viewer' or 'co-host'.
     * @param  array<string, mixed> $metadata  Optional arbitrary metadata embedded in the token.
     *
     * @return string  Signed JWT string.
     *
     * @throws OmnicastException  On JWT signing failure or invalid role.
     */
    public function generateJoinToken(
        string $roomId,
        string $userId,
        string $role = 'viewer',
        array $metadata = [],
    ): string {
        $allowedRoles = ['viewer', 'co-host'];

        if (! in_array($role, $allowedRoles, strict: true)) {
            throw OmnicastException::jwtError(
                "Invalid role [{$role}] for generateJoinToken(). Allowed: "
                . implode(', ', $allowedRoles),
            );
        }

        return $this->buildToken(
            roomId: $roomId,
            userId: $userId,
            role: $role,
            metadata: $metadata,
        );
    }

    // =========================================================================
    // REST API – Rooms
    // =========================================================================

    /**
     * Retrieve all active rooms from the OmniCast server.
     *
     * @return array<int, array<string, mixed>>  List of room objects.
     *
     * @throws OmnicastException  On request failure, timeout, or connection error.
     */
    public function getRooms(): array
    {
        $endpoint = '/rooms';

        try {
            $response = $this->httpClient()
                ->timeout($this->config['timeout'])
                ->get($this->resolveUrl($endpoint));
        } catch (ConnectionException $e) {
            throw OmnicastException::connectionError($endpoint, previous: $e);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            throw OmnicastException::timeout($endpoint, previous: $e);
        }

        if ($response->failed()) {
            throw OmnicastException::requestFailed(
                endpoint: $endpoint,
                httpStatusCode: $response->status(),
                responseBody: $response->body(),
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Return the total number of active rooms.
     *
     * @return int  Count of active rooms.
     *
     * @throws OmnicastException  Propagated from {@see getRooms()}.
     */
    public function getRoomCount(): int
    {
        return count($this->getRooms());
    }

    // =========================================================================
    // REST API – Participants
    // =========================================================================

    /**
     * Retrieve all participants in a given room.
     *
     * @param  string  $roomId  The room whose participants to fetch.
     *
     * @return array<int, array<string, mixed>>  List of participant objects.
     *
     * @throws OmnicastException  On request failure, timeout, or connection error.
     */
    public function getParticipants(string $roomId): array
    {
        $endpoint = "/rooms/{$roomId}/participants";

        try {
            $response = $this->httpClient()
                ->timeout($this->config['timeout'])
                ->get($this->resolveUrl($endpoint));
        } catch (ConnectionException $e) {
            throw OmnicastException::connectionError($endpoint, previous: $e);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            throw OmnicastException::timeout($endpoint, previous: $e);
        }

        if ($response->failed()) {
            throw OmnicastException::requestFailed(
                endpoint: $endpoint,
                httpStatusCode: $response->status(),
                responseBody: $response->body(),
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Return the total number of participants in a given room.
     *
     * @param  string  $roomId  The room to query.
     *
     * @return int  Participant count.
     *
     * @throws OmnicastException  Propagated from {@see getParticipants()}.
     */
    public function getParticipantCount(string $roomId): int
    {
        return count($this->getParticipants($roomId));
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    /**
     * Build and sign a JWT token with the standard OmniCast payload.
     *
     * @param  string               $roomId
     * @param  string               $userId
     * @param  string               $role
     * @param  array<string, mixed> $metadata
     *
     * @throws OmnicastException
     */
    private function buildToken(
        string $roomId,
        string $userId,
        string $role,
        array $metadata,
    ): string {
        $now = time();

        $payload = [
            'api_key'  => $this->config['api_key'],
            'room_id'  => $roomId,
            'user_id'  => $userId,
            'role'     => $role,
            'metadata' => $metadata,
            'iat'      => $now,
            'exp'      => $now + $this->config['jwt_ttl'],
        ];

        try {
            return JWT::encode($payload, $this->config['jwt_secret'], 'HS256');
        } catch (\Throwable $e) {
            throw OmnicastException::jwtError($e->getMessage(), previous: $e);
        }
    }

    /**
     * Return a pre-configured HTTP client with the required auth headers.
     *
     * @return \Illuminate\Http\Client\PendingRequest
     */
    private function httpClient(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'X-API-Key'    => $this->config['api_key'],
            'X-API-Secret' => $this->config['api_secret'],
            'Accept'       => 'application/json',
        ]);
    }

    /**
     * Resolve a relative API endpoint path against the configured base URL.
     */
    private function resolveUrl(string $endpoint): string
    {
        return rtrim($this->config['api_url'], '/') . '/' . ltrim($endpoint, '/');
    }

    /**
     * Validate and normalise the raw package configuration array.
     *
     * @param  array<string, mixed>  $config
     *
     * @return array{
     *     api_url:    string,
     *     api_key:    string,
     *     api_secret: string,
     *     jwt_secret: string,
     *     timeout:    int,
     *     jwt_ttl:    int,
     * }
     *
     * @throws OmnicastException
     */
    private function validateConfig(array $config): array
    {
        $required = ['api_url', 'api_key', 'api_secret', 'jwt_secret'];

        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw OmnicastException::missingConfiguration("omnicast.{$key}");
            }
        }

        return [
            'api_url'    => (string) $config['api_url'],
            'api_key'    => (string) $config['api_key'],
            'api_secret' => (string) $config['api_secret'],
            'jwt_secret' => (string) $config['jwt_secret'],
            'timeout'    => isset($config['timeout']) ? (int) $config['timeout'] : 30,
            'jwt_ttl'    => isset($config['jwt_ttl'])  ? (int) $config['jwt_ttl']  : 86400,
        ];
    }
}
