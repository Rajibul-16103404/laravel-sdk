<?php

declare(strict_types=1);

namespace Omnicast\LaravelSdk\Constants;

/**
 * OmniCast Webhook Event Types.
 */
class WebhookType
{
    public const ROOM_STARTED = 'RoomStarted';

    public const ROOM_ENDED = 'RoomEnded';

    public const PARTICIPANT_JOINED = 'ParticipantJoined';

    public const PARTICIPANT_LEFT = 'ParticipantLeft';

    public const GIFT_SENT = 'GiftSent';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::ROOM_STARTED,
            self::ROOM_ENDED,
            self::PARTICIPANT_JOINED,
            self::PARTICIPANT_LEFT,
            self::GIFT_SENT,
        ];
    }
}
