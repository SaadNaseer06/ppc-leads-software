<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A sales rep's list is scoped by brand AND assignee: with brand_id in this index,
        // their status/channel-filtered counts are answered from the index instead of row reads
        Schema::table('leads', function (Blueprint $table) {
            $table->index(['assignee_id', 'status', 'brand_id', 'channel'], 'leads_assignee_status_brand_idx');
        });
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['assignee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->index(['assignee_id', 'status']);
        });
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_assignee_status_brand_idx');
        });
    }
};
