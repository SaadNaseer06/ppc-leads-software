<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // "Live" chat counts (polled by every open dashboard) and the idle-chat
            // cleanup read only open conversations by recency, not the whole table
            $table->index(['status', 'last_message_at']);
            // Every chat widget request finds its conversation by visitor token alone
            $table->index('visitor_token');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['status', 'last_message_at']);
            $table->dropIndex(['visitor_token']);
        });
    }
};
