<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // amount stays the invoice total; these are how it was reached
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('subtotal', 12, 2)->default(0)->after('amount');
            $table->decimal('discount', 12, 2)->default(0)->after('subtotal');
            $table->decimal('tax_rate', 5, 2)->default(0)->after('discount');
            $table->decimal('tax_amount', 12, 2)->default(0)->after('tax_rate');
            $table->date('due_date')->nullable()->after('status');
            // The unguessable part of the client's payment link
            $table->string('pay_token', 64)->nullable()->unique()->after('due_date');
        });

        // Existing invoices get a subtotal and a payment link too
        DB::table('invoices')->update(['subtotal' => DB::raw('amount')]);
        DB::table('invoices')->whereNull('pay_token')->orderBy('id')->chunkById(500, function ($invoices) {
            foreach ($invoices as $invoice) {
                DB::table('invoices')->where('id', $invoice->id)->update(['pay_token' => Str::random(40)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['pay_token']);
            $table->dropColumn(['subtotal', 'discount', 'tax_rate', 'tax_amount', 'due_date', 'pay_token']);
        });
    }
};
