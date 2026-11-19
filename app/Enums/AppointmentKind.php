<?php

namespace App\Enums;

/** The only appointment types the CRM knows. They cannot be added, renamed, removed or deactivated. */
enum AppointmentKind: string
{
    case Demo = 'demo';
    case Meeting = 'meeting';
    case Training = 'training';
    case Accompaniment = 'accompaniment';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
