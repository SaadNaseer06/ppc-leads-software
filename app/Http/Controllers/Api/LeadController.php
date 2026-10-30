<?php

namespace App\Http\Controllers\Api;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeadRowResource;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use App\Models\Website;
use App\Services\LeadAssignment;
use App\Services\LeadIntake;
use App\Services\LeadQuery;
use App\Services\TeamDirectory;
use App\Support\LeadText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadController extends Controller
{
    public function __construct(private LeadQuery $leads, private LeadAssignment $assignment) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = $this->leads->filters($request->query());
        $page = $this->leads->page($user, $filters, $request->query('cursor'), (string) $request->query('dir', 'next'), (int) $request->query('size', 50));
        $total = $this->leads->count($user, $filters);

        return response()->json([
            'leads' => LeadRowResource::collection($page['rows']),
            'next_cursor' => $page['next_cursor'],
            'prev_cursor' => $page['prev_cursor'],
            'total' => $total['count'],
            'total_capped' => $total['capped'],
            'filters' => (object) $filters,
        ]);
    }

    public function store(Request $request, LeadIntake $intake): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canCreateLeads(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:191'],
            'message' => ['nullable', 'string', 'max:20000'],
            'website_id' => ['required', 'integer'],
            'assignee_id' => ['nullable', 'integer'],
        ], ['website_id.required' => 'Choose a website']);

        $website = Website::find($data['website_id']);
        abort_if(! $website || ! $user->canAccessBrand($website->brand_id), 404);

        $result = $intake->ingest($website->brand_id, $website, LeadSource::Manual, [
            ...$data,
            'actor_id' => $user->id,
            // Sales users keep the leads they log themselves; admins may pick anyone
            'assignee_id' => $user->canAssignLeads() ? ($data['assignee_id'] ?? null) : $user->id,
            'dedupe' => false,
        ]);

        return response()->json(['lead_id' => $result['lead_id']], 201);
    }

    public function show(Request $request, int $id, TeamDirectory $team): JsonResponse
    {
        $user = $request->user();
        $lead = Lead::query()->visibleTo($user)
            ->with(['brand:id,name', 'website:id,name,domain', 'assignee:id,name,email'])
            ->findOrFail($id);

        $activities = $lead->activities()->with('actor:id,name')->latest('created_at')->latest('id')->limit(100)->get();
        $userIds = $activities->flatMap(fn (LeadActivity $a) => [$a->data['to_user_id'] ?? null, $a->data['from_user_id'] ?? null])->filter()->unique();
        $names = User::whereIn('id', $userIds)->pluck('name', 'id');

        $conversations = $lead->conversations()
            ->with(['messages' => fn ($q) => $q->orderByDesc('sent_at')->orderByDesc('id')->limit(300)])
            ->latest('started_at')->limit(5)->get();

        return response()->json([
            'lead' => [
                ...$lead->only([
                    'id', 'name', 'email', 'phone', 'company', 'message', 'source', 'channel', 'status',
                    'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'click_id', 'click_id_type',
                    'landing_page', 'page_url', 'referrer', 'form_name', 'ip_address', 'submission_count',
                    'assigned_at', 'last_activity_at', 'created_at',
                ]),
                'fields' => $lead->metadata['fields'] ?? (object) [],
                'device' => LeadText::device($lead->user_agent),
                'brand' => $lead->brand,
                'website' => $lead->website,
                'assignee' => $lead->assignee,
            ],
            'activities' => $activities->map(fn (LeadActivity $a) => [
                'id' => $a->id,
                'type' => $a->type,
                'body' => $a->body,
                'data' => $a->data ?? (object) [],
                'actor' => $a->actor?->name,
                'to_user' => isset($a->data['to_user_id']) ? ($names[$a->data['to_user_id']] ?? 'a former user') : null,
                'from_user' => isset($a->data['from_user_id']) ? ($names[$a->data['from_user_id']] ?? 'a former user') : null,
                'created_at' => $a->created_at,
            ]),
            'conversations' => $conversations->map(fn ($c) => [
                ...$c->only(['id', 'kind', 'status', 'subject', 'started_at']),
                'messages' => $c->messages->reverse()->values()->map->only(['id', 'sender_type', 'sender_name', 'body', 'sent_at']),
            ]),
            'invoices' => $user->canViewInvoices()
                ? $lead->invoices()->latest()->limit(20)->get(['id', 'number', 'amount', 'currency', 'status', 'created_at'])
                : [],
            'assignees' => $team->assignable($user, $lead->brand_id),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canWorkLeads(), 403);
        $lead = Lead::query()->visibleTo($user)->findOrFail($id);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(LeadStatus::values())],
            'handoff_user_id' => ['sometimes', 'nullable', 'integer'],
            'assignee_id' => ['sometimes', 'nullable', 'integer'],
            'name' => ['sometimes', 'string', 'min:1', 'max:191'],
            'email' => ['sometimes', 'nullable', 'email', 'max:191'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'company' => ['sometimes', 'nullable', 'string', 'max:191'],
        ]);

        if (array_key_exists('assignee_id', $data)) {
            abort_unless($user->canAssignLeads(), 403);
            $this->assignment->assign([$lead->id], $data['assignee_id'], $user->id);
        }
        if (isset($data['status'])) {
            $this->assignment->changeStatus($lead->refresh(), LeadStatus::from($data['status']), $user->id, $data['handoff_user_id'] ?? null);
        }

        $contact = array_intersect_key($data, array_flip(['name', 'email', 'phone', 'company']));
        if ($contact) {
            $lead->refresh()->fill([
                ...$contact,
                'email' => array_key_exists('email', $contact) ? LeadText::email($contact['email']) : $lead->email,
            ]);
            $lead->fill([
                'phone_key' => LeadText::phoneKey($lead->phone),
                'search_text' => LeadText::searchText($lead->name, $lead->email, $lead->phone, $lead->company),
            ]);
            DB::transaction(function () use ($lead, $user, $contact) {
                $lead->save();
                $lead->activities()->create(['actor_id' => $user->id, 'type' => 'updated', 'data' => ['fields' => array_keys($contact)]]);
            });
        }

        return response()->json(['ok' => true]);
    }

    public function addNote(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canWorkLeads(), 403);
        $lead = Lead::query()->visibleTo($user)->findOrFail($id);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']], ['body.required' => 'Write a note first']);

        $note = DB::transaction(function () use ($lead, $user, $data) {
            $lead->update(['last_activity_at' => now()]);

            return $lead->activities()->create(['actor_id' => $user->id, 'type' => 'note', 'body' => trim($data['body'])]);
        });

        return response()->json(['id' => $note->id], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canDeleteLeads(), 403);
        $lead = Lead::query()->visibleTo($user)->findOrFail($id);

        if ($lead->invoices()->exists()) {
            return response()->json([
                'error' => 'This lead has invoices. Remove or reassign those invoices before deleting the lead.',
            ], 422);
        }

        $name = $lead->name;
        DB::transaction(function () use ($lead) {
            // Conversations keep their history; lead_id is nullOnDelete
            $lead->delete();
        });
        ActivityLog::record($user->id, 'lead.delete', 'Lead', $id, ['name' => $name]);

        return response()->json(['ok' => true]);
    }

    public function bulk(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canWorkLeads(), 403);
        $data = $request->validate([
            'action' => ['required', Rule::in(['assign', 'status'])],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'assignee_id' => ['nullable', 'integer'],
            'status' => ['required_if:action,status', Rule::in(LeadStatus::values())],
        ]);

        // Only act on leads this user can actually see
        $ids = Lead::query()->visibleTo($user)->whereIn('id', $data['ids'])->pluck('id')->all();
        if ($ids === []) {
            return response()->json(['error' => 'None of the selected leads are available'], 404);
        }

        if ($data['action'] === 'assign') {
            abort_unless($user->canAssignLeads(), 403);

            return response()->json(['updated' => $this->assignment->assign($ids, $data['assignee_id'] ?? null, $user->id, 'bulk')]);
        }

        return response()->json(['updated' => $this->assignment->bulkStatus($ids, LeadStatus::from($data['status']), $user->id)]);
    }

    /** Streams the filtered leads as CSV in keyset batches: memory stays flat at any size */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->canAssignLeads(), 403);
        $filters = $this->leads->filters($request->query());
        ActivityLog::record($user->id, 'lead.export', 'Lead', null, $filters);

        $columns = [
            'Created (UTC)' => fn (Lead $l) => $l->created_at?->toIso8601String(),
            'Name' => 'name', 'Email' => 'email', 'Phone' => 'phone', 'Company' => 'company',
            'Status' => 'status', 'Source' => 'source', 'Channel' => 'channel',
            'Website' => fn (Lead $l) => $l->website?->name,
            'Brand' => fn (Lead $l) => $l->brand?->name,
            'Assignee' => fn (Lead $l) => $l->assignee?->name,
            'UTM Source' => 'utm_source', 'UTM Medium' => 'utm_medium', 'UTM Campaign' => 'utm_campaign',
            'UTM Term' => 'utm_term', 'UTM Content' => 'utm_content', 'Click ID' => 'click_id',
            'Click ID Type' => 'click_id_type', 'Landing Page' => 'landing_page', 'Referrer' => 'referrer',
            'Submissions' => 'submission_count', 'Message' => 'message',
        ];

        return response()->streamDownload(function () use ($user, $filters, $columns) {
            // Hundreds of thousands of rows take minutes; shared hosting's max_execution_time
            // would otherwise cut the file off silently part-way
            @set_time_limit(0);
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_keys($columns));

            $cursor = null;
            do {
                $q = $this->leads->query($user, $filters)->with(['website:id,name', 'brand:id,name', 'assignee:id,name']);
                if ($cursor) {
                    [$t, $id] = $cursor;
                    $q->where(fn ($w) => $w->where('leads.created_at', '<', $t)
                        ->orWhere(fn ($e) => $e->where('leads.created_at', $t)->where('leads.id', '<', $id)));
                }
                $rows = $q->orderByDesc('leads.created_at')->orderByDesc('leads.id')->limit(2000)->get();

                foreach ($rows as $lead) {
                    fputcsv($out, array_map(function ($get) use ($lead) {
                        $value = (string) (is_string($get) ? $lead->{$get} : $get($lead));

                        // Neutralise spreadsheet formula injection
                        return preg_match('/^[=+\-@\t\r]/', $value) ? "'{$value}" : $value;
                    }, $columns));
                }
                $last = $rows->last();
                $cursor = $last ? [$last->created_at, $last->id] : null;
                flush();
            } while ($rows->count() === 2000);

            fclose($out);
        }, 'leads-'.now(config('ppc.report_timezone'))->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }
}
