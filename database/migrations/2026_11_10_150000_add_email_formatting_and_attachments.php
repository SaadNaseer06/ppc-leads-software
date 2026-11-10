<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Emails with formatting (the cleaned HTML beside the plain text) and files sent either way */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_emails', function (Blueprint $table) {
            $table->mediumText('body_html')->nullable()->after('body');
        });

        Schema::create('lead_email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_email_id')->constrained()->cascadeOnDelete();
            $table->string('name', 191);
            $table->string('mime', 127);
            $table->unsignedInteger('size');
            // On the private disk, never under public/: files are only handed out after a permission check
            $table->string('path', 255);
            $table->dateTime('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_email_attachments');
        Schema::table('lead_emails', function (Blueprint $table) {
            $table->dropColumn('body_html');
        });
    }
};
