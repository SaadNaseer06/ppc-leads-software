<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('lead_id')->constrained()->restrictOnDelete();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('USD');
            $table->string('status', 20)->default('draft');     // draft | sent | paid | cancelled
            $table->text('description')->nullable();
            $table->json('line_items')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'created_at']);
            $table->index(['created_by_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method', 40);
            $table->string('external_ref', 191)->nullable();
            $table->timestamp('recorded_at')->useCurrent();
            $table->text('notes')->nullable();

            $table->index('recorded_at');
        });

        // Audit trail: assignments, exports, user and website changes
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 60);
            $table->string('entity', 40);
            $table->string('entity_id', 64)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity', 'entity_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('invoices');
    }
};
