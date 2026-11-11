<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospect_form_options', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50); // source, previous_software, business_activity, profession
            $table->string('name', 150);
            $table->boolean('requires_details')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'name']);
            $table->index(['type', 'sort_order']);
            $table->index(['type', 'is_active']);
        });

        Schema::table('prospects', function (Blueprint $table) {
            $table->string('profession', 150)->nullable()->after('business_activity');
            $table->string('profession_details', 255)->nullable()->after('profession');
            $table->string('source_details', 255)->nullable()->after('source');
            $table->string('previous_software_details', 255)->nullable()->after('previous_software');
            $table->string('business_activity_details', 255)->nullable()->after('business_activity');
        });

        // Safely migrate existing source_other data to source_details before retiring source_other
        DB::table('prospects')
            ->whereNotNull('source_other')
            ->whereNull('source_details')
            ->update(['source_details' => DB::raw('source_other')]);
    }

    public function down(): void
    {
        Schema::table('prospects', function (Blueprint $table) {
            $table->dropColumn([
                'profession',
                'profession_details',
                'source_details',
                'previous_software_details',
                'business_activity_details',
            ]);
        });

        Schema::dropIfExists('prospect_form_options');
    }
};
