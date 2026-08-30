<?php

declare(strict_types=1);

namespace Omnicast\LaravelSdk\Facades;

use Illuminate\Support\Facades\Facade;
use Omnicast\LaravelSdk\OmnicastService;

/**
 * Omnicast Facade
 *
 * Provides static-style access to the OmnicastService singleton.
 *
 * @method static string generateHostToken(string $roomId, string $userId, array $metadata = [])
 * @method static string generateJoinToken(string $roomId, string $userId, string $role = 'viewer', array $metadata = [])
 * @method static array  getRooms()
 * @method static int    getRoomCount()
 * @method static array  getParticipants(string $roomId)
 * @method static int    getParticipantCount(string $roomId)
 *
 * @see \Omnicast\LaravelSdk\OmnicastService
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
