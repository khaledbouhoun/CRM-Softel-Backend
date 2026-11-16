<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intervention_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('source', 10); // customer | employee | both
            $table->string('color', 9)->default('#3366FF');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('intervention_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('customer_label', 120);
            $table->string('color', 9)->default('#3366FF');
            $table->boolean('is_initial')->default(false);
            $table->boolean('is_closed')->default(false);
            $table->boolean('awaits_customer')->default(false);
            $table->boolean('is_delivered')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('intervention_status_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_status_id')->constrained('intervention_statuses')->restrictOnDelete();
            $table->foreignId('to_status_id')->constrained('intervention_statuses')->restrictOnDelete();
            $table->boolean('customer_allowed')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['from_status_id', 'to_status_id']);
        });

        Schema::create('intervention_priorities', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('color', 9)->default('#3366FF');
            $table->unsignedInteger('response_target_hours')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('interventions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->string('source', 10); // customer | employee
            $table->foreignId('intervention_type_id')->constrained('intervention_types')->restrictOnDelete();
            $table->foreignId('intervention_status_id')->constrained('intervention_statuses')->restrictOnDelete();
            $table->foreignId('intervention_priority_id')->nullable()->constrained('intervention_priorities')->restrictOnDelete();
            $table->foreignId('prospect_id')->nullable()->constrained('prospects')->restrictOnDelete();
            $table->foreignId('portal_account_id')->nullable()->constrained('portal_accounts')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->string('title', 200);
            $table->text('description');
            $table->text('customer_resolution')->nullable(); // customer-visible delivered details / release notes
            $table->string('assigned_employee', 100)->nullable();
            $table->boolean('is_published')->default(false); // employee-originated work visible to affected customers
            $table->dateTime('published_at')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();

            $table->index(['intervention_status_id', 'intervention_priority_id']);
            $table->index(['prospect_id', 'source']);
            $table->index(['is_published', 'published_at']);
            $table->index('scheduled_at');
        });

        Schema::create('intervention_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intervention_id')->constrained('interventions')->cascadeOnDelete();
            $table->foreignId('prospect_id')->constrained('prospects')->restrictOnDelete();
            $table->unique(['intervention_id', 'prospect_id']);
        });

        Schema::create('intervention_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intervention_id')->constrained('interventions')->cascadeOnDelete();
            $table->string('author_type', 10); // customer | employee
            $table->string('author_name', 150)->nullable();
            $table->string('visibility', 10); // customer | internal
            $table->text('body');
            $table->foreignId('status_id')->nullable()->constrained('intervention_statuses')->restrictOnDelete();
            $table->timestamps();

            $table->index(['intervention_id', 'visibility']);
        });

        Schema::create('intervention_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intervention_id')->constrained('interventions')->cascadeOnDelete();
            $table->string('visibility', 10)->default('customer'); // customer | internal
            $table->string('original_name', 255);
            $table->string('path', 500);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intervention_attachments');
        Schema::dropIfExists('intervention_messages');
        Schema::dropIfExists('intervention_customers');
        Schema::dropIfExists('interventions');
        Schema::dropIfExists('intervention_priorities');
        Schema::dropIfExists('intervention_status_transitions');
        Schema::dropIfExists('intervention_statuses');
        Schema::dropIfExists('intervention_types');
    }
};
