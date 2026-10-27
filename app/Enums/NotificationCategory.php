<?php

namespace App\Enums;

/**
 * What a follower can be notified of about a result.
 */
enum NotificationCategory: string
{
    /** New results published by the TSE. */
    case Updates = 'atualizacao';

    /** Mathematically decided: a winner or a runoff. */
    case Decided = 'definido';

    /** The count is over. */
    case Final = 'final';

    /**
     * Column of a follow that turns this kind of notification on.
     */
    public function column(): string
    {
        return match ($this) {
            self::Updates => 'notify_updates',
            self::Decided => 'notify_decided',
            self::Final => 'notify_final',
        };
    }
}
