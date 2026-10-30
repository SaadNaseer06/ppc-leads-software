<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('visitor_token', 64)->nullable()->after('external_id');
            $table->foreignId('assigned_agent_id')->nullable()->after('lead_id')->constrained('users')->nullOnDelete();
            $table->index(['website_id', 'visitor_token']);
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->boolean('chat_enabled')->default(true)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['website_id', 'visitor_token']);
            $table->dropConstrainedForeignId('assigned_agent_id');
            $table->dropColumn('visitor_token');
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('chat_enabled');
        });
    }
};
