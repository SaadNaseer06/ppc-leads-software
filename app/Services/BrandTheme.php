<?php

namespace App\Services;

/** Turns a brand's one colour into a set that stays readable, whatever colour was picked */
class BrandTheme
{
    public const DEFAULT_COLOR = '#0f766e';

    private const WHITE = [255, 255, 255];

    private const INK = [15, 23, 42];

    /** The WCAG minimum for body text */
    private const READABLE_CONTRAST = 4.5;

    /** @return array{accent: string, on_accent: string, ink: string, soft: string} */
    public static function from(?string $color): array
    {
        $accent = sscanf($color ?: self::DEFAULT_COLOR, '#%02x%02x%02x');

        $ink = $accent;
        while (self::contrast($ink, self::WHITE) < self::READABLE_CONTRAST) {
            $ink = self::mix($ink, [0, 0, 0], 0.08);
        }

        return [
            'accent' => self::hex($accent),
            // Text and icons placed on the brand colour
            'on_accent' => self::hex(self::contrast($accent, self::WHITE) >= self::contrast($accent, self::INK) ? self::WHITE : self::INK),
            // The brand colour when used as text on white
            'ink' => self::hex($ink),
            'soft' => self::hex(self::mix($accent, self::WHITE, 0.9)),
        ];
    }

    private static function mix(array $from, array $to, float $amount): array
    {
        return array_map(fn ($a, $b) => (int) round($a + ($b - $a) * $amount), $from, $to);
    }

    private static function hex(array $rgb): string
    {
        return vsprintf('#%02x%02x%02x', $rgb);
    }

    private static function contrast(array $a, array $b): float
    {
        [$light, $dark] = [max(self::luminance($a), self::luminance($b)), min(self::luminance($a), self::luminance($b))];

        return ($light + 0.05) / ($dark + 0.05);
    }

    private static function luminance(array $rgb): float
    {
        [$r, $g, $b] = array_map(function ($channel) {
            $value = $channel / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
