<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // cPanel accounts the app may create mailboxes on. Brand domains can be spread over several.
        Schema::create('cpanel_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('host', 191);
            $table->string('username', 64);
            $table->text('token');
            // The mail domains the account hosts, as cPanel last reported them
            $table->json('domains')->nullable();
            $table->dateTime('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['host', 'username']);
        });

        Schema::table('mailboxes', function (Blueprint $table) {
            // Set when the app created the mailbox in cPanel, so it can also reset or delete it there
            $table->foreignId('cpanel_account_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cpanel_account_id');
        });
        Schema::dropIfExists('cpanel_accounts');
    }
};
