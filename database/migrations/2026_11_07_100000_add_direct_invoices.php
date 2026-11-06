<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            // An invoice can be raised for someone who was never a lead
            $table->unsignedBigInteger('lead_id')->nullable()->change();
            $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();

            // Who was billed, kept on the invoice: editing a lead later must not rewrite an issued invoice
            $table->string('client_name', 191)->nullable()->after('merchant_name');
            $table->string('client_email', 191)->nullable()->after('client_name');
            $table->string('client_phone', 50)->nullable()->after('client_email');
            $table->string('client_company', 191)->nullable()->after('client_phone');
            $table->string('client_address', 500)->nullable()->after('client_company');

            // Deleted invoices stay in the books out of sight, and their numbers are never reused
            $table->softDeletes();
        });

        DB::statement('UPDATE invoices i JOIN leads l ON l.id = i.lead_id
            SET i.client_name = l.name, i.client_email = l.email, i.client_phone = l.phone, i.client_company = l.company');
    }

    public function down(): void
    {
        // lead_id can't go back to required while these exist
        DB::table('invoices')->whereNull('lead_id')->delete();

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['client_name', 'client_email', 'client_phone', 'client_company', 'client_address']);
            $table->unsignedBigInteger('lead_id')->nullable(false)->change();
            $table->foreign('lead_id')->references('id')->on('leads')->restrictOnDelete();
        });
    }
};
