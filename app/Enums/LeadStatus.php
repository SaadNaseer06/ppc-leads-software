<?php

namespace App\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Converted = 'converted';
    case Lost = 'lost';
    case Spam = 'spam';

    /** @return list<string> Statuses that still need work from the sales team */
    public static function open(): array
    {
        return [self::New->value, self::Contacted->value, self::Qualified->value];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
