<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A Tawk.to live chat or offline message (ticket)
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->default('tawk');
            $table->string('external_id', 191);
            $table->string('kind', 20);                         // chat | ticket
            $table->string('property_id', 64)->nullable();
            $table->string('property_name', 191)->nullable();
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('visitor_name', 191)->nullable();
            $table->string('visitor_email', 191)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('country', 8)->nullable();
            $table->text('page_url')->nullable();
            $table->string('subject', 255)->nullable();
            $table->text('last_message')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->string('status', 20)->default('open');     // open | ended
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->dateTime('last_message_at');
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index('last_message_at');
            $table->index(['brand_id', 'last_message_at']);
            $table->index(['property_id', 'website_id']);
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('sender_type', 20);                  // visitor | agent | system
            $table->string('sender_name', 191)->nullable();
            $table->text('body');
            $table->dateTime('sent_at');

            $table->index(['conversation_id', 'sent_at']);
        });

        // Raw inbound webhook log: idempotency for retries and integration debugging
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('event_id', 191)->nullable();
            $table->string('event', 60);
            $table->string('property_id', 64)->nullable();
            $table->string('status', 20);                       // processed | ignored | rejected | failed
            $table->text('error')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['provider', 'event_id']);
            $table->index('created_at');
            $table->index(['property_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
    }
};
