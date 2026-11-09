<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only: every new column is nullable, so existing prospects stay valid.
 *
 *  - business_activity: stable code (e.g. "wholesale") or free text.
 *  - source / source_other: stable code (e.g. "facebook"); source_other holds
 *    the detail when source = "other".
 *  - previous_software: stable code (e.g. "sage") or free text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospects', function (Blueprint $table) {
            $table->string('business_activity', 150)->nullable()->after('city');
            $table->string('source', 50)->nullable()->after('business_activity');
            $table->string('source_other', 150)->nullable()->after('source');
            $table->string('previous_software', 150)->nullable()->after('source_other');

            $table->index('contact_person');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('prospects', function (Blueprint $table) {
            $table->dropIndex(['contact_person']);
            $table->dropIndex(['source']);
            $table->dropColumn(['business_activity', 'source', 'source_other', 'previous_software']);
        });
    }
};
