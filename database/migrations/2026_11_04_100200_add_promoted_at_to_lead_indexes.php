<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Each covering index with the columns the lists, counts and dashboard read from it */
    private const INDEXES = [
        'leads_timeline_idx' => ['created_at', 'id', 'brand_id', 'status', 'channel', 'website_id', 'assignee_id'],
        'leads_brand_timeline_idx' => ['brand_id', 'created_at', 'id', 'status', 'channel', 'website_id', 'assignee_id'],
        'leads_assignee_timeline_idx' => ['assignee_id', 'created_at', 'id', 'status', 'brand_id', 'channel', 'website_id'],
        'leads_website_timeline_idx' => ['website_id', 'created_at', 'id', 'status', 'brand_id'],
        'leads_assignee_status_brand_idx' => ['assignee_id', 'status', 'brand_id', 'channel'],
    ];

    /**
     * Every lead query filters on promoted_at. Outside these indexes, MySQL had to read the
     * row behind each index entry, which at 300k leads took the list from 0.3s to 4-26s and the
     * dashboard to over a minute. Nearly every lead is promoted, so an index of its own only
     * misled the planner. One ALTER keeps the foreign keys backed by an index throughout.
     */
    public function up(): void
    {
        $changes = ['DROP INDEX leads_promoted_at_index'];
        foreach (self::INDEXES as $name => $columns) {
            $changes[] = "DROP INDEX {$name}";
            $changes[] = "ADD INDEX {$name} (".implode(', ', [...$columns, 'promoted_at']).')';
        }
        DB::statement('ALTER TABLE leads '.implode(', ', $changes));
    }

    public function down(): void
    {
        $changes = ['ADD INDEX leads_promoted_at_index (promoted_at)'];
        foreach (self::INDEXES as $name => $columns) {
            $changes[] = "DROP INDEX {$name}";
            $changes[] = "ADD INDEX {$name} (".implode(', ', $columns).')';
        }
        DB::statement('ALTER TABLE leads '.implode(', ', $changes));
    }
};
