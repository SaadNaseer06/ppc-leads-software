<?php

return [

    // IANA timezone for "per day" dashboard buckets. Keep in sync with
    // APP_TIMEZONE in frontend/.env so charts and dates agree.
    'report_timezone' => env('REPORT_TIMEZONE', 'UTC'),

    // Leads whose full contact details each sales rep can see per day (counted per lead, from midnight)
    'contact_reveal_daily_limit' => (int) env('CONTACT_REVEAL_DAILY_LIMIT', 30),

    // Check mail servers' TLS certificates. Only turn off for a mail host whose certificate doesn't match its name.
    'mail_verify_certificates' => (bool) env('MAIL_VERIFY_CERTIFICATES', true),

    // Shown on the client's invoice page, e.g. bank details. Use \n for new lines.
    'payment_instructions' => str_replace('\n', "\n", (string) env('PAYMENT_INSTRUCTIONS', '')),

    // Fallback Tawk.to webhook secret; per-website secrets take priority.
    'tawk_webhook_secret' => env('TAWK_WEBHOOK_SECRET'),

    // A repeat submission from the same email/phone within this many days
    // updates the existing lead instead of creating a new one.
    'dedupe_window_days' => 30,

    // Lists stop counting here, so huge result sets never cost a full scan.
    'count_cap' => 10_000,
    'search_count_cap' => 1_000,

    // A chat with no message for this long is no longer "live"; native chats are
    // then closed by the scheduled `chats:close-idle` command.
    'active_chat_hours' => 3,
];
