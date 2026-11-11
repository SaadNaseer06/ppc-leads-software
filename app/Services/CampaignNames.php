<?php

namespace App\Services;

use App\Models\AdCampaign;

/**
 * What a lead's campaign is called. A name someone gave it wins; then the utm_campaign tag the ad
 * carried; then the bare campaign ID, which still tells campaigns apart until someone names it.
 */
class CampaignNames
{
    /**
     * Names for a set of (brand, campaign) pairs, in one query.
     *
     * @param  iterable<array{0: int|null, 1: string|null}>  $pairs
     * @return array<string, string> keyed by "brand_id|campaign"
     */
    public function lookup(iterable $pairs): array
    {
        $brands = [];
        $campaigns = [];
        foreach ($pairs as [$brandId, $campaign]) {
            if ($brandId && $campaign !== null && $campaign !== '') {
                $brands[$brandId] = true;
                $campaigns[$campaign] = true;
            }
        }
        if (! $campaigns) {
            return [];
        }

        return AdCampaign::query()
            ->whereIn('brand_id', array_keys($brands))
            ->whereIn('campaign', array_map('strval', array_keys($campaigns)))
            ->get(['brand_id', 'campaign', 'name'])
            ->mapWithKeys(fn (AdCampaign $c) => [self::key($c->brand_id, $c->campaign) => $c->name])
            ->all();
    }

    public static function key(?int $brandId, ?string $campaign): string
    {
        return $brandId.'|'.$campaign;
    }

    public static function label(?string $name, ?string $utmCampaign, ?string $campaign): ?string
    {
        if ($name !== null && $name !== '') {
            return $name;
        }
        if ($utmCampaign !== null && $utmCampaign !== '') {
            return $utmCampaign;
        }

        return $campaign !== null && $campaign !== '' ? (self::isId($campaign) ? "Campaign {$campaign}" : $campaign) : null;
    }

    /** A platform's numeric campaign ID, as opposed to a utm_campaign tag someone typed */
    public static function isId(?string $campaign): bool
    {
        return $campaign !== null && (bool) preg_match('/^\d{5,}$/', $campaign);
    }
}
