<?php

namespace App\Support;

use App\Enums\Channel;

/**
 * Ad attribution for a lead: UTM tags, the ad click ID and the pages involved.
 * Keys match the leads columns (utm_source, click_id, landing_page, ...).
 */
final class Attribution
{
    private const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /** Checked in priority order: paid search IDs first */
    public const CLICK_ID_KEYS = ['gclid', 'gbraid', 'wbraid', 'msclkid', 'dclid', 'fbclid', 'ttclid', 'li_fat_id'];

    private const PAID_CLICK_IDS = ['gclid', 'gbraid', 'wbraid', 'msclkid', 'dclid'];

    /**
     * The ad platform's own campaign ID, in priority order. Google adds gad_campaignid to every
     * ad click by itself; the others are what advertisers usually put in their URL suffix.
     */
    public const CAMPAIGN_ID_KEYS = ['gad_campaignid', 'utm_id', 'campaignid', 'campaign_id'];

    /** Where each click ID comes from, for showing a campaign's platform */
    private const PLATFORMS = [
        'gclid' => 'Google Ads', 'gbraid' => 'Google Ads', 'wbraid' => 'Google Ads', 'dclid' => 'Google Ads',
        'msclkid' => 'Microsoft Ads', 'fbclid' => 'Meta Ads', 'ttclid' => 'TikTok Ads', 'li_fat_id' => 'LinkedIn Ads',
    ];

    private const SOCIAL_CLICK_IDS = ['fbclid', 'ttclid', 'li_fat_id'];

    private const PAID_MEDIUM = '/^(cpc|ppc|paid|paid[-_ ]?search|sem|cpm|cpv|display|paid[-_ ]?social|retargeting)$/i';

    private const SOCIAL_MEDIUM = '/^(social|social[-_ ]?media|sm)$/i';

    private const SEARCH_ENGINES = '/(^|\.)(google|bing|yahoo|duckduckgo|baidu|yandex|ecosia|ask)\./i';

    private const SOCIAL_SITES = '/(^|\.)(facebook|fb|instagram|linkedin|lnkd|t|twitter|x|youtube|tiktok|pinterest|reddit|snapchat|threads)\.(com|co|net|in)$/i';

    /** @return array<string, string> */
    public static function fromUrl(?string $url): array
    {
        if (! $url) {
            return [];
        }
        $query = parse_url($url, PHP_URL_QUERY);
        if (! is_string($query)) {
            return [];
        }
        parse_str($query, $params);

        return self::fromRecord($params);
    }

    /** @return array<string, string> */
    public static function fromRecord(array $record): array
    {
        $result = [];
        foreach (self::UTM_KEYS as $key) {
            if ($value = LeadText::clean($record[$key] ?? null)) {
                $result[$key] = $value;
            }
        }
        foreach (self::CLICK_ID_KEYS as $key) {
            if ($value = LeadText::clean($record[$key] ?? null, 255)) {
                $result['click_id'] = $value;
                $result['click_id_type'] = $key;
                break;
            }
        }
        foreach (self::CAMPAIGN_ID_KEYS as $key) {
            // Only real IDs: an unfilled {campaignid} placeholder or a stray word is not one
            $value = LeadText::clean($record[$key] ?? null, 150);
            if ($value && preg_match('/^[A-Za-z0-9_.:-]+$/', $value) && ! preg_match('/^\{.*\}$/', $value)) {
                $result['campaign_id'] = $value;
                break;
            }
        }
        if (! empty($record['gad_source']) || isset($record['gad_campaignid'])) {
            $result['google_ads'] = '1';
        }

        return $result;
    }

    /** The campaign a lead is filed under: the platform's ID when one came, else the utm_campaign tag */
    public static function campaign(array $a): ?string
    {
        return LeadText::clean($a['campaign_id'] ?? null, 150) ?? LeadText::clean($a['utm_campaign'] ?? null, 150);
    }

    /** "Google Ads", "Microsoft Ads", ... from the click ID or the utm_source tag */
    public static function platform(?string $clickIdType, ?string $utmSource): ?string
    {
        if ($clickIdType && isset(self::PLATFORMS[$clickIdType])) {
            return self::PLATFORMS[$clickIdType];
        }

        return match (true) {
            ! $utmSource => null,
            (bool) preg_match('/google|adwords/i', $utmSource) => 'Google Ads',
            (bool) preg_match('/bing|microsoft/i', $utmSource) => 'Microsoft Ads',
            (bool) preg_match('/facebook|^fb$|meta|instagram|^ig$/i', $utmSource) => 'Meta Ads',
            (bool) preg_match('/tiktok/i', $utmSource) => 'TikTok Ads',
            (bool) preg_match('/linkedin/i', $utmSource) => 'LinkedIn Ads',
            default => null,
        };
    }

    /** Later parts only fill fields the earlier ones left empty */
    public static function merge(array ...$parts): array
    {
        $merged = [];
        foreach ($parts as $part) {
            foreach ($part as $key => $value) {
                if ($value !== null && $value !== '' && empty($merged[$key])) {
                    $merged[$key] = $value;
                }
            }
        }
        if (! empty($merged['click_id']) && empty($merged['click_id_type'])) {
            $merged['click_id_type'] = 'unknown';
        }

        return $merged;
    }

    public static function channel(array $a, ?string $siteDomain = null): Channel
    {
        $clickType = $a['click_id_type'] ?? null;
        $medium = $a['utm_medium'] ?? null;

        if ($clickType && in_array($clickType, self::PAID_CLICK_IDS, true)) {
            return Channel::Ppc;
        }
        // Google marks its ad clicks with gad_* even when the gclid is missing
        if (! empty($a['google_ads'])) {
            return Channel::Ppc;
        }
        if ($medium && preg_match(self::PAID_MEDIUM, $medium)) {
            return Channel::Ppc;
        }
        if ($medium && preg_match(self::SOCIAL_MEDIUM, $medium)) {
            return Channel::Social;
        }
        if ($medium === 'organic') {
            return Channel::Organic;
        }
        if ($clickType && in_array($clickType, self::SOCIAL_CLICK_IDS, true)) {
            return Channel::Social;
        }
        if (! empty($a['utm_source'])) {
            return Channel::Referral;
        }

        $refHost = self::host($a['referrer'] ?? null);
        $ownHost = preg_replace('/^www\./', '', $siteDomain ?? self::host($a['landing_page'] ?? null) ?? '');
        $internal = $ownHost && $refHost && ($refHost === $ownHost || str_ends_with($refHost, ".{$ownHost}"));
        if ($refHost && ! $internal) {
            if (preg_match(self::SEARCH_ENGINES, $refHost)) {
                return Channel::Organic;
            }
            if (preg_match(self::SOCIAL_SITES, $refHost)) {
                return Channel::Social;
            }

            return Channel::Referral;
        }
        if (! empty($a['landing_page']) || ! empty($a['page_url'])) {
            return Channel::Direct;
        }

        return Channel::Unknown;
    }

    private static function host(?string $url): ?string
    {
        $host = $url ? parse_url($url, PHP_URL_HOST) : null;

        return is_string($host) ? strtolower($host) : null;
    }
}
