<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CRM is the single source of truth for the marketing catalogue: per-language texts for the public website.
 * "is_active_on_web" remains the public visibility toggle; "image_path" is an uploaded asset path or a URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('title_i18n')->nullable();        // {"ar": "...", "fr": "...", "en": "..."}
            $table->json('summary_i18n')->nullable();
            $table->json('description_i18n')->nullable();
            $table->json('tech_stack_i18n')->nullable();   // {"ar": ["..."], "fr": [...], "en": [...]}
            $table->unsignedInteger('sort_order')->default(0);

            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['sort_order']);
            $table->dropColumn(['title_i18n', 'summary_i18n', 'description_i18n', 'tech_stack_i18n', 'sort_order']);
        });
    }
};
