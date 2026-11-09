<?php

namespace App\Enums;

/** Planned type of a follow-up (relance); copied to the activity it becomes. */
enum FollowUpActivityType: string
{
    case Call = 'call';
    case Appointment = 'appointment';
    case Message = 'message';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
