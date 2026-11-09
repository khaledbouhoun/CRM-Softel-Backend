<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planned type of the follow-up (call | appointment | message). Nullable:
 * follow-ups created before this migration have no type and keep none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_up_tasks', function (Blueprint $table) {
            $table->string('activity_type', 20)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('follow_up_tasks', function (Blueprint $table) {
            $table->dropColumn('activity_type');
        });
    }
};
