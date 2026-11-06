<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * A brand's own domain for invoice links. Links only switch to it once this app has been
 * reached through it, so connecting DNS can never send a client to a dead page.
 */
class PayDomain
{
    /** "https://Pay.Brand.com/x" becomes "pay.brand.com"; null if it isn't a real domain name */
    public function normalize(string $input): ?string
    {
        $host = strtolower(trim($input));
        $host = preg_replace('#^[a-z]+://#', '', $host);
        $host = rtrim(strtok($host, '/?#') ?: '', '.');

        $valid = preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $host) === 1 && strlen($host) <= 191;

        return $valid ? $host : null;
    }

    /** What this app answers on a domain, which no other server could produce */
    public function proof(string $host): string
    {
        return hash_hmac('sha256', strtolower($host), (string) config('app.key'));
    }

    /** @return string|null why the domain isn't ready, or null when it reaches this app */
    public function problem(string $domain): ?string
    {
        try {
            $response = Http::timeout(8)->withoutRedirecting()->get("https://{$domain}/pay-domain-check");
        } catch (ConnectionException) {
            return "We couldn't reach {$domain} over HTTPS. DNS changes can take a few hours, and the SSL certificate has to be issued too.";
        }

        return $response->successful() && hash_equals($this->proof($domain), trim($response->body()))
            ? null
            : "{$domain} is online but doesn't point to this app yet. Check its DNS record and that it's added in cPanel.";
    }
}
