<?php

namespace App\Support;

final class LeadText
{
    public static function clean(mixed $value, int $max = 191): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }

    public static function email(mixed $value): ?string
    {
        $email = self::clean($value);
        $email = $email !== null ? mb_strtolower($email) : null;

        return $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** Last 10 digits, so "+1 (555) 010-1234" and "5550101234" match */
    public static function phoneKey(mixed $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) ($value ?? ''));

        return strlen($digits) >= 7 ? substr($digits, -10) : null;
    }

    /** Tokens stored in leads.search_text and covered by its FULLTEXT index */
    public static function searchText(?string $name, ?string $email, ?string $phone, ?string $company = null): string
    {
        $parts = array_filter([$name, $email, $email ? str_replace(['@', '.'], ' ', $email) : null, $company]);
        $digits = preg_replace('/\D/', '', (string) $phone);
        if ($digits !== '') {
            $parts[] = $digits;
            if (strlen($digits) > 10) {
                $parts[] = substr($digits, -10);
            }
            if (strlen($digits) > 7) {
                $parts[] = substr($digits, -7);
            }
        }

        return mb_substr(mb_strtolower(implode(' ', $parts)), 0, 2000);
    }

    // InnoDB's default full-text stopwords; a required (+) stopword never matches.
    private const STOPWORDS = [
        'a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in',
        'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who',
        'will', 'with', 'und', 'www',
    ];

    /**
     * Turns user input into a BOOLEAN MODE query where every word is a required
     * prefix ("+ali* +example*"). Returns null when nothing searchable remains.
     */
    public static function fulltextQuery(string $term): ?string
    {
        $term = mb_strtolower(trim($term));
        $digits = preg_replace('/[\s()+\-.]/', '', $term);
        if (preg_match('/^\d{4,}$/', $digits)) {
            return "+{$digits}*";
        }

        $tokens = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', $term) ?: [],
            fn ($t) => mb_strlen($t) >= 2 && ! in_array($t, self::STOPWORDS, true),
        ));
        if ($tokens === []) {
            return null;
        }

        return implode(' ', array_map(fn ($t) => "+{$t}*", array_slice($tokens, 0, 8)));
    }

    /** "Chrome on Windows", "Safari on iPhone": enough for a sales rep, no UA library needed */
    public static function device(?string $ua): ?string
    {
        if (! $ua) {
            return null;
        }
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => null,
        };
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return $browser || $os ? implode(' on ', array_filter([$browser, $os])) : null;
    }
}
