<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            Schema::table('activities', function (Blueprint $table) {
                $table->dropUnique(['follow_up_task_id']);
                $table->index('follow_up_task_id');
            });
        } else {
            Schema::table('activities', function (Blueprint $table) {
                $table->dropForeign(['follow_up_task_id']);
                $table->dropUnique(['follow_up_task_id']);
                $table->index('follow_up_task_id');
                $table->foreign('follow_up_task_id')->references('id')->on('follow_up_tasks')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            Schema::table('activities', function (Blueprint $table) {
                $table->dropIndex(['follow_up_task_id']);
                $table->unique('follow_up_task_id');
            });
        } else {
            Schema::table('activities', function (Blueprint $table) {
                $table->dropForeign(['follow_up_task_id']);
                $table->dropIndex(['follow_up_task_id']);
                $table->unique('follow_up_task_id');
                $table->foreign('follow_up_task_id')->references('id')->on('follow_up_tasks')->restrictOnDelete();
            });
        }
    }
};
