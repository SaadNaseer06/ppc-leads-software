<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'SUPER_ADMIN';
    case Admin = 'ADMIN';
    case BrandManager = 'BRAND_MANAGER';
    case FrontSales = 'FRONT_SALES';
    case UpSales = 'UP_SALES';
    case ChatAgent = 'CHAT_AGENT';
    case Finance = 'FINANCE';
    case Developer = 'DEVELOPER';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::BrandManager => 'Brand Manager',
            self::FrontSales => 'Front Sales',
            self::UpSales => 'Up Sales',
            self::ChatAgent => 'Chat Agent',
            self::Finance => 'Finance',
            self::Developer => 'Developer',
        };
    }

    /** Sees every brand and lead */
    public function hasGlobalAccess(): bool
    {
        return $this === self::SuperAdmin || $this === self::Developer;
    }

    /** Sees every lead of their brands, not only the ones assigned to them */
    public function seesWholeBrand(): bool
    {
        return in_array($this, [self::Admin, self::BrandManager, self::Finance, self::ChatAgent], true);
    }

    public function isSales(): bool
    {
        return $this === self::FrontSales || $this === self::UpSales;
    }

    /** @return list<self> Roles a lead can be assigned to */
    public static function assignable(): array
    {
        return [self::FrontSales, self::UpSales, self::Admin, self::BrandManager];
    }

    /** @return list<self> Roles an Admin may hand out to the people they manage */
    public static function adminGrantable(): array
    {
        return [self::BrandManager, self::FrontSales, self::UpSales, self::ChatAgent, self::Finance];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
