<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\ContactReveal;
use App\Models\Lead;
use App\Models\LeadEmail;
use App\Models\LeadEmailAttachment;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\ContactMask;
use App\Services\ContactReveals;
use App\Services\EmailHtml;
use App\Services\MailboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/** Seeing a lead's contact details, emailing the lead, and the log managers keep of both */
class ContactController extends Controller
{
    public function reveal(Request $request, int $id, ContactReveals $reveals): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canWorkLeads(), 403);
        $lead = Lead::query()->visibleTo($user)->findOrFail($id);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:191']]);

        return response()->json($reveals->reveal($user, $lead, $data['reason'] ?? null));
    }

    /** Emails the lead from the sender's own mailbox on the brand. The address is filled in here, so reps never need to see it. */
    public function sendEmail(Request $request, int $id, MailboxService $mail): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canWorkLeads(), 403);
        $lead = Lead::query()->visibleTo($user)->with('brand')->findOrFail($id);
        $max = LeadEmailAttachment::maxBytes();
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            // Formatted (from the editor) or plain text (the API)
            'html' => ['nullable', 'string', 'max:200000'],
            'body' => ['nullable', 'string', 'max:20000'],
            'reply_to_id' => ['nullable', 'integer'],
            'files' => ['nullable', 'array', 'max:'.LeadEmailAttachment::MAX_FILES],
            'files.*' => ['file', 'max:'.intdiv($max, 1024)],
        ], [
            'subject.required' => 'Add a subject',
            'files.max' => 'Attach up to '.LeadEmailAttachment::MAX_FILES.' files',
            'files.*.max' => 'Attachments can be up to '.Number::fileSize($max).' in total',
            'files.*.file' => "A file couldn't be uploaded. Try attaching it again.",
        ]);

        $html = isset($data['html']) ? EmailHtml::clean($data['html']) : null;
        $body = $html !== null ? EmailHtml::text($html) : trim($data['body'] ?? '');
        if ($html !== null && $body === '') {
            $html = null;
        }
        $files = $request->file('files', []);
        if ($body === '' && ! $files) {
            return response()->json(['error' => 'Write a message'], 422);
        }
        if (mb_strlen($body) > 20000) {
            return response()->json(['error' => 'The message is too long'], 422);
        }
        foreach ($files as $file) {
            if (LeadEmailAttachment::blocked($file->getClientOriginalName())) {
                return response()->json(['error' => "{$file->getClientOriginalName()} can't be sent: mail providers block this kind of file. Zip it first, or share a link."], 422);
            }
        }
        if (array_sum(array_map(fn ($f) => $f->getSize(), $files)) > $max) {
            return response()->json(['error' => 'Attachments can be up to '.Number::fileSize($max).' in total. Share bigger files as a link.'], 422);
        }

        if (! $lead->email) {
            return response()->json(['error' => 'This lead has no email address.'], 422);
        }
        $mailbox = Mailbox::forSender($lead->brand_id, $user);
        if (! $mailbox) {
            return response()->json(['error' => "You don't have a mailbox on {$lead->brand->name} yet. A Super Admin can connect one on the Mailboxes page."], 422);
        }
        $inReplyTo = isset($data['reply_to_id'])
            ? LeadEmail::where('lead_id', $lead->id)->whereKey($data['reply_to_id'])->value('message_id')
            : null;

        $attachments = array_map(fn ($file) => [
            'path' => LeadEmailAttachment::store($file->get()),
            'name' => LeadEmailAttachment::cleanName($file->getClientOriginalName()),
            'mime' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => (int) $file->getSize(),
        ], $files);

        try {
            $email = $mail->send($mailbox, $lead->email, $lead->name, $lead, $user, $data['subject'], $body, $inReplyTo, $html, $attachments);
        } catch (TransportExceptionInterface $e) {
            report($e);
            Storage::disk(LeadEmailAttachment::DISK)->delete(array_column($attachments, 'path'));

            return response()->json(['error' => "The email couldn't be sent. The mailbox may need reconnecting on the Mailboxes page."], 503);
        }

        return response()->json(['email' => self::emailRow($email->load('attachments'), $user)], 201);
    }

    /** The log managers check: who saw which client's details, and who hit the daily limit */
    public function log(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canViewTeam(), 403);
        $brands = Brand::query()->visibleTo($user)->pluck('id');
        $tz = config('ppc.report_timezone');
        $today = now($tz)->startOfDay()->utc();
        $week = now($tz)->subDays(6)->startOfDay()->utc();

        $counts = ContactReveal::whereIn('brand_id', $brands)->where('created_at', '>=', $week)
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(DISTINCT lead_id) AS week, COUNT(DISTINCT CASE WHEN created_at >= ? THEN lead_id END) AS today', [$today])
            ->get()->keyBy('user_id');
        $limitHits = DB::table('activity_logs')->where('action', 'contact.reveal_limit')->where('created_at', '>=', $week)
            ->whereIn('actor_id', $counts->keys())->groupBy('actor_id')->selectRaw('actor_id, COUNT(*) AS n')->pluck('n', 'actor_id');
        $names = User::whereIn('id', $counts->keys())->get(['id', 'name', 'role'])->keyBy('id');

        return response()->json([
            'limit' => (int) config('ppc.contact_reveal_daily_limit'),
            'people' => $counts->map(fn ($row) => [
                'id' => (int) $row->user_id,
                'name' => $names->get($row->user_id)?->name ?? 'A former user',
                'role' => $names->get($row->user_id)?->role->label(),
                'today' => (int) $row->today,
                'week' => (int) $row->week,
                'limit_hits' => (int) ($limitHits[$row->user_id] ?? 0),
            ])->sortByDesc('week')->values(),
            'recent' => ContactReveal::whereIn('brand_id', $brands)->with(['user:id,name', 'lead:id,name'])
                ->latest('id')->limit(100)->get()
                ->map(fn (ContactReveal $r) => [
                    'id' => $r->id,
                    'user' => $r->user?->name,
                    'lead' => $r->lead?->only(['id', 'name']),
                    'reason' => $r->reason,
                    'at' => $r->created_at,
                ]),
        ]);
    }

    /** @return array<string, mixed> one email on the lead page, with the client's address masked for reps */
    public static function emailRow(LeadEmail $email, User $viewer): array
    {
        $client = $email->direction === 'in' ? 'from_email' : 'to_email';
        $row = $email->only(['id', 'direction', 'from_email', 'from_name', 'to_email', 'subject', 'body', 'sent_at']);
        $row['html'] = $email->body_html;
        if (! $viewer->seesFullContacts()) {
            $row[$client] = ContactMask::email($row[$client]);
            $row['body'] = ContactMask::text($row['body']);
            // The cleaner writes @ as an entity; it's put back so addresses in the text are masked too
            $row['html'] = ContactMask::text($row['html'] !== null ? str_replace('&#64;', '@', $row['html']) : null);
        }

        return [
            ...$row,
            'sender' => $email->user?->name,
            'attachments' => $email->relationLoaded('attachments') ? $email->attachments->map->toRow()->values()->all() : [],
        ];
    }
}
