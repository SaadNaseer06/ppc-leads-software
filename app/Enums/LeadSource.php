<?php

namespace App\Enums;

enum LeadSource: string
{
    case Form = 'form';
    case Chat = 'chat';
    case Offline = 'offline';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Form => 'Web form',
            self::Chat => 'Live chat',
            self::Offline => 'Offline message',
            self::Manual => 'Manual',
        };
    }
}
