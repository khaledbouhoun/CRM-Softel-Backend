<?php

namespace App\Enums;

/** How a prospect/customer is linked to a product. */
enum ProductRelation: string
{
    case Linked = 'linked';         // interested OR owned
    case Interested = 'interested'; // linked, not purchased yet
    case Owned = 'owned';           // purchased (customer owns it)

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
