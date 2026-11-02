<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Null = chat/offline still in the inbox only; set when converted onto the Leads page
            $table->timestamp('promoted_at')->nullable()->after('last_activity_at')->index();
        });

        // Keep existing form/manual (and already-handled) leads on the Leads page
        DB::table('leads')->whereIn('source', ['form', 'manual'])->update([
            'promoted_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('promoted_at');
        });
    }
};
