<?php

namespace App\Enums;

enum ActivityType: string
{
    case Call = 'call';
    /** Legacy code of existing rows; new data uses Appointment. Both mean "appointment / meeting". */
    case Meeting = 'meeting';
    case Appointment = 'appointment';
    case Message = 'message';
    case Note = 'note';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
