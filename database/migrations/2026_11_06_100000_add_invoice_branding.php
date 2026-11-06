<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->string('merchant_name', 120)->nullable()->after('slug');
            $table->string('brand_color', 7)->nullable()->after('merchant_name');
            $table->string('logo_path', 191)->nullable()->after('brand_color');
            $table->string('billing_email', 191)->nullable()->after('logo_path');
            $table->string('billing_address', 500)->nullable()->after('billing_email');
            $table->string('pay_domain', 191)->nullable()->unique()->after('billing_address');
            $table->timestamp('pay_domain_verified_at')->nullable()->after('pay_domain');
        });

        Schema::table('invoices', function (Blueprint $table) {
            // Kept on the invoice: renaming the brand later must not change what the client was billed by
            $table->string('merchant_name', 120)->nullable()->after('brand_id');
            $table->string('stripe_intent_id', 64)->nullable()->after('pay_token');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['merchant_name', 'stripe_intent_id']);
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->dropUnique(['pay_domain']);
            $table->dropColumn(['merchant_name', 'brand_color', 'logo_path', 'billing_email', 'billing_address', 'pay_domain', 'pay_domain_verified_at']);
        });
    }
};
