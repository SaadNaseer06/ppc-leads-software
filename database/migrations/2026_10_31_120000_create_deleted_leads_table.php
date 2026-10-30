<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deleted_leads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_lead_id')->index();
            $table->unsignedBigInteger('brand_id')->nullable()->index();
            $table->unsignedBigInteger('website_id')->nullable()->index();
            $table->string('name', 191);
            $table->string('email', 191)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('company', 191)->nullable();
            $table->string('source', 20)->nullable();
            $table->string('channel', 20)->nullable();
            $table->string('status', 20)->nullable();
            $table->unsignedBigInteger('assignee_id')->nullable();
            /** Full lead row + related snapshots at delete time */
            $table->json('payload');
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deleted_at')->useCurrent();

            $table->index(['deleted_at', 'id']);
            $table->index(['brand_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deleted_leads');
    }
};
