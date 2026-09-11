<?php

declare(strict_types=1);

namespace Omnicast\LaravelSdk\DTOs;

/**
 * WebhookEvent DTO
 *
 * Represents an incoming webhook payload from the OmniCast media server.
 */
class WebhookEvent
{
    /**
     * @param  string  $eventType  Unique event type (e.g. RoomStarted, ParticipantJoined)
     * @param  string  $event  Alias for eventType
     * @param  string  $roomId  Associated room ID
     * @param  string  $userId  User ID associated with event
     * @param  int  $timestamp  Unix timestamp of event
     * @param  array<string, mixed>  $data  Event-specific payload
     * @param  array<string, mixed>  $raw  Full raw decoded payload
     */
    public function __construct(
        public readonly string $eventType,
        public readonly string $event,
        public readonly string $roomId,
        public readonly string $userId,
        public readonly int $timestamp,
        public readonly array $data = [],
        public readonly array $raw = [],
    ) {}

    /**
     * Construct from decoded array.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $eventType = (string) ($payload['event_type'] ?? $payload['event'] ?? '');

        return new self(
            eventType: $eventType,
            event: (string) ($payload['event'] ?? $eventType),
            roomId: (string) ($payload['room_id'] ?? ''),
            userId: (string) ($payload['user_id'] ?? ''),
            timestamp: (int) ($payload['timestamp'] ?? time()),
            data: is_array($payload['data'] ?? null) ? $payload['data'] : [],
            raw: $payload,
        );
    }

    /**
     * Get a specific value from data with optional default.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Convert DTO back to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_type' => $this->eventType,
            'event' => $this->event,
            'room_id' => $this->roomId,
            'user_id' => $this->userId,
            'timestamp' => $this->timestamp,
            'data' => $this->data,
        ];
    }
}
