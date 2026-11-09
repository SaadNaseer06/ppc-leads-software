<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every time a sales rep sees a lead's full contact details
        Schema::create('contact_reveals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 191)->nullable();
            $table->dateTime('created_at');

            $table->index(['user_id', 'created_at']);
            $table->index(['brand_id', 'created_at']);
        });

        // One shared mailbox per brand: sent from the app, replies collected over IMAP
        Schema::table('brands', function (Blueprint $table) {
            $table->string('mail_address', 191)->nullable()->after('stripe_account_name');
            $table->string('mail_from_name', 120)->nullable()->after('mail_address');
            $table->string('mail_host', 191)->nullable()->after('mail_from_name');
            $table->unsignedSmallInteger('mail_imap_port')->nullable()->after('mail_host');
            $table->unsignedSmallInteger('mail_smtp_port')->nullable()->after('mail_imap_port');
            $table->string('mail_username', 191)->nullable()->after('mail_smtp_port');
            $table->text('mail_password')->nullable()->after('mail_username');
            $table->unsignedBigInteger('mail_last_uid')->nullable()->after('mail_password');
            $table->unsignedBigInteger('mail_uid_validity')->nullable()->after('mail_last_uid');
            $table->dateTime('mail_checked_at')->nullable()->after('mail_uid_validity');
            $table->string('mail_error', 500)->nullable()->after('mail_checked_at');
        });

        Schema::create('lead_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 3);
            $table->string('from_email', 191);
            $table->string('from_name', 191)->nullable();
            $table->string('to_email', 191);
            $table->string('subject', 255);
            $table->mediumText('body');
            $table->string('message_id', 191)->nullable();
            $table->string('in_reply_to', 191)->nullable();
            $table->dateTime('sent_at');
            $table->dateTime('created_at')->nullable();

            $table->index(['lead_id', 'sent_at']);
            $table->unique(['brand_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_emails');
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn([
                'mail_address', 'mail_from_name', 'mail_host', 'mail_imap_port', 'mail_smtp_port', 'mail_username',
                'mail_password', 'mail_last_uid', 'mail_uid_validity', 'mail_checked_at', 'mail_error',
            ]);
        });
        Schema::dropIfExists('contact_reveals');
    }
};
