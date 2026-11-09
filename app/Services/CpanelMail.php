<?php

namespace App\Services;

use App\Models\CpanelAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Creates, resets and removes mailboxes through cPanel's own API (UAPI), so nobody has to do it by
 * hand in cPanel, and nobody ever needs to know a mailbox's password.
 */
class CpanelMail
{
    /** Mailbox size in MB unless said otherwise */
    public const DEFAULT_QUOTA = 1024;

    /** @return list<string> the mail domains the account hosts */
    public function domains(CpanelAccount $account): array
    {
        $rows = $this->call($account, 'Email', 'list_mail_domains');

        return collect($rows)->pluck('domain')->filter()->map(fn ($d) => strtolower($d))->unique()->values()->all();
    }

    public function create(CpanelAccount $account, string $address, string $password, int $quota = self::DEFAULT_QUOTA): void
    {
        [$local, $domain] = explode('@', strtolower($address), 2);
        $this->call($account, 'Email', 'add_pop', ['email' => $local, 'domain' => $domain, 'password' => $password, 'quota' => $quota]);
    }

    public function changePassword(CpanelAccount $account, string $address, string $password): void
    {
        [$local, $domain] = explode('@', strtolower($address), 2);
        $this->call($account, 'Email', 'passwd_pop', ['email' => $local, 'domain' => $domain, 'password' => $password]);
    }

    public function delete(CpanelAccount $account, string $address): void
    {
        [$local, $domain] = explode('@', strtolower($address), 2);
        $this->call($account, 'Email', 'delete_pop', ['email' => $local, 'domain' => $domain]);
    }

    /** A long random password nobody will ever type: the app is the only thing that logs in */
    public static function password(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', 'Ab'), '=').'#9a';
    }

    /** @return mixed the call's data */
    private function call(CpanelAccount $account, string $module, string $function, array $params = []): mixed
    {
        try {
            $response = Http::baseUrl("https://{$account->host}:2083")
                ->withHeaders(['Authorization' => "cpanel {$account->username}:{$account->token}"])
                ->withOptions(['verify' => (bool) config('ppc.mail_verify_certificates')])
                ->timeout(20)
                ->asForm()
                ->post("/execute/{$module}/{$function}", $params);
        } catch (ConnectionException) {
            throw new RuntimeException("Couldn't reach cPanel at {$account->host}. Check the server name.");
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new RuntimeException('cPanel turned the API token down. Check the username and token, and that the token hasn\'t expired.');
        }
        // UAPI answers {status, errors, data}, sometimes wrapped in "result"
        $result = $response->json('result') ?? $response->json();
        if (! $response->successful() || ! is_array($result) || (int) ($result['status'] ?? 0) !== 1) {
            $why = collect($result['errors'] ?? [])->filter()->implode(' ') ?: "cPanel answered {$response->status()}.";

            throw new RuntimeException("cPanel said: {$why}");
        }

        return $result['data'] ?? null;
    }
}
