<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospect_stage_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospect_id')->constrained('prospects')->restrictOnDelete();
            // Null only for the initial entry written when the prospect is created.
            $table->foreignId('from_stage_id')->nullable()->constrained('sales_stages')->restrictOnDelete();
            $table->foreignId('to_stage_id')->constrained('sales_stages')->restrictOnDelete();
            $table->foreignId('loss_reason_id')->nullable()->constrained('loss_reasons')->restrictOnDelete();
            $table->text('loss_note')->nullable();
            $table->dateTime('changed_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['prospect_id', 'changed_at']);
            $table->index('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospect_stage_histories');
    }
};
