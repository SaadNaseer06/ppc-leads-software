<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Lead;
use App\Models\User;
use App\Support\LeadText;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Filtering, keyset pagination and capped counting for the leads list. Designed
 * so every page costs the same at 5 thousand or 5 million leads.
 */
class LeadQuery
{
    public const PAGE_SIZES = [25, 50, 100];

    /** Columns the list needs; all other columns stay on disk */
    private const LIST_COLUMNS = [
        'leads.id', 'leads.name', 'leads.email', 'leads.phone', 'leads.company', 'leads.status', 'leads.source',
        'leads.channel', 'leads.utm_campaign', 'leads.submission_count', 'leads.created_at', 'leads.website_id',
        'leads.brand_id', 'leads.assignee_id',
    ];

    /** @return array<string, string> */
    public function filters(array $params): array
    {
        $pick = fn (string $key) => is_string($params[$key] ?? null) && trim($params[$key]) !== '' ? trim($params[$key]) : null;

        return array_filter([
            'q' => ($q = $pick('q')) !== null ? mb_substr($q, 0, 100) : null,
            'status' => $pick('status'),
            'source' => $pick('source'),
            'channel' => $pick('channel'),
            'website' => $pick('website'),
            'brand' => $pick('brand'),
            'assignee' => $pick('assignee'), // "unassigned" | "me" | user id
            'from' => $pick('from'),
            'to' => $pick('to'),
        ], fn ($v) => $v !== null);
    }

    /** Visibility plus filters. Spam is hidden unless explicitly asked for. */
    public function query(User $user, array $f): Builder
    {
        $q = Lead::query()->visibleTo($user);

        isset($f['status']) ? $q->where('leads.status', $f['status']) : $q->where('leads.status', '!=', 'spam');
        foreach (['source' => 'source', 'channel' => 'channel', 'website' => 'website_id', 'brand' => 'brand_id'] as $key => $column) {
            if (isset($f[$key])) {
                $q->where("leads.{$column}", $f[$key]);
            }
        }

        match ($f['assignee'] ?? null) {
            null => null,
            'unassigned' => $q->whereNull('leads.assignee_id'),
            'me' => $q->where('leads.assignee_id', $user->id),
            default => $q->where('leads.assignee_id', (int) $f['assignee']),
        };

        $tz = config('ppc.report_timezone');
        if (isset($f['from']) && ($from = $this->day($f['from'], $tz))) {
            $q->where('leads.created_at', '>=', $from->utc());
        }
        if (isset($f['to']) && ($to = $this->day($f['to'], $tz))) {
            $q->where('leads.created_at', '<', $to->addDay()->utc());
        }

        if (isset($f['q'])) {
            $fulltext = LeadText::fulltextQuery($f['q']);
            $fulltext
                ? $q->whereFullText('leads.search_text', $fulltext, ['mode' => 'boolean'])
                : $q->where(fn ($w) => $w->where('leads.email', 'like', $f['q'].'%')->orWhere('leads.name', 'like', $f['q'].'%'));
        }

        return $q;
    }

    /**
     * Brands to page through separately, or null. MySQL can't read
     * `brand_id IN (a, b) ORDER BY created_at LIMIT n` in index order, so it sorts
     * every matching row; one ordered LIMIT query per brand, merged, stays cheap.
     *
     * @return list<int>|null
     */
    public function brandSplit(User $user, array $f): ?array
    {
        if ($user->role !== Role::Admin && $user->role !== Role::Finance) {
            return null;
        }
        // These filters already narrow to a single ordered index range (or use FULLTEXT)
        if (isset($f['brand']) || isset($f['website']) || isset($f['assignee']) || isset($f['q'])) {
            return null;
        }
        $brands = $user->brandIds();

        return count($brands) >= 2 && count($brands) <= 12 ? $brands : null;
    }

    /**
     * Keyset pagination on (created_at DESC, id DESC): every page costs the same
     * however deep you go, unlike OFFSET which rescans every skipped row.
     *
     * @return array{rows: EloquentCollection<int, Lead>, next_cursor: ?string, prev_cursor: ?string}
     */
    public function page(User $user, array $f, ?string $cursor, string $dir, int $take): array
    {
        $take = in_array($take, self::PAGE_SIZES, true) ? $take : 50;
        $position = $this->decodeCursor($cursor);
        $backwards = $position !== null && $dir === 'prev';
        $order = $backwards ? 'asc' : 'desc';

        $fetch = function (?int $brandId) use ($user, $f, $position, $backwards, $order, $take) {
            $q = $this->query($user, $f)->select(self::LIST_COLUMNS);
            if ($brandId !== null) {
                $q->where('leads.brand_id', $brandId);
            }
            if ($position) {
                [$t, $id] = $position;
                $op = $backwards ? '>' : '<';
                $q->where(fn ($w) => $w->where('leads.created_at', $op, $t)
                    ->orWhere(fn ($e) => $e->where('leads.created_at', $t)->where('leads.id', $op, $id)));
            }

            return $q->orderBy('leads.created_at', $order)->orderBy('leads.id', $order)->limit($take + 1)->get();
        };

        $split = $this->brandSplit($user, $f);
        $rows = $split === null ? $fetch(null) : collect($split)->flatMap(fn ($b) => $fetch($b));
        if ($split !== null) {
            // Each part is already sorted, so the overall first take+1 rows are among them
            $rows = $rows->sort(function (Lead $a, Lead $b) use ($backwards) {
                $cmp = [$a->created_at->getTimestamp(), $a->id] <=> [$b->created_at->getTimestamp(), $b->id];

                return $backwards ? $cmp : -$cmp;
            })->values()->take($take + 1);
        }

        $hasMore = $rows->count() > $take;
        $rows = new EloquentCollection($rows->take($take)->all());
        if ($backwards) {
            $rows = $rows->reverse()->values();
        }
        $rows->load(['website:id,name', 'brand:id,name', 'assignee:id,name']);

        $first = $rows->first();
        $last = $rows->last();

        return [
            'rows' => $rows,
            // Older rows exist if we just paged back, or this forward page overflowed
            'next_cursor' => $last && ($backwards || $hasMore) ? $this->encodeCursor($last) : null,
            // Newer rows exist if we came from a cursor, unless paging back ran out
            'prev_cursor' => $first && $position && (! $backwards || $hasMore) ? $this->encodeCursor($first) : null,
        ];
    }

    /**
     * Counts stop at a cap so huge result sets never cost a full scan. Search
     * matches each cost a row read, so they are capped lower.
     *
     * @return array{count: int, capped: bool}
     */
    public function count(User $user, array $f): array
    {
        $cap = isset($f['q']) ? config('ppc.search_count_cap') : config('ppc.count_cap');
        $count = DB::query()->fromSub($this->query($user, $f)->select('leads.id')->limit($cap + 1), 't')->count();

        return ['count' => min($count, $cap), 'capped' => $count > $cap];
    }

    public function encodeCursor(Lead $lead): string
    {
        return rtrim(strtr(base64_encode($lead->created_at->getTimestamp().':'.$lead->id), '+/', '-_'), '=');
    }

    /** @return array{0: CarbonImmutable, 1: int}|null */
    private function decodeCursor(?string $cursor): ?array
    {
        if (! $cursor) {
            return null;
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (! $decoded || ! preg_match('/^(\d+):(\d+)$/', $decoded, $m)) {
            return null;
        }

        return [CarbonImmutable::createFromTimestampUTC((int) $m[1]), (int) $m[2]];
    }

    private function day(string $value, string $tz): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $value, $tz)?->startOfDay();
    }
}
