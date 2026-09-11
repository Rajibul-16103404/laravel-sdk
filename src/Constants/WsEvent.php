<?php

declare(strict_types=1);

namespace Omnicast\LaravelSdk\Constants;

/**
 * OmniCast WebSocket Signaling Server -> Client Events.
 */
class WsEvent
{
    public const CONNECTED = 'connected';

    public const ROOM_CREATED = 'room_created';

    public const ROOM_ENDED = 'room_ended';

    public const USER_JOINED = 'user_joined';

    public const USER_LEFT = 'user_left';

    public const VIEWER_UPDATE = 'viewer_update';

    public const CHAT = 'chat';

    public const GIFT = 'gift';

    public const SEAT_REQUEST = 'seat_request';

    public const SEAT_UPDATED = 'seat_updated';

    public const COHOST_JOINED = 'cohost_joined';

    public const COHOST_LEFT = 'cohost_left';

    public const KICKED = 'kicked';

    public const MEDIA_STATE_CHANGED = 'media_state_changed';

    public const PK_REQUEST = 'pk_request';

    public const PK_STARTED = 'pk_started';

    public const PK_ENDED = 'pk_ended';

    public const PK_SCORE_UPDATED = 'pk_score_updated';

    public const REACTION = 'reaction';

    public const ROOM_STATE = 'room_state';

    public const ANSWER = 'answer';

    public const ICE = 'ice';

    public const PONG = 'pong';
}
