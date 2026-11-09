<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A cPanel account the app creates mailboxes on. Its API token can manage the whole account: it never leaves the server. */
class CpanelAccount extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'domains' => 'array', 'checked_at' => 'datetime'];
    }

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class);
    }

    /** The account that hosts a domain, if any connected one does */
    public static function forDomain(string $domain): ?self
    {
        $domain = strtolower($domain);

        return self::all()->first(fn (self $account) => in_array($domain, array_map('strtolower', $account->domains ?? []), true));
    }
}
