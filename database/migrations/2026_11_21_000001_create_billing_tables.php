<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash-only billing. Everything is keyed on the customer (the converted prospect, the same id the portal
 * accounts and releases already use), so every portal account of one company sees the same subscription.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One lifetime license row and (optionally) one maintenance row per customer.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospect_id')->constrained('prospects')->cascadeOnDelete();
            $table->string('type', 20);                       // lifetime_license | maintenance
            $table->string('status', 10)->default('active');  // active | expired (kept in sync by billing:sync-subscriptions)
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('expires_at')->nullable();       // null for a lifetime license
            $table->timestamps();

            $table->unique(['prospect_id', 'type']);
            $table->index(['type', 'status', 'expires_at']);
        });

        // A request to renew maintenance. Created by the customer (pending), confirmed by staff once the cash is received.
        Schema::create('renewal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospect_id')->constrained('prospects')->cascadeOnDelete();
            $table->foreignId('portal_account_id')->nullable()->constrained('portal_accounts')->nullOnDelete();
            $table->unsignedTinyInteger('years');
            $table->unsignedBigInteger('amount_dzd');         // price snapshot (years x yearly price) at request time
            $table->string('status', 10)->default('pending'); // pending | confirmed
            $table->string('reference', 30)->unique();        // proforma slip number, e.g. PF-2026-0007
            $table->dateTime('confirmed_at')->nullable();
            $table->string('confirmed_by', 100)->nullable();
            $table->timestamps();

            $table->index(['prospect_id', 'status']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospect_id')->constrained('prospects')->cascadeOnDelete();
            $table->foreignId('renewal_request_id')->nullable()->constrained('renewal_requests')->nullOnDelete();
            $table->string('reference', 30)->unique();        // e.g. SOF-2026-9041
            $table->string('type', 20);                       // license | maintenance_renewal
            $table->unsignedBigInteger('amount_dzd');
            $table->string('status', 15)->default('pending_cash'); // paid_cash | pending_cash
            $table->dateTime('issued_at');
            $table->dateTime('paid_at')->nullable();
            $table->string('pdf_path')->nullable();           // scanned receipt / invoice, uploaded by staff
            $table->string('pdf_name')->nullable();
            $table->string('pdf_mime', 100)->nullable();
            $table->string('created_by', 100)->nullable();
            $table->timestamps();

            $table->index(['prospect_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('renewal_requests');
        Schema::dropIfExists('subscriptions');
    }
};
