<?php

return [

    // IANA timezone for "per day" dashboard buckets. Keep in sync with
    // APP_TIMEZONE in frontend/.env so charts and dates agree.
    'report_timezone' => env('REPORT_TIMEZONE', 'UTC'),

    // Fallback Tawk.to webhook secret; per-website secrets take priority.
    'tawk_webhook_secret' => env('TAWK_WEBHOOK_SECRET'),

    // A repeat submission from the same email/phone within this many days
    // updates the existing lead instead of creating a new one.
    'dedupe_window_days' => 30,

    // Lists stop counting here, so huge result sets never cost a full scan.
    'count_cap' => 10_000,
    'search_count_cap' => 1_000,

    // A chat with no chat:end event after this long is treated as finished.
    'active_chat_hours' => 3,
];
