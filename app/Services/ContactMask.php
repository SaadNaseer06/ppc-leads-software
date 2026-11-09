<?php

namespace App\Services;

/**
 * What sales reps see of a client's contact details: enough to recognise them, not enough to
 * take them away. Done on the server, so the API hands out nothing more than the screen shows.
 */
class ContactMask
{
    public const DOT = '•';

    private const EMAIL = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';

    /** Seven or more digits, allowing the spaces, dots, dashes and brackets phone numbers are written with */
    private const PHONE = '/\+?\d(?:[\s().\-]*\d){6,}/';

    public static function email(?string $email): ?string
    {
        if ($email === null || $email === '') {
            return $email;
        }
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).str_repeat(self::DOT, 3).($domain !== '' ? "@{$domain}" : '');
    }

    /** Keeps the last three digits and every separator: "+1 (555) 010-4821" becomes "+• (•••) •••-•821" */
    public static function phone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return $phone;
        }
        $digits = preg_match_all('/\d/', $phone);
        $seen = 0;

        return preg_replace_callback('/\d/', function ($digit) use (&$seen, $digits) {
            return ++$seen > $digits - 3 ? $digit[0] : self::DOT;
        }, $phone);
    }

    /** Free text a client typed, like an enquiry, with any email address or phone number in it masked */
    public static function text(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }
        $text = preg_replace_callback(self::EMAIL, fn ($m) => self::email($m[0]), $text);

        return preg_replace_callback(self::PHONE, fn ($m) => self::phone($m[0]), $text);
    }

    /** @param array<string, mixed> $fields extra form fields a website sent */
    public static function fields(array $fields): array
    {
        $masked = [];
        foreach ($fields as $key => $value) {
            $masked[$key] = is_scalar($value) ? self::text((string) $value) : $value;
        }

        return $masked;
    }

    /** Something a rep sent back that is still the masked version, so it must not overwrite the real value */
    public static function isMasked(?string $value): bool
    {
        return $value !== null && str_contains($value, self::DOT);
    }
}
