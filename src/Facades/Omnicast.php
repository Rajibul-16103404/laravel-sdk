<?php

declare(strict_types=1);

namespace Omnicast\LaravelSdk\Facades;

use Illuminate\Support\Facades\Facade;
use Omnicast\LaravelSdk\DTOs\WebhookEvent;
use Omnicast\LaravelSdk\OmnicastService;

/**
 * Omnicast Facade
 *
 * Provides static-style access to the OmnicastService singleton.
 *
 * @method static array<string, mixed> generateToken(string $userId, string $userName = '', string $avatarUrl = '', string $role = 'viewer', string $roomId = '', ?bool $canPublish = null, ?bool $canSubscribe = null)
 * @method static array<string, mixed> generateLivekitToken(string $userId, string $userName = '', string $avatarUrl = '', string $role = 'viewer', string $roomId = '', ?bool $canPublish = null, ?bool $canSubscribe = null)
 * @method static array<string, mixed> getDemoToken(string $userId = '', string $role = 'viewer', string $roomId = '')
 * @method static string generateHostToken(string $roomId, string $userId, array<string, mixed> $metadata = [])
 * @method static string generateJoinToken(string $roomId, string $userId, string $role = 'viewer', array<string, mixed> $metadata = [])
 * @method static string generateLocalToken(string $userId, string $userName = '', string $avatarUrl = '', string $role = 'viewer', string $roomId = '', ?bool $canPublish = null, ?bool $canSubscribe = null, array<string, mixed> $metadata = [])
 * @method static array<string, mixed> createRoom(string $roomId, ?string $hostId = null, string $roomName = '', string $roomType = 'live')
 * @method static array<int, array<string, mixed>> listRooms()
 * @method static array<int, array<string, mixed>> getRooms()
 * @method static array<string, mixed> getRoom(string $roomId)
 * @method static int getRoomCount()
 * @method static array<int, array<string, mixed>> getParticipants(string $roomId)
 * @method static int getParticipantCount(string $roomId)
 * @method static array<string, mixed> getIceServers(string $userId = '')
 * @method static array<string, mixed> getTurnCredentials(string $userId = '')
 * @method static array{uris: array<int, string>, username: string, password: string, ttl: int} generateTurnCredentials(string $userId, int $ttl = 86400, ?string $turnSecret = null, array<int, string> $uris = [])
 * @method static array<string, mixed> adminListRooms()
 * @method static array<string, mixed> forceEndRoom(string $roomId)
 * @method static array<string, mixed> closeRoom(string $roomId)
 * @method static array<string, mixed> sendGift(string $roomId, string $senderId = '', string $senderName = '', string $giftId = '', string $giftName = '', string $receiverId = '', int $coins = 0, int $points = 0, int $amount = 1)
 * @method static array<string, mixed> healthCheck()
 * @method static bool isHealthy()
 * @method static bool isDraining()
 * @method static string getWebSocketUrl(string $token)
 * @method static array<string, mixed> formatWebSocketMessage(string $action, string $roomId, string $userId, array<string, mixed> $payload = [], array<string, mixed> $extra = [])
 * @method static bool verifyWebhookSignature(string $payload, string $signature, ?string $secret = null)
 * @method static WebhookEvent handleWebhook(string $payload, string $signature, ?string $secret = null)
 * @method static int getRoomUptime(string $createdAt)
 * @method static array<string, mixed> getConfig()
 *
 * @see OmnicastService
 */
class Omnicast extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return OmnicastService::class;
    }
}
