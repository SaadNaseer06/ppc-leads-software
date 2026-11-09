<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\CpanelAccount;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\CpanelMail;
use App\Services\MailboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
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
            'cpanel_accounts' => CpanelAccount::orderBy('host')->get()->map(fn (CpanelAccount $a) => $this->accountRow($a))->values(),
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
        if ($problem = $this->ownerProblem($brand, $owner)) {
            return response()->json(['error' => $problem], 422);
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

    /** Disconnects it from the app. A mailbox the app created can also be deleted in cPanel, which loses its emails. */
    public function destroy(Request $request, int $id, CpanelMail $cpanel): JsonResponse
    {
        abort_unless($request->user()->canManagePayments(), 403);
        $mailbox = Mailbox::with('cpanelAccount')->findOrFail($id);
        $alsoInCpanel = $request->boolean('delete_from_cpanel') && $mailbox->cpanelAccount;

        if ($alsoInCpanel) {
            try {
                $cpanel->delete($mailbox->cpanelAccount, $mailbox->address);
            } catch (RuntimeException $e) {
                return response()->json(['error' => "It's still connected: {$e->getMessage()}"], 422);
            }
        }
        $mailbox->delete();
        ActivityLog::record($request->user()->id, $alsoInCpanel ? 'mailbox.delete' : 'mailbox.disconnect', 'Mailbox', $id, ['address' => $mailbox->address]);

        return response()->json(['ok' => true]);
    }

    /** Creates the mailbox in cPanel with a password nobody sees, and connects it */
    public function createInCpanel(Request $request, CpanelMail $cpanel): JsonResponse
    {
        abort_unless($request->user()->canManagePayments(), 403);
        $data = $request->validate([
            'brand_id' => ['required', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'local_part' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]([a-z0-9._-]*[a-z0-9])?$/i'],
            'domain' => ['required', 'string', 'max:191'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'quota' => ['nullable', 'integer', 'min:50', 'max:51200'],
        ], ['local_part.regex' => 'Use letters, numbers, dots, dashes or underscores, like riley or riley.chen']);

        $brand = Brand::findOrFail($data['brand_id']);
        $owner = isset($data['user_id']) ? User::findOrFail($data['user_id']) : null;
        if ($problem = $this->ownerProblem($brand, $owner)) {
            return response()->json(['error' => $problem], 422);
        }
        $account = CpanelAccount::forDomain($data['domain']);
        if (! $account) {
            return response()->json(['error' => "None of the connected cPanel accounts hosts {$data['domain']}."], 422);
        }
        $address = strtolower("{$data['local_part']}@{$data['domain']}");
        if (Mailbox::where('address', $address)->exists()) {
            return response()->json(['error' => "{$address} is already connected."], 422);
        }

        $password = CpanelMail::password();
        try {
            $cpanel->create($account, $address, $password, $data['quota'] ?? CpanelMail::DEFAULT_QUOTA);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        ActivityLog::record($request->user()->id, 'mailbox.create', 'Mailbox', null, ['address' => $address, 'cpanel' => $account->host]);

        // cPanel's own server handles the mail, and its certificate covers that name
        $mailbox = new Mailbox([
            'brand_id' => $brand->id, 'user_id' => $owner?->id, 'cpanel_account_id' => $account->id, 'address' => $address,
            'from_name' => $data['from_name'] ?? null, 'host' => $account->host, 'imap_port' => 993, 'smtp_port' => 465, 'password' => $password,
        ]);
        // A brand-new mailbox can take a moment to accept logins: it's kept and retried rather than lost
        $problem = $this->mail->problem($mailbox);
        $mailbox->error = $problem;
        $mailbox->save();
        if (! $problem) {
            rescue(fn () => $this->mail->startFromNow($mailbox));
        }

        return response()->json(['mailbox' => $this->row($mailbox->fresh(['brand:id,name', 'user:id,name'])), 'warning' => $problem], 201);
    }

    /** A new password in cPanel and the app at once, for when one may have got out */
    public function resetPassword(Request $request, int $id, CpanelMail $cpanel): JsonResponse
    {
        abort_unless($request->user()->canManagePayments(), 403);
        $mailbox = Mailbox::with('cpanelAccount')->findOrFail($id);
        abort_unless($mailbox->cpanelAccount, 422, 'Only mailboxes created from the app can have their password reset here.');

        $password = CpanelMail::password();
        try {
            $cpanel->changePassword($mailbox->cpanelAccount, $mailbox->address, $password);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $mailbox->forceFill(['password' => $password, 'error' => null])->save();
        ActivityLog::record($request->user()->id, 'mailbox.reset_password', 'Mailbox', $mailbox->id, ['address' => $mailbox->address]);

        return response()->json(['ok' => true]);
    }

    /** Connects a cPanel account by reading which mail domains it hosts: that proves the token works */
    public function storeAccount(Request $request, CpanelMail $cpanel): JsonResponse
    {
        abort_unless($request->user()->canManagePayments(), 403);
        $data = $request->validate([
            'host' => ['required', 'string', 'max:191', 'regex:/^[a-z0-9.\-]+$/i'],
            'username' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_.\-]+$/i'],
            'token' => ['required', 'string', 'max:255'],
        ], ['host.regex' => 'Enter just the server name, like server1.yourhost.com']);

        $account = CpanelAccount::firstOrNew(['host' => strtolower($data['host']), 'username' => $data['username']]);
        $account->token = trim($data['token']);
        try {
            $domains = $cpanel->domains($account);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $account->fill(['domains' => $domains, 'checked_at' => now()])->save();
        ActivityLog::record($request->user()->id, 'cpanel.connect', 'CpanelAccount', $account->id, ['host' => $account->host, 'domains' => count($domains)]);

        return response()->json(['account' => $this->accountRow($account)], 201);
    }

    /** Reads the domains again, after one is added in cPanel */
    public function refreshAccount(Request $request, int $id, CpanelMail $cpanel): JsonResponse
    {
        abort_unless($request->user()->canManagePayments(), 403);
        $account = CpanelAccount::findOrFail($id);
        try {
            $account->fill(['domains' => $cpanel->domains($account), 'checked_at' => now()])->save();
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['account' => $this->accountRow($account)]);
    }

    /** Forgets the token. Mailboxes it created stay connected, but can no longer be reset or deleted from here. */
    public function destroyAccount(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()->canManagePayments(), 403);
        $account = CpanelAccount::findOrFail($id);
        $account->delete();
        ActivityLog::record($request->user()->id, 'cpanel.disconnect', 'CpanelAccount', $id, ['host' => $account->host]);

        return response()->json(['ok' => true]);
    }

    private function ownerProblem(Brand $brand, ?User $owner): ?string
    {
        if ($owner && ! $owner->canAccessBrand($brand->id)) {
            return "{$owner->name} doesn't work on {$brand->name}. Add them to the brand on the Team page first.";
        }
        if (Mailbox::where('brand_id', $brand->id)->where('user_id', $owner?->id)->exists()) {
            return $owner ? "{$owner->name} already has a mailbox on {$brand->name}. Edit that one instead." : "{$brand->name} already has a shared mailbox.";
        }

        return null;
    }

    /** No token, ever */
    private function accountRow(CpanelAccount $account): array
    {
        return [...$account->only(['id', 'host', 'username', 'checked_at']), 'domains' => $account->domains ?? []];
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
            // Created by the app in cPanel: its password is known only to the app, and it can be reset or deleted from here
            'managed' => $m->cpanel_account_id !== null,
            'brand' => $m->brand?->name,
            'owner' => $m->user?->name,
        ];
    }
}
