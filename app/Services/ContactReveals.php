<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ContactReveal;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;

/** A sales rep asking to see a lead's full contact details: allowed, logged, and limited per day */
class ContactReveals
{
    /** @return array{email: ?string, phone: ?string} */
    public function reveal(User $user, Lead $lead, ?string $reason): array
    {
        $contact = ['email' => $lead->email, 'phone' => $lead->phone];
        if ($user->seesFullContacts()) {
            return $contact;
        }

        $since = now(config('ppc.report_timezone'))->startOfDay()->utc();
        $today = ContactReveal::where('user_id', $user->id)->where('created_at', '>=', $since);
        // Seeing the same lead again the same day costs nothing more
        if (! (clone $today)->where('lead_id', $lead->id)->exists()) {
            $limit = (int) config('ppc.contact_reveal_daily_limit');
            if ((clone $today)->distinct()->count('lead_id') >= $limit) {
                ActivityLog::record($user->id, 'contact.reveal_limit', 'Lead', $lead->id, ['limit' => $limit]);
                throw new HttpResponseException(response()->json([
                    'error' => "You've seen the contact details of {$limit} leads today, the daily limit. Ask a manager if you need more.",
                ], 429));
            }
        }

        ContactReveal::create([
            'user_id' => $user->id,
            'lead_id' => $lead->id,
            'brand_id' => $lead->brand_id,
            'reason' => $reason ? mb_substr(trim($reason), 0, 191) : null,
            'created_at' => now(),
        ]);

        return $contact;
    }
}
