<?php

namespace App\Http\Controllers\Api;

use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Models\AdCampaign;
use App\Models\Brand;
use App\Models\Lead;
use App\Services\CampaignNames;
use App\Support\Attribution;
use App\Support\LeadText;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Which ad campaigns bring leads, and the names people know them by */
class CampaignController extends Controller
{
    public const PERIODS = [7, 30, 90, 365];

    private const MAX_ROWS = 300;

    public function __construct(private CampaignNames $names) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canManageCampaigns(), 403);

        $days = in_array((int) $request->query('days'), self::PERIODS, true) ? (int) $request->query('days') : 30;
        $brands = Brand::query()->visibleTo($user)->orderBy('name')->get(['id', 'name']);
        $brandId = (int) $request->query('brand');
        $brandIds = $brandId && $brands->contains('id', $brandId) ? [$brandId] : $brands->pluck('id')->all();
        $start = CarbonImmutable::now(config('ppc.report_timezone'))->subDays($days - 1)->startOfDay();
        $since = $start->utc();

        // Polled by nobody and read a few times a day: a minute of caching keeps repeat visits free
        $key = 'campaigns:'.md5(json_encode([$brandIds, $days, $since->toDateString()]));
        $rows = Cache::remember($key, 60, fn () => $this->summary($brandIds, $since));

        $names = $this->names->lookup($rows->map(fn ($r) => [(int) $r->brand_id, $r->campaign]));
        $brandNames = $brands->pluck('name', 'id');

        return response()->json([
            'days' => $days,
            // The first day counted, for links to the same leads on the Leads page
            'from' => $start->toDateString(),
            'brand' => count($brandIds) === 1 && $brandId ? $brandId : null,
            'periods' => self::PERIODS,
            'brands' => $brands,
            'campaigns' => $rows->map(function ($r) use ($names, $brandNames) {
                $name = $names[CampaignNames::key((int) $r->brand_id, $r->campaign)] ?? null;

                return [
                    'brand_id' => (int) $r->brand_id,
                    'brand' => $brandNames[$r->brand_id] ?? null,
                    'campaign' => $r->campaign,
                    'is_id' => CampaignNames::isId($r->campaign),
                    'name' => $name,
                    'label' => CampaignNames::label($name, $r->utm_campaign, $r->campaign),
                    'tag' => $r->utm_campaign !== $r->campaign ? $r->utm_campaign : null,
                    'platform' => Attribution::platform($r->click_id_type, $r->utm_source),
                    'leads' => (int) $r->leads,
                    'converted' => (int) $r->converted,
                    'last_lead_at' => CarbonImmutable::parse($r->last_lead_at, 'UTC')->toIso8601String(),
                ];
            })->values(),
            'truncated' => $rows->count() >= self::MAX_ROWS,
        ]);
    }

    /** Give a campaign a name (or clear it, with an empty name) for one brand */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canManageCampaigns(), 403);
        $data = $request->validate([
            'brand_id' => ['required', 'integer'],
            'campaign' => ['required', 'string', 'max:150'],
            'name' => ['nullable', 'string', 'max:150'],
        ]);
        abort_unless($user->canAccessBrand($data['brand_id']), 404);
        // Only campaigns that actually brought this brand a lead
        abort_unless(Lead::where('campaign', $data['campaign'])->where('brand_id', $data['brand_id'])->exists(), 404);

        $name = LeadText::clean($data['name'] ?? null, 150);
        $match = ['brand_id' => $data['brand_id'], 'campaign' => $data['campaign']];
        $name === null
            ? AdCampaign::where($match)->delete()
            : AdCampaign::updateOrCreate($match, ['name' => $name, 'updated_by_id' => $user->id]);

        return response()->json(['name' => $name]);
    }

    /** Leads per campaign since a date: one grouped pass over the campaign index */
    private function summary(array $brandIds, CarbonImmutable $since)
    {
        if (! $brandIds) {
            return collect();
        }

        // Every column here is in leads_campaign_timeline_idx, so no lead row is read (0.3-0.7s at 300k leads)
        $rows = DB::table('leads')
            ->whereNotNull('campaign')
            ->where('created_at', '>=', $since)
            ->whereIn('brand_id', $brandIds)
            ->whereNotNull('promoted_at')
            ->where('status', '!=', LeadStatus::Spam->value)
            ->groupBy('brand_id', 'campaign')
            ->selectRaw('brand_id, campaign, COUNT(*) AS leads, SUM(status = ?) AS converted, MAX(created_at) AS last_lead_at, MAX(id) AS sample_id', [LeadStatus::Converted->value])
            ->orderByDesc('leads')
            ->limit(self::MAX_ROWS)
            ->get();

        // The newest lead of each campaign gives its tag and platform: one read per campaign, not per lead
        $samples = DB::table('leads')->whereIn('id', $rows->pluck('sample_id'))
            ->get(['id', 'utm_campaign', 'utm_source', 'click_id_type'])->keyBy('id');

        return $rows->map(function ($r) use ($samples) {
            $sample = $samples[$r->sample_id] ?? null;
            $r->utm_campaign = $sample?->utm_campaign;
            $r->utm_source = $sample?->utm_source;
            $r->click_id_type = $sample?->click_id_type;

            return $r;
        });
    }
}
