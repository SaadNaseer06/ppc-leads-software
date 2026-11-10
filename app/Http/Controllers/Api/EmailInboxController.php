<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Lead;
use App\Models\LeadEmail;
use App\Models\Mailbox;
use App\Services\ContactMask;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Emails inbox: one conversation per lead, newest first, read from the conversation state kept on
 * `leads` (indexed), so a page costs three small queries however many emails there are.
 */
class EmailInboxController extends Controller
{
    private const PAGE = 40;

    private const FILTERS = ['all', 'unread', 'needs_reply'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canWorkLeads(), 403);

        $filter = in_array($request->query('filter'), self::FILTERS, true) ? $request->query('filter') : 'all';
        $brandId = $request->integer('brand_id') ?: null;
        $search = trim((string) $request->query('q', ''));
        // First, so the conversation being opened is already read in the list beside it
        $selectedId = $request->integer('id') ?: null;
        $selected = $selectedId ? $this->thread($request, $selectedId) : null;

        $query = Lead::query()->visibleTo($user)->whereNotNull('leads.last_email_at')
            ->when($brandId, fn (Builder $q) => $q->where('leads.brand_id', $brandId))
            ->when($filter === 'unread', fn (Builder $q) => $q->where('leads.email_unread', true))
            ->when($filter === 'needs_reply', fn (Builder $q) => $q->where('leads.email_needs_reply', true))
            ->when($search !== '', fn (Builder $q) => $q->where('leads.name', 'like', '%'.addcslashes($search, '%_\\').'%'));
        try {
            if ($before = $request->query('before')) {
                $query->where('leads.last_email_at', '<', CarbonImmutable::parse($before)->utc());
            }
        } catch (\Throwable) {
        }

        // Page by id first, from the index alone, then read just those leads: sorting whole rows made a
        // brand's Unread list read every unread lead from disk (0.9s at 300k leads, 5ms this way)
        $ids = $query->orderByDesc('leads.last_email_at')->orderByDesc('leads.id')->limit(self::PAGE + 1)->pluck('leads.id');
        $leads = Lead::with(['brand:id,name', 'assignee:id,name'])->whereIn('id', $ids)
            ->get(['id', 'name', 'brand_id', 'assignee_id', 'status', 'last_email_at', 'email_unread', 'email_needs_reply'])
            ->sortBy(fn (Lead $lead) => $ids->search($lead->id))->values();
        $hasMore = $leads->count() > self::PAGE;
        $leads = $leads->take(self::PAGE);

        // The newest email of each conversation on the page, and how many it has: one grouped read on lead_id
        $stats = LeadEmail::whereIn('lead_id', $leads->pluck('id'))->groupBy('lead_id')
            ->selectRaw('lead_id, MAX(id) AS last_id, COUNT(*) AS total')->get()->keyBy('lead_id');
        // Leads whose emails were all removed (outside the app, say in phpMyAdmin) have no conversation left:
        // they leave the inbox and stop counting as unread
        $empty = $leads->pluck('id')->diff($stats->keys());
        if ($empty->isNotEmpty()) {
            Lead::clearEmailThreads($empty->all());
            $leads = $leads->reject(fn (Lead $lead) => $empty->contains($lead->id))->values();
        }
        $latest = LeadEmail::with('user:id,name')->whereIn('id', $stats->pluck('last_id'))
            ->get(['id', 'lead_id', 'user_id', 'direction', 'subject', 'body'])->keyBy('lead_id');
        $mask = ! $user->seesFullContacts();

        $threads = $leads->map(function (Lead $lead) use ($stats, $latest, $mask) {
            $email = $latest[$lead->id] ?? null;
            $snippet = $email ? Str::limit(self::withoutQuote($email->body), 160) : '';

            return [
                'lead_id' => $lead->id,
                'name' => $lead->name ?: 'Unnamed lead',
                'brand' => $lead->brand?->name,
                'assignee' => $lead->assignee?->name,
                'status' => $lead->status,
                'last_email_at' => $lead->last_email_at,
                'unread' => $lead->email_unread,
                'needs_reply' => $lead->email_needs_reply,
                'subject' => $email?->subject ?? '',
                'snippet' => $mask ? ContactMask::text($snippet) : $snippet,
                'direction' => $email?->direction,
                'sender' => $email?->user?->name,
                'count' => (int) ($stats[$lead->id]->total ?? 0),
            ];
        })->values();

        return response()->json([
            'threads' => $threads,
            'has_more' => $hasMore,
            'counts' => [
                'unread' => $this->capped(Lead::query()->visibleTo($user)->where('leads.email_unread', true)),
                'needs_reply' => $this->capped(Lead::query()->visibleTo($user)->where('leads.email_needs_reply', true)),
            ],
            'brands' => Brand::query()->visibleTo($user)->orderBy('name')->get(['id', 'name']),
            'selected' => $selected,
        ]);
    }

    /** One lead's whole conversation, oldest first; opening it as the lead's rep marks it read */
    public function show(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()->canWorkLeads(), 403);

        return response()->json($this->thread($request, $id) ?? abort(404));
    }

    private function thread(Request $request, int $id): ?array
    {
        $user = $request->user();
        $lead = Lead::query()->visibleTo($user)->with(['brand:id,name', 'assignee:id,name'])->find($id);
        if (! $lead) {
            return null;
        }
        $lead->markEmailsReadBy($user);

        $emails = $lead->emails()->with(['user:id,name', 'attachments'])->orderByDesc('sent_at')->orderByDesc('id')->limit(100)->get()
            ->reverse()->values()->map(fn (LeadEmail $e) => ContactController::emailRow($e, $user));
        if ($emails->isEmpty() && $lead->last_email_at) {
            Lead::clearEmailThreads([$lead->id]);
        }

        return [
            'lead' => [
                'id' => $lead->id,
                'name' => $lead->name ?: 'Unnamed lead',
                'email' => $user->seesFullContacts() ? $lead->email : ContactMask::email($lead->email),
                'brand' => $lead->brand?->name,
                'assignee' => $lead->assignee?->name,
                'status' => $lead->status,
                'needs_reply' => $lead->email_needs_reply,
            ],
            'emails' => $emails,
            'mailbox' => Mailbox::forSender($lead->brand_id, $user)?->address,
            'has_email' => (bool) $lead->email,
        ];
    }

    /** The reply itself, without the earlier emails mail apps quote underneath it */
    public static function withoutQuote(string $body): string
    {
        $text = preg_split('/^\s*(On\s.{0,300}?wrote:|-{2,}\s*Original Message\s*-{2,}|>)/msi', str_replace("\r\n", "\n", $body), 2)[0];

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    private function capped(Builder $query, int $cap = 1000): int
    {
        return DB::query()->fromSub($query->select('leads.id')->limit($cap), 't')->count();
    }
}
