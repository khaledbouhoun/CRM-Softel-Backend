<?php

namespace App\Enums;

/** Planned type of a follow-up (relance); copied to the activity it becomes. */
enum FollowUpActivityType: string
{
    case Call = 'call';
    case Meeting = 'meeting';
    case Message = 'message';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
