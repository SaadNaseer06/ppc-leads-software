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

        // Mailboxes on a brand's domain: one per person (riley@brand.com), and optionally one shared by the brand (sales@)
        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            // Null for the brand's shared mailbox
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('address', 191);
            $table->string('from_name', 120)->nullable();
            $table->string('host', 191);
            $table->unsignedSmallInteger('imap_port');
            $table->unsignedSmallInteger('smtp_port');
            $table->string('username', 191)->nullable();
            $table->text('password');
            $table->unsignedBigInteger('last_uid')->nullable();
            $table->unsignedBigInteger('uid_validity')->nullable();
            $table->dateTime('checked_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->unique('address');
            $table->index(['brand_id', 'user_id']);
        });

        Schema::create('lead_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mailbox_id')->nullable()->constrained()->nullOnDelete();
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
        Schema::dropIfExists('mailboxes');
        Schema::dropIfExists('contact_reveals');
    }
};
