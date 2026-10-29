<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('slug', 120)->unique();
            $table->boolean('auto_assign_enabled')->default(false);
            // Round-robin position; the brand row is locked (FOR UPDATE) while advancing it
            $table->foreignId('round_robin_last_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('brand_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'brand_id']);
            $table->index('brand_id');
        });

        // A website the client runs PPC traffic to. Leads arrive through its public
        // key (embed script / form webhook) and through its linked Tawk.to property.
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('domain', 191);
            $table->string('public_key', 40)->unique();
            $table->string('tawk_property_id', 64)->nullable()->unique();
            $table->text('tawk_webhook_secret')->nullable(); // encrypted at rest (model cast)
            $table->boolean('active')->default(true);
            $table->timestamp('last_lead_at')->nullable();
            $table->timestamp('last_chat_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('websites');
        Schema::dropIfExists('brand_user');
        Schema::dropIfExists('brands');
    }
};
