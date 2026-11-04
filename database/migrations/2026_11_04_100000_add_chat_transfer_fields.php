<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A chat handed to a Front Sales rep: assigned_agent_id holds the rep. The note is
        // internal, so it lives here and never among the messages the visitor can read.
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('transferred_by_id')->nullable()->after('assigned_agent_id')->constrained('users')->nullOnDelete();
            $table->dateTime('transferred_at')->nullable()->after('transferred_by_id');
            $table->string('transfer_note', 500)->nullable()->after('transferred_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transferred_by_id');
            $table->dropColumn(['transferred_at', 'transfer_note']);
        });
    }
};
