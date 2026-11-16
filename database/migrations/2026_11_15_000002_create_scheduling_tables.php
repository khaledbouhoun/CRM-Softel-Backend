<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('group', 20)->default('meeting'); // demo | meeting | training | accompaniment
            $table->unsignedSmallInteger('default_duration_minutes')->default(60);
            $table->string('color', 9)->default('#3366FF');
            $table->boolean('customer_bookable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('appointment_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_type_id')->constrained('appointment_types')->restrictOnDelete();
            $table->foreignId('prospect_id')->constrained('prospects')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->unsignedSmallInteger('occurrences');
            $table->unsignedSmallInteger('interval_days')->default(7);
            $table->string('created_by', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_type_id')->constrained('appointment_types')->restrictOnDelete();
            $table->foreignId('prospect_id')->constrained('prospects')->restrictOnDelete();
            $table->foreignId('portal_account_id')->nullable()->constrained('portal_accounts')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('series_id')->nullable()->constrained('appointment_series')->restrictOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained('activities')->nullOnDelete();
            $table->string('title', 200)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            // held | proposed | confirmed | rejected | cancelled | expired | completed | no_show
            $table->string('status', 20);
            $table->dateTime('hold_expires_at')->nullable();
            // Unique while the appointment occupies a customer-bookable slot lane; NULL otherwise.
            $table->string('slot_key', 40)->nullable()->unique();
            $table->string('assigned_employee', 100)->nullable();
            $table->string('meeting_url', 500)->nullable();
            $table->text('internal_notes')->nullable();
            $table->text('customer_message')->nullable();
            $table->text('outcome')->nullable();
            $table->string('training_kind', 20)->nullable(); // first | follow_up
            $table->boolean('send_whatsapp')->default(false);
            $table->string('resource_url', 500)->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->string('decided_by', 100)->nullable();
            $table->timestamps();

            $table->index(['starts_at', 'ends_at']);
            $table->index(['status', 'hold_expires_at']);
            $table->index(['prospect_id', 'status']);
            $table->index('assigned_employee');
        });

        Schema::create('portal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_account_id')->constrained('portal_accounts')->restrictOnDelete();
            $table->foreignId('prospect_id')->constrained('prospects')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->string('type', 20); // demo | quotation | trial
            $table->string('status', 20)->default('pending'); // pending | scheduled | handled | rejected | cancelled
            $table->string('company', 150);
            $table->string('contact_name', 150);
            $table->string('job_title', 150)->nullable();
            $table->string('phone', 30);
            $table->string('email', 191);
            $table->string('wilaya', 100);
            $table->string('city', 100);
            $table->string('business_activity', 150)->nullable();
            $table->text('notes')->nullable();
            $table->string('source', 50)->default('website');
            $table->text('customer_message')->nullable();
            $table->timestamps();

            $table->index(['portal_account_id', 'status']);
            $table->index(['status', 'type']);
        });

        Schema::create('trials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospect_id')->constrained('prospects')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('url', 500);
            $table->dateTime('revoked_at')->nullable();
            $table->string('created_by', 100)->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamps();

            $table->index(['prospect_id', 'product_id']);
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trials');
        Schema::dropIfExists('portal_requests');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('appointment_series');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('appointment_types');
    }
};
