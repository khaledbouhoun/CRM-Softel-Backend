<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_sessions', function (Blueprint $table) {
            $table->string('custom_name', 120)->nullable(); // e.g. "Bureau Commercial 1"
            $table->string('role', 50)->default('sales'); // sales, support, management, training
            $table->string('presence_status', 30)->default('available'); // available, busy, away, break
            $table->text('status_note')->nullable();
        });

        // Audit labels (X-Employee-Name) so "today" productivity can be attributed per employee.
        Schema::table('activities', function (Blueprint $table) {
            $table->string('created_by', 100)->nullable();
            $table->index(['created_by', 'created_at']);
        });

        Schema::table('follow_up_tasks', function (Blueprint $table) {
            $table->string('completed_by', 100)->nullable();
            $table->index(['completed_by', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('follow_up_tasks', function (Blueprint $table) {
            $table->dropIndex(['completed_by', 'completed_at']);
            $table->dropColumn('completed_by');
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndex(['created_by', 'created_at']);
            $table->dropColumn('created_by');
        });

        Schema::table('employee_sessions', function (Blueprint $table) {
            $table->dropColumn(['custom_name', 'role', 'presence_status', 'status_note']);
        });
    }
};
