<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Align existing activity types to Call, Meeting, Message, Other
        DB::table('activities')->where('type', 'appointment')->update(['type' => 'meeting']);
        DB::table('activities')->where('type', 'note')->update(['type' => 'other']);

        DB::table('follow_up_tasks')->where('activity_type', 'appointment')->update(['activity_type' => 'meeting']);
    }

    public function down(): void
    {
        // Safe reversible no-op
    }
};
