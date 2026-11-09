<?php

namespace App\Enums;

/**
 * Behaviour of a sales stage. Business logic always relies on this value,
 * never on the (user editable) stage name.
 */
enum StageType: string
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';

    public function isClosed(): bool
    {
        return $this !== self::Open;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
