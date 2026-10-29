<?php

namespace App\Services;

use App\Enums\Channel;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class Dashboard
{
    public const RANGES = [7, 30, 90];

    /**
     * All dashboard numbers come from one GROUP BY over the selected range plus
     * the previous one. With the covering lead indexes it is an index-only range
     * scan: cost grows with the leads in the window, not with the table size.
     */
    public function data(User $user, int $range): array
    {
        $range = in_array($range, self::RANGES, true) ? $range : 30;
        $tz = config('ppc.report_timezone');
        $today = CarbonImmutable::now($tz)->startOfDay();
        $firstDay = $today->subDays($range - 1);
        $prevStart = $firstDay->subDays($range);

        [$visibilitySql, $bindings] = Lead::visibilitySql($user);
        $buckets = collect(DB::select(
            "SELECT DATE(CONVERT_TZ(created_at, '+00:00', ?)) AS day, status, channel, website_id, COUNT(*) AS n
             FROM leads
             WHERE created_at >= ? AND {$visibilitySql}
             GROUP BY day, status, channel, website_id",
            [$today->format('P'), $prevStart->utc(), ...$bindings],
        ))->map(fn ($r) => [
            'day' => substr((string) $r->day, 0, 10),
            'status' => $r->status,
            'channel' => $r->channel,
            'website_id' => $r->website_id !== null ? (int) $r->website_id : null,
            'n' => (int) $r->n,
        ]);

        $first = $firstDay->format('Y-m-d');
        $current = $buckets->where('day', '>=', $first);
        $real = $current->where('status', '!=', LeadStatus::Spam->value);

        $perDay = $real->groupBy('day')->map->sum('n');
        $series = collect(range(0, $range - 1))->map(fn ($i) => [
            'day' => $d = $firstDay->addDays($i)->format('Y-m-d'),
            'count' => (int) ($perDay[$d] ?? 0),
        ])->all();

        $perWebsite = $real->groupBy(fn ($b) => $b['website_id'] ?? 0)->map->sum('n')->sortDesc()->take(8);
        $names = Website::whereIn('id', $perWebsite->keys()->filter())->pluck('name', 'id');

        $team = [];
        if ($user->canAssignLeads()) {
            $rows = Lead::query()->visibleTo($user)
                ->where('created_at', '>=', $firstDay->utc())
                ->where('status', '!=', LeadStatus::Spam->value)
                ->whereNotNull('assignee_id')
                ->groupBy('assignee_id', 'status')
                ->selectRaw('assignee_id, status, COUNT(*) AS n')
                ->get();
            $people = User::whereIn('id', $rows->pluck('assignee_id')->unique())->pluck('name', 'id');
            $team = $rows->groupBy('assignee_id')->map(fn ($r, $id) => [
                'id' => (int) $id,
                'name' => $people[$id] ?? 'Unknown',
                'assigned' => (int) $r->sum('n'),
                'converted' => (int) $r->where('status', LeadStatus::Converted->value)->sum('n'),
            ])->sortByDesc('assigned')->take(8)->values()->all();
        }

        return [
            'range' => $range,
            'total' => $real->sum('n'),
            'previous' => $buckets->where('day', '<', $first)->where('status', '!=', LeadStatus::Spam->value)->sum('n'),
            'today' => $real->where('day', $today->format('Y-m-d'))->sum('n'),
            'converted' => $current->where('status', LeadStatus::Converted->value)->sum('n'),
            'ppc' => $real->where('channel', Channel::Ppc->value)->sum('n'),
            'unassigned' => $user->canAssignLeads()
                ? Lead::query()->visibleTo($user)->whereNull('assignee_id')->where('status', LeadStatus::New->value)->count()
                : 0,
            'my_open' => Lead::where('assignee_id', $user->id)->whereIn('status', LeadStatus::open())->count(),
            'series' => $series,
            'websites' => $perWebsite->map(fn ($n, $id) => [
                'id' => $id ?: null,
                'name' => $id ? ($names[$id] ?? 'Deleted website') : 'No website',
                'count' => (int) $n,
            ])->values()->all(),
            'statuses' => collect(LeadStatus::values())->map(fn ($s) => [
                'status' => $s,
                'count' => (int) $current->where('status', $s)->sum('n'),
            ])->all(),
            'team' => $team,
        ];
    }
}
