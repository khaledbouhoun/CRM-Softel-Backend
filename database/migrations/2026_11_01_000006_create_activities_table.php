<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            // Restrict: activities are the communication history and must outlive any delete attempt.
            $table->foreignId('prospect_id')->constrained('prospects')->restrictOnDelete();
            $table->string('type', 20);
            $table->text('description');
            $table->text('outcome')->nullable();
            $table->dateTime('activity_at');
            $table->timestamps();

            $table->index(['prospect_id', 'activity_at']);
            $table->index('activity_at');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
