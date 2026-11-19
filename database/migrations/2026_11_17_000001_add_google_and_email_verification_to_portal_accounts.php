<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: Google Sign-In linkage and e-mail verification (6-digit OTP) for portal accounts.
 * Accounts created through Google have no local password, so the column becomes nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_accounts', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });

        Schema::table('portal_accounts', function (Blueprint $table) {
            $table->string('google_id', 191)->nullable()->unique()->after('password');
            $table->timestamp('email_verified_at')->nullable()->after('google_id');
            $table->string('email_verification_token', 64)->nullable()->after('email_verified_at');
            $table->timestamp('email_verification_expires_at')->nullable()->after('email_verification_token');
        });
    }

    public function down(): void
    {
        Schema::table('portal_accounts', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn([
                'google_id',
                'email_verified_at',
                'email_verification_token',
                'email_verification_expires_at',
            ]);
        });

        // password stays nullable on rollback: Google-only rows may exist and have no password to restore.
    }
};
