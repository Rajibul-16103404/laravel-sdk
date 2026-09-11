<?php

declare(strict_types=1);

namespace Omnicast\LaravelSdk\Constants;

/**
 * OmniCast WebSocket Signaling Client -> Server Actions.
 */
class WsAction
{
    public const CREATE_ROOM = 'create_room';

    public const JOIN_ROOM = 'join_room';

    public const CHAT = 'chat';

    public const GIFT = 'gift';

    public const SEAT_REQUEST = 'seat_request';

    public const CANCEL_REQUEST = 'cancel_request';

    public const SEAT_ACCEPT = 'seat_accept';

    public const LEAVE_SEAT = 'leave_seat';

    public const KICK_SEAT = 'kick_seat';

    public const KICK_PARTICIPANT = 'kick_participant';

    public const END_ROOM = 'end_room';

    public const LEAVE = 'leave';

    public const MUTE = 'mute';

    public const SET_MAIN_SEAT = 'set_main_seat';

    public const PK_REQUEST = 'pk_request';

    public const PK_ACCEPT = 'pk_accept';

    public const PK_STOP = 'pk_stop';

    public const SUBSCRIBE_COHOST = 'subscribe_cohost';

    public const SYNC_STATE = 'sync_state';

    public const ICE = 'ice';

    public const ANSWER = 'answer';

    public const REACTION = 'reaction';

    public const PING = 'ping';
}
