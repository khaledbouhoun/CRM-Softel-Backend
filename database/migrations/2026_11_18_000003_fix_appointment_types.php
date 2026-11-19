<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Appointment types become a fixed list: demo, meeting, training, accompaniment.
 * Custom types created before are folded into the fixed type of the same group.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fixed = [
            ['demo', 'Demo', 60, '#009EE0', true, 1],
            ['meeting', 'Meeting', 60, '#7C3AED', false, 2],
            ['training', 'Training', 120, '#16A34A', false, 3],
            ['accompaniment', 'Accompaniment', 120, '#F59E0B', false, 4],
        ];

        $ids = [];
        foreach ($fixed as [$code, $name, $minutes, $color, $bookable, $order]) {
            $existing = DB::table('appointment_types')->where('code', $code)->first();

            if ($existing) {
                DB::table('appointment_types')->where('id', $existing->id)->update(['name' => $name, 'group' => $code, 'is_active' => true, 'updated_at' => now()]);
                $ids[$code] = $existing->id;
            } else {
                $ids[$code] = DB::table('appointment_types')->insertGetId([
                    'code' => $code, 'name' => $name, 'group' => $code, 'default_duration_minutes' => $minutes, 'color' => $color,
                    'customer_bookable' => $bookable, 'is_active' => true, 'sort_order' => $order, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        foreach (DB::table('appointment_types')->whereNotIn('code', array_keys($ids))->get() as $custom) {
            $target = $ids[$custom->group] ?? $ids['meeting'];
            DB::table('appointments')->where('appointment_type_id', $custom->id)->update(['appointment_type_id' => $target]);
            DB::table('appointment_series')->where('appointment_type_id', $custom->id)->update(['appointment_type_id' => $target]);
            DB::table('appointment_types')->where('id', $custom->id)->delete();
        }
    }

    public function down(): void
    {
        // Folded custom types cannot be restored.
    }
};
