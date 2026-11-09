<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Databases that ran the first version of 2026_11_10_100000 keep one shared mailbox on each brand.
 * This brings them to mailboxes per person: the mailboxes table, any brand mailbox moved into it as
 * that brand's shared one, and the old brand columns dropped. Every step checks first, so databases
 * already on the new layout are left as they are.
 */
return new class extends Migration
{
    private const BRAND_COLUMNS = [
        'mail_address', 'mail_from_name', 'mail_host', 'mail_imap_port', 'mail_smtp_port', 'mail_username',
        'mail_password', 'mail_last_uid', 'mail_uid_validity', 'mail_checked_at', 'mail_error',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('mailboxes')) {
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
        }

        if (Schema::hasColumn('brands', 'mail_address')) {
            // A mailbox already connected on a brand becomes its shared one. The password is copied as
            // stored: it was encrypted with the same key the new column decrypts with.
            DB::table('brands')->whereNotNull('mail_address')->whereNotNull('mail_host')->whereNotNull('mail_password')
                ->orderBy('id')->get()
                ->reject(fn ($brand) => DB::table('mailboxes')->where('address', $brand->mail_address)->exists())
                ->each(fn ($brand) => DB::table('mailboxes')->insert([
                    'brand_id' => $brand->id,
                    'user_id' => null,
                    'address' => $brand->mail_address,
                    'from_name' => $brand->mail_from_name,
                    'host' => $brand->mail_host,
                    'imap_port' => $brand->mail_imap_port ?: 993,
                    'smtp_port' => $brand->mail_smtp_port ?: 465,
                    'username' => $brand->mail_username,
                    'password' => $brand->mail_password,
                    'last_uid' => $brand->mail_last_uid,
                    'uid_validity' => $brand->mail_uid_validity,
                    'checked_at' => $brand->mail_checked_at,
                    'error' => $brand->mail_error,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));

            Schema::table('brands', function (Blueprint $table) {
                $table->dropColumn(self::BRAND_COLUMNS);
            });
        }

        if (Schema::hasTable('lead_emails') && ! Schema::hasColumn('lead_emails', 'mailbox_id')) {
            Schema::table('lead_emails', function (Blueprint $table) {
                $table->foreignId('mailbox_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            });
        }
    }

    /** Nothing to undo: 2026_11_10_100000 owns these tables and drops them when it is rolled back */
    public function down(): void {}
};
