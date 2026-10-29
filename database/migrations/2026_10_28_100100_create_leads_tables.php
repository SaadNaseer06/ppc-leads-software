<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id');
            $table->unsignedBigInteger('website_id')->nullable();

            $table->string('name', 191);
            $table->string('email', 191)->nullable();
            $table->string('phone', 50)->nullable();
            // Last 10 digits of the phone number, used for duplicate detection
            $table->string('phone_key', 20)->nullable();
            $table->string('company', 191)->nullable();
            $table->text('message')->nullable();

            $table->string('source', 20);                       // form | chat | offline | manual
            $table->string('channel', 20)->default('unknown');  // ppc | organic | social | referral | direct | unknown
            $table->string('status', 20)->default('new');       // new | contacted | qualified | converted | lost | spam

            $table->unsignedBigInteger('assignee_id')->nullable();
            $table->timestamp('assigned_at')->nullable();

            // PPC attribution
            $table->string('utm_source', 191)->nullable();
            $table->string('utm_medium', 191)->nullable();
            $table->string('utm_campaign', 191)->nullable();
            $table->string('utm_term', 191)->nullable();
            $table->string('utm_content', 191)->nullable();
            $table->string('click_id', 255)->nullable();
            $table->string('click_id_type', 20)->nullable();    // gclid | gbraid | wbraid | msclkid | fbclid ...
            $table->text('landing_page')->nullable();
            $table->text('page_url')->nullable();
            $table->text('referrer')->nullable();
            $table->string('form_name', 191)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->json('metadata')->nullable();               // extra submitted fields
            $table->unsignedInteger('submission_count')->default(1);
            $table->timestamp('last_activity_at')->useCurrent();
            $table->text('search_text');                        // tokens for the FULLTEXT index
            $table->timestamps();

            // Covering indexes: each starts with the column a view scopes by (nothing,
            // brand, assignee), then (created_at, id) so lists page in index order with
            // no sort, then every column the lists, counts and dashboard filter or group
            // on. Those queries are answered from the index and never read row data.
            $table->index(['created_at', 'id', 'brand_id', 'status', 'channel', 'website_id', 'assignee_id'], 'leads_timeline_idx');
            $table->index(['brand_id', 'created_at', 'id', 'status', 'channel', 'website_id', 'assignee_id'], 'leads_brand_timeline_idx');
            $table->index(['assignee_id', 'created_at', 'id', 'status', 'brand_id', 'channel', 'website_id'], 'leads_assignee_timeline_idx');
            $table->index(['website_id', 'created_at', 'id', 'status', 'brand_id'], 'leads_website_timeline_idx');
            $table->index(['assignee_id', 'status']);
            $table->index(['brand_id', 'email']);
            $table->index(['brand_id', 'phone_key']);
            $table->fullText('search_text');

            $table->foreign('brand_id')->references('id')->on('brands')->restrictOnDelete();
            $table->foreign('website_id')->references('id')->on('websites')->nullOnDelete();
            $table->foreign('assignee_id')->references('id')->on('users')->nullOnDelete();
        });

        // Timeline entry: created, resubmitted, assigned, unassigned, status, note, updated
        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30);
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['lead_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('leads');
    }
};
