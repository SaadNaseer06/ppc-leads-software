<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Services\BrandMailbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/** Each brand's shared mailbox. Super Admin only: whoever holds it reads and sends as the brand. */
class MailboxController extends Controller
{
    public function __construct(private BrandMailbox $mailbox) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canManagePayments(), 403);

        return response()->json([
            'brands' => Brand::orderBy('name')->get()->map(fn (Brand $b) => $this->row($b))->values(),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()->canManagePayments(), 403);
        $brand = Brand::findOrFail($id);
        $data = $request->validate([
            'address' => ['required', 'email', 'max:191'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'host' => ['required', 'string', 'max:191', 'regex:/^[a-z0-9.\-]+$/i'],
            'imap_port' => ['required', 'integer', 'in:993,143'],
            'smtp_port' => ['required', 'integer', 'in:465,587,25'],
            'username' => ['nullable', 'string', 'max:191'],
            'password' => ['nullable', 'string', 'max:255'],
        ], [
            'address.email' => 'Enter the mailbox address, like sales@yourbrand.com',
            'host.regex' => 'Enter just the server name, like mail.yourbrand.com',
        ]);

        $password = ($data['password'] ?? null) ?: $brand->mail_password;
        if (! $password) {
            return response()->json(['error' => "Enter the mailbox's password"], 422);
        }

        // Tried on a copy first, so a typo never replaces settings that work
        $trial = $brand->replicate()->forceFill([
            'mail_address' => strtolower($data['address']),
            'mail_from_name' => $data['from_name'] ?? null,
            'mail_host' => strtolower($data['host']),
            'mail_imap_port' => $data['imap_port'],
            'mail_smtp_port' => $data['smtp_port'],
            'mail_username' => $data['username'] ?? null,
            'mail_password' => $password,
        ]);
        if ($problem = $this->mailbox->problem($trial)) {
            return response()->json(['error' => $problem], 422);
        }

        $brand->forceFill($trial->only([
            'mail_address', 'mail_from_name', 'mail_host', 'mail_imap_port', 'mail_smtp_port', 'mail_username', 'mail_password',
        ]))->save();
        try {
            $this->mailbox->startFromNow($brand);
        } catch (Throwable $e) {
            report($e);
        }
        ActivityLog::record($request->user()->id, 'brand.mailbox_connect', 'Brand', $brand->id, ['address' => $brand->mail_address]);

        return response()->json(['brand' => $this->row($brand->fresh())]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()->canManagePayments(), 403);
        $brand = Brand::findOrFail($id);
        $brand->forceFill([
            'mail_address' => null, 'mail_from_name' => null, 'mail_host' => null, 'mail_imap_port' => null, 'mail_smtp_port' => null,
            'mail_username' => null, 'mail_password' => null, 'mail_last_uid' => null, 'mail_uid_validity' => null,
            'mail_checked_at' => null, 'mail_error' => null,
        ])->save();
        ActivityLog::record($request->user()->id, 'brand.mailbox_disconnect', 'Brand', $brand->id);

        return response()->json(['brand' => $this->row($brand)]);
    }

    /** No password, ever: only whether one is saved */
    private function row(Brand $brand): array
    {
        return [
            'id' => $brand->id,
            'name' => $brand->name,
            'connected' => $this->mailbox->configured($brand),
            'address' => $brand->mail_address,
            'from_name' => $brand->mail_from_name,
            'host' => $brand->mail_host,
            'imap_port' => $brand->mail_imap_port,
            'smtp_port' => $brand->mail_smtp_port,
            'username' => $brand->mail_username,
            'checked_at' => $brand->mail_checked_at,
            'error' => $brand->mail_error,
        ];
    }
}
