<?php

declare(strict_types=1);

namespace Omnicast\LaravelSdk\Constants;

/**
 * OmniCast User Roles.
 */
class Role
{
    public const HOST = 'host';

    public const COHOST = 'cohost';

    public const PUBLISHER = 'publisher';

    public const VIEWER = 'viewer';

    public const USER = 'user';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::HOST,
            self::COHOST,
            self::PUBLISHER,
            self::VIEWER,
            self::USER,
        ];
    }
}
