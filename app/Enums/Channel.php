<?php

namespace App\Enums;

enum Channel: string
{
    case Ppc = 'ppc';
    case Organic = 'organic';
    case Social = 'social';
    case Referral = 'referral';
    case Direct = 'direct';
    case Unknown = 'unknown';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
