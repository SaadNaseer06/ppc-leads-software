<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\MailboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Mailboxes on each brand's domain: one per person, and optionally one shared by the brand. Super Admin
 * only, and the passwords stay with them: a rep who knew theirs could read clients' addresses in webmail.
 */
class MailboxController extends Controller
{
    public function __construct(private MailboxService $mail) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canManagePayments(), 403);
        $brands = Brand::orderBy('name')->get(['id', 'name']);

        return response()->json([
            'mailboxes' => Mailbox::with(['brand:id,name', 'user:id,name'])->get()
                ->sortBy(fn (Mailbox $m) => [$m->brand->name, $m->user_id ? 1 : 0, $m->user?->name])
                ->map(fn (Mailbox $m) => $this->row($m))->values(),
            'brands' => $brands,
            // Who can have a mailbox on each brand: the people who work its leads
            'people' => User::where('active', true)->whereNotIn('role', [Role::Finance->value])->with('brands:id')->orderBy('name')->get(['id', 'name', 'role'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role->label(), 'brand_ids' => $u->hasGlobalAccess() ? $brands->pluck('id') : $u->brands->pluck('id')]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->canManagePayments(), 403);
        $data = $this->validated($request, null);
        $brand = Brand::findOrFail($data['brand_id']);
        $owner = isset($data['user_id']) ? User::findOrFail($data['user_id']) : null;
        if ($owner && ! $owner->canAccessBrand($brand->id)) {
            return response()->json(['error' => "{$owner->name} doesn't work on {$brand->name}. Add them to the brand on the Team page first."], 422);
        }
        if (Mailbox::where('brand_id', $brand->id)->where('user_id', $owner?->id)->exists()) {
            return response()->json(['error' => $owner ? "{$owner->name} already has a mailbox on {$brand->name}. Edit that one instead." : "{$brand->name} already has a shared mailbox."], 422);
        }
        if (! ($data['password'] ?? null)) {
            return response()->json(['error' => "Enter the mailbox's password"], 422);
        }

        $mailbox = new Mailbox(['brand_id' => $brand->id, 'user_id' => $owner?->id]);

        return $this->save($request, $mailbox, $data, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()->canManagePayments(), 403);
        $mailbox = Mailbox::findOrFail($id);

        return $this->save($request, $mailbox, $this->validated($request, $mailbox), 200);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()->canManagePayments(), 403);
        $mailbox = Mailbox::findOrFail($id);
        $mailbox->delete();
        ActivityLog::record($request->user()->id, 'mailbox.disconnect', 'Mailbox', $id, ['address' => $mailbox->address]);

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, ?Mailbox $mailbox): array
    {
        return $request->validate([
            'brand_id' => [$mailbox ? 'prohibited' : 'required', 'integer'],
            'user_id' => [$mailbox ? 'prohibited' : 'nullable', 'integer'],
            'address' => ['required', 'email', 'max:191', Rule::unique('mailboxes', 'address')->ignore($mailbox?->id)],
            'from_name' => ['nullable', 'string', 'max:120'],
            'host' => ['required', 'string', 'max:191', 'regex:/^[a-z0-9.\-]+$/i'],
            'imap_port' => ['required', 'integer', 'in:993,143'],
            'smtp_port' => ['required', 'integer', 'in:465,587,25'],
            'username' => ['nullable', 'string', 'max:191'],
            'password' => ['nullable', 'string', 'max:255'],
        ], [
            'address.email' => 'Enter the mailbox address, like riley@yourbrand.com',
            'address.unique' => 'That address is already connected',
            'host.regex' => 'Enter just the server name, like mail.yourbrand.com',
        ]);
    }

    /** Logs in with the new details first, so a typo never replaces settings that work */
    private function save(Request $request, Mailbox $mailbox, array $data, int $status): JsonResponse
    {
        $mailbox->fill([
            'address' => strtolower($data['address']),
            'from_name' => $data['from_name'] ?? null,
            'host' => strtolower($data['host']),
            'imap_port' => $data['imap_port'],
            'smtp_port' => $data['smtp_port'],
            'username' => $data['username'] ?? null,
        ]);
        if ($data['password'] ?? null) {
            $mailbox->password = $data['password'];
        }

        if ($problem = $this->mail->problem($mailbox)) {
            return response()->json(['error' => $problem], 422);
        }
        $mailbox->save();
        try {
            $this->mail->startFromNow($mailbox);
        } catch (Throwable $e) {
            report($e);
        }
        ActivityLog::record($request->user()->id, 'mailbox.connect', 'Mailbox', $mailbox->id, ['address' => $mailbox->address]);

        return response()->json(['mailbox' => $this->row($mailbox->fresh(['brand:id,name', 'user:id,name']))], $status);
    }

    /** No password, ever */
    private function row(Mailbox $m): array
    {
        return [
            ...$m->only(['id', 'brand_id', 'user_id', 'address', 'from_name', 'host', 'imap_port', 'smtp_port', 'username', 'checked_at', 'error']),
            'brand' => $m->brand?->name,
            'owner' => $m->user?->name,
        ];
    }
}
