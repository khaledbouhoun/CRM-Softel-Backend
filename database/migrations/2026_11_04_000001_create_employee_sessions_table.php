<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id', 50)->unique();
            $table->string('username', 100);
            $table->string('device_name', 100)->nullable();
            $table->string('ip_address', 45);
            $table->string('last_request_path', 255)->default('/api/v1/health');
            $table->timestamp('last_heartbeat_at')->useCurrent();
            $table->timestamps();

            $table->index('last_heartbeat_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_sessions');
    }
};
