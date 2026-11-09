<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('follow_up_tasks', function (Blueprint $table) {
      $table->id();
      $table->foreignId('prospect_id')->constrained('prospects')->restrictOnDelete();
      $table->string('title', 200);
      $table->text('description')->nullable();
      $table->dateTime('scheduled_at');
      // pending | completed | cancelled
      $table->string('status', 20)->default('pending');
      $table->dateTime('completed_at')->nullable();
      $table->dateTime('cancelled_at')->nullable();
      $table->text('closing_note')->nullable();
      $table->timestamps();

      $table->index('prospect_id');
      $table->index('scheduled_at');
      $table->index('status');
      $table->index(['status', 'scheduled_at']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('follow_up_tasks');
  }
};
