<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each lead's email conversation, kept on the lead so the Emails inbox and its sidebar badge are one
 * indexed read on `leads` instead of a scan of every email: when it last moved, whether the lead's rep
 * has read the client's latest email, and whether the client is waiting for an answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dateTime('last_email_at')->nullable();
            $table->boolean('email_unread')->default(false);
            $table->boolean('email_needs_reply')->default(false);

            // Newest conversations first: across all brands, one brand, or one rep's leads
            $table->index('last_email_at');
            $table->index(['brand_id', 'last_email_at']);
            $table->index(['assignee_id', 'last_email_at']);
            // The badge and the Unread / Needs reply filters, answered from the index alone: every column
            // they test is in it, so no lead row is read (the badge is polled every few seconds)
            $table->index(['email_unread', 'brand_id', 'assignee_id', 'last_email_at'], 'leads_email_unread_idx');
            $table->index(['email_needs_reply', 'brand_id', 'assignee_id', 'last_email_at'], 'leads_email_needs_reply_idx');
        });

        // Conversations from before this change: their order and whether the client spoke last
        DB::table('lead_emails')->selectRaw('lead_id, MAX(id) AS last_id, MAX(COALESCE(created_at, sent_at)) AS at')
            ->groupBy('lead_id')->orderBy('lead_id')
            ->chunk(500, function ($rows) {
                $inbound = DB::table('lead_emails')->whereIn('id', $rows->pluck('last_id'))->where('direction', 'in')->pluck('id')->flip();
                foreach ($rows as $row) {
                    DB::table('leads')->where('id', $row->lead_id)->update([
                        'last_email_at' => $row->at,
                        'email_needs_reply' => isset($inbound[$row->last_id]),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['last_email_at']);
            $table->dropIndex(['brand_id', 'last_email_at']);
            $table->dropIndex(['assignee_id', 'last_email_at']);
            $table->dropIndex('leads_email_unread_idx');
            $table->dropIndex('leads_email_needs_reply_idx');
            $table->dropColumn(['last_email_at', 'email_unread', 'email_needs_reply']);
        });
    }
};
