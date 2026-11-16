<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only. A "customer" is a prospect with converted_at set: the prospect row,
 * its activities, follow-ups and stage history are kept untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospects', function (Blueprint $table) {
            $table->dateTime('converted_at')->nullable()->after('loss_note');
            $table->string('converted_by', 100)->nullable()->after('converted_at');
            $table->text('conversion_note')->nullable()->after('converted_by');
            $table->index('converted_at');
        });

        Schema::table('prospect_products', function (Blueprint $table) {
            $table->dateTime('purchased_at')->nullable();
            $table->index('purchased_at');
        });

        Schema::create('portal_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospect_id')->nullable()->constrained('prospects')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('email', 191)->unique();
            $table->string('phone', 30)->nullable();
            $table->string('password');
            $table->string('locale', 5)->default('fr');
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_login_at')->nullable();
            $table->timestamps();

            $table->index('prospect_id');
        });

        Schema::create('portal_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_account_id')->constrained('portal_accounts')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('portal_password_resets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_account_id')->constrained('portal_accounts')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60);
            $table->string('locale', 5);
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['code', 'locale']);
        });

        Schema::create('product_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('title', 200);
            $table->string('kind', 20)->default('pdf'); // pdf | video | link | other
            $table->string('url', 500);
            $table->boolean('customer_visible')->default(true);
            $table->boolean('is_training_material')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_resources');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('crm_settings');
        Schema::dropIfExists('portal_password_resets');
        Schema::dropIfExists('portal_tokens');
        Schema::dropIfExists('portal_accounts');

        Schema::table('prospect_products', function (Blueprint $table) {
            $table->dropIndex(['purchased_at']);
            $table->dropColumn('purchased_at');
        });

        Schema::table('prospects', function (Blueprint $table) {
            $table->dropIndex(['converted_at']);
            $table->dropColumn(['converted_at', 'converted_by', 'conversion_note']);
        });
    }
};
