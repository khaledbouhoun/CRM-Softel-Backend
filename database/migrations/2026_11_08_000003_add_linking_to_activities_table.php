<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an activity exist before it is linked to a prospect and records where
 * it came from. Existing rows are untouched: they keep their prospect_id and
 * simply have no follow_up_task_id / title / linked_at.
 *
 *  - prospect_id becomes nullable (native, not yet linked activities).
 *  - follow_up_task_id: the follow-up this activity was converted from.
 *    UNIQUE, so one follow-up can never produce two activities.
 *  - title: optional short summary.
 *  - linked_at: when the activity was last (re)linked to a prospect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->unsignedBigInteger('prospect_id')->nullable()->change();

            $table->string('title', 200)->nullable()->after('type');
            $table->dateTime('linked_at')->nullable()->after('activity_at');
            $table->foreignId('follow_up_task_id')->nullable()->after('prospect_id')
                ->unique()->constrained('follow_up_tasks')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Unlinked activities cannot satisfy NOT NULL again; keep them attached
        // to nothing rather than deleting history, so only refuse when present.
        if (\Illuminate\Support\Facades\DB::table('activities')->whereNull('prospect_id')->exists()) {
            throw new RuntimeException('Cannot roll back: unlinked activities exist. Link them to a prospect first.');
        }

        Schema::table('activities', function (Blueprint $table) {
            $table->dropForeign(['follow_up_task_id']);
            $table->dropUnique(['follow_up_task_id']);
            $table->dropColumn(['follow_up_task_id', 'title', 'linked_at']);
            $table->unsignedBigInteger('prospect_id')->nullable(false)->change();
        });
    }
};
