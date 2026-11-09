<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_stages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('description', 500)->nullable();
            $table->string('color', 7)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            // open | won | lost — behaviour is driven by this column, never by the stage name.
            $table->string('stage_type', 10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['sort_order', 'id']);
            $table->index('stage_type');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_stages');
    }
};
