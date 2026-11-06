<?php

namespace App\Services;

use App\Enums\Channel;
use App\Enums\LeadStatus;
use App\Models\Brand;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sales analytics on the invoices raised in a period. Everything reads from the same set, so
 * the numbers always agree: invoiced = collected + outstanding, in the totals, the chart and
 * every breakdown. Each figure is a GROUP BY: rows are never loaded just to be counted.
 */
class SalesReport
{
    public const STATUSES = ['paid', 'unpaid', 'overdue', 'cancelled'];

    /** Up to about two months the chart shows days; longer periods show months */
    private const DAILY_UP_TO_DAYS = 62;

    /** Money received on an invoice, capped at its total. An invoice marked paid counts in full. */
    private const COLLECTED = "CASE WHEN status = 'paid' THEN amount ELSE LEAST(amount, paid) END";

    /** @return array{from: string, to: string, brand: ?int, website: ?int, rep: ?int, channel: ?string, status: ?string} */
    public function filters(array $params): array
    {
        $tz = config('ppc.report_timezone');
        $today = CarbonImmutable::now($tz)->startOfDay();
        $day = fn (mixed $value) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            ? CarbonImmutable::createFromFormat('Y-m-d', $value, $tz)?->startOfDay()
            : null;
        $id = fn (mixed $value) => is_string($value) && ctype_digit($value) ? (int) $value : null;
        $pick = fn (mixed $value, array $allowed) => in_array($value, $allowed, true) ? $value : null;

        $from = $day($params['from'] ?? null) ?? $today->subDays(29);
        $to = $day($params['to'] ?? null) ?? $today;
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'brand' => $id($params['brand'] ?? null),
            'website' => $id($params['website'] ?? null),
            'rep' => $id($params['rep'] ?? null),
            'channel' => $pick($params['channel'] ?? null, Channel::values()),
            'status' => $pick($params['status'] ?? null, self::STATUSES),
        ];
    }

    public function data(User $user, array $f): array
    {
        [$from, $to] = $this->period($f);
        $days = (int) $from->diffInDays($to) + 1;
        $set = $this->invoiceSet($user, $f, $from, $to);

        $totals = $this->totals($set);
        // The same length of time just before, for the "vs previous period" comparison
        $previous = $this->totals($this->invoiceSet($user, $f, $from->subDays($days), $from->subDay()));

        return [
            'filters' => $f,
            'options' => $this->options($user),
            'totals' => $totals,
            'previous' => ['invoiced' => $previous['invoiced'], 'collected' => $previous['collected']],
            'leads' => $this->leads($user, $f, $from, $to),
            'granularity' => $days <= self::DAILY_UP_TO_DAYS ? 'day' : 'month',
            'series' => $this->series($set, $from, $to, $days),
            'by_brand' => $this->breakdown($set, 'brand_id', Brand::query()),
            'by_rep' => $this->breakdown($set, 'created_by_id', User::query()),
            'by_channel' => $this->breakdownBy($set, 'channel')
                ->map(fn ($r) => [...$r, 'id' => $r['key'], 'name' => $r['key']])->values()->all(),
            'by_campaign' => $this->breakdownBy($set->clone()->whereNotNull('utm_campaign'), 'utm_campaign')
                ->take(10)->map(fn ($r) => [...$r, 'id' => $r['key'], 'name' => $r['key']])->values()->all(),
        ];
    }

    /** Every invoice in the filtered set, for the CSV: newest first, one batch at a time */
    public function rows(User $user, array $f, callable $each): void
    {
        [$from, $to] = $this->period($f);
        $set = $this->invoiceSet($user, $f, $from, $to);
        $beforeId = null;
        do {
            $batch = $set->clone()
                ->when($beforeId, fn ($q) => $q->where('id', '<', $beforeId))
                ->orderByDesc('id')->limit(1000)->get();
            $brands = Brand::whereIn('id', $batch->pluck('brand_id')->unique())->pluck('name', 'id');
            $reps = User::whereIn('id', $batch->pluck('created_by_id')->unique())->pluck('name', 'id');
            foreach ($batch as $row) {
                $each($row, $brands[$row->brand_id] ?? '', $reps[$row->created_by_id] ?? '');
            }
            $beforeId = $batch->last()?->id;
        } while ($batch->count() === 1000);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} first and last day, in the report timezone */
    private function period(array $f): array
    {
        $tz = config('ppc.report_timezone');

        return [
            CarbonImmutable::createFromFormat('Y-m-d', $f['from'], $tz)->startOfDay(),
            CarbonImmutable::createFromFormat('Y-m-d', $f['to'], $tz)->startOfDay(),
        ];
    }

    /** The invoices under analysis, with what has been paid on each and the lead they came from */
    private function invoiceSet(User $user, array $f, CarbonImmutable $from, CarbonImmutable $to): QueryBuilder
    {
        $today = CarbonImmutable::now(config('ppc.report_timezone'))->format('Y-m-d');
        $invoices = Invoice::query()->visibleTo($user)
            // Left join: an invoice raised without a lead is still a sale
            ->leftJoin('leads', 'leads.id', '=', 'invoices.lead_id')
            ->where('invoices.created_at', '>=', $from->utc())
            ->where('invoices.created_at', '<', $to->addDay()->utc())
            ->when($f['brand'], fn (Builder $q, $id) => $q->where('invoices.brand_id', $id))
            ->when($f['rep'], fn (Builder $q, $id) => $q->where('invoices.created_by_id', $id))
            ->when($f['website'], fn (Builder $q, $id) => $q->where('leads.website_id', $id))
            ->when($f['channel'], fn (Builder $q, $channel) => $q->where('leads.channel', $channel));

        match ($f['status']) {
            'paid' => $invoices->where('invoices.status', 'paid'),
            'unpaid' => $invoices->where('invoices.status', 'sent'),
            'overdue' => $invoices->where('invoices.status', 'sent')->where('invoices.due_date', '<', $today),
            'cancelled' => $invoices->where('invoices.status', 'cancelled'),
            default => $invoices->where('invoices.status', '!=', 'cancelled'),
        };

        $invoices->select([
            'invoices.id', 'invoices.number', 'invoices.amount', 'invoices.subtotal', 'invoices.discount', 'invoices.tax_amount',
            'invoices.currency', 'invoices.status', 'invoices.due_date', 'invoices.brand_id', 'invoices.created_by_id',
            'invoices.created_at', 'invoices.client_name as lead_name', 'leads.channel', 'leads.utm_campaign',
        ])->selectRaw('(SELECT COALESCE(SUM(p.amount), 0) FROM payment_transactions p WHERE p.invoice_id = invoices.id) AS paid');

        return DB::query()->fromSub($invoices->toBase(), 'i');
    }

    private function totals(QueryBuilder $set): array
    {
        $collected = self::COLLECTED;
        $today = CarbonImmutable::now(config('ppc.report_timezone'))->format('Y-m-d');
        $row = $set->clone()->selectRaw(
            "COUNT(*) AS invoices, COALESCE(SUM(amount), 0) AS invoiced, COALESCE(SUM({$collected}), 0) AS collected,
             COALESCE(SUM(status = 'paid'), 0) AS paid_invoices,
             COALESCE(SUM(CASE WHEN status = 'sent' AND due_date < ? THEN amount - LEAST(amount, paid) ELSE 0 END), 0) AS overdue",
            [$today]
        )->first();

        $invoices = (int) $row->invoices;
        $invoiced = (float) $row->invoiced;
        $received = (float) $row->collected;

        return [
            'invoices' => $invoices,
            'paid_invoices' => (int) $row->paid_invoices,
            'invoiced' => $invoiced,
            'collected' => $received,
            'outstanding' => round($invoiced - $received, 2),
            'overdue' => (float) $row->overdue,
            'average' => $invoices ? round($invoiced / $invoices, 2) : 0,
        ];
    }

    /** Leads in the same period: how much pipeline produced those sales. Counted from the index, cached briefly. */
    private function leads(User $user, array $f, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $brandIds = Brand::query()->visibleTo($user)->pluck('id');
        $key = 'reports:sales-leads:'.md5(json_encode([$brandIds, $f['from'], $f['to'], $f['brand'], $f['website'], $f['channel'], $f['rep']]));

        return Cache::remember($key, 60, function () use ($brandIds, $f, $from, $to) {
            $row = Lead::query()->inInbox()
                ->whereIn('brand_id', $f['brand'] ? $brandIds->intersect([$f['brand']]) : $brandIds)
                ->where('created_at', '>=', $from->utc())
                ->where('created_at', '<', $to->addDay()->utc())
                ->where('status', '!=', LeadStatus::Spam->value)
                ->when($f['website'], fn ($q, $id) => $q->where('website_id', $id))
                ->when($f['channel'], fn ($q, $channel) => $q->where('channel', $channel))
                ->when($f['rep'], fn ($q, $id) => $q->where('assignee_id', $id))
                ->selectRaw("COUNT(*) AS received, COALESCE(SUM(status = 'converted'), 0) AS converted")
                ->toBase()->first();

            return ['received' => (int) $row->received, 'converted' => (int) $row->converted];
        });
    }

    /** Invoiced per day or month, gaps included so the chart's time axis stays even */
    private function series(QueryBuilder $set, CarbonImmutable $from, CarbonImmutable $to, int $days): array
    {
        $daily = $days <= self::DAILY_UP_TO_DAYS;
        $format = $daily ? '%Y-%m-%d' : '%Y-%m';
        $collected = self::COLLECTED;
        $offset = $from->format('P');

        $rows = $set->clone()
            ->selectRaw("DATE_FORMAT(CONVERT_TZ(created_at, '+00:00', ?), '{$format}') AS bucket, SUM(amount) AS invoiced, SUM({$collected}) AS collected", [$offset])
            ->groupBy('bucket')->get()->keyBy('bucket');

        $series = [];
        for ($at = $daily ? $from : $from->startOfMonth(); $at->lte($to); $at = $daily ? $at->addDay() : $at->addMonth()) {
            $bucket = $at->format($daily ? 'Y-m-d' : 'Y-m');
            $invoiced = (float) ($rows[$bucket]->invoiced ?? 0);
            $received = (float) ($rows[$bucket]->collected ?? 0);
            $series[] = [
                'bucket' => $bucket,
                'invoiced' => $invoiced,
                'collected' => $received,
                'outstanding' => round($invoiced - $received, 2),
            ];
        }

        return $series;
    }

    /** One row per brand or rep, biggest first, with the name looked up */
    private function breakdown(QueryBuilder $set, string $column, Builder $names): array
    {
        $rows = $this->breakdownBy($set, $column);
        $labels = $names->whereIn('id', $rows->pluck('key'))->pluck('name', 'id');

        return $rows->map(fn ($r) => [...$r, 'id' => (int) $r['key'], 'name' => $labels[$r['key']] ?? 'Unknown'])->values()->all();
    }

    private function breakdownBy(QueryBuilder $set, string $column)
    {
        $collected = self::COLLECTED;

        return $set->clone()
            ->selectRaw("{$column} AS `key`, COUNT(*) AS invoices, SUM(amount) AS invoiced, SUM({$collected}) AS collected")
            ->groupBy($column)->orderByDesc('invoiced')->limit(50)->get()
            ->map(fn ($r) => [
                'key' => $r->key,
                'invoices' => (int) $r->invoices,
                'invoiced' => (float) $r->invoiced,
                'collected' => (float) $r->collected,
                'outstanding' => round((float) $r->invoiced - (float) $r->collected, 2),
            ]);
    }

    /** What can be picked in the filter bar: only what this user may see */
    private function options(User $user): array
    {
        $brands = Brand::query()->visibleTo($user)->orderBy('name')->get(['id', 'name']);

        return [
            'brands' => $brands,
            'websites' => Website::whereIn('brand_id', $brands->pluck('id'))->orderBy('name')->get(['id', 'name', 'brand_id']),
            'reps' => User::whereIn('id', Invoice::query()->visibleTo($user)->select('created_by_id')->distinct())
                ->orderBy('name')->get(['id', 'name']),
            'channels' => Channel::values(),
            'statuses' => self::STATUSES,
        ];
    }
}
