<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Key/value business configuration; defaults live in config/crm.php ("scheduling" block). */
class CrmSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public static function read(string $key): mixed
    {
        $row = static::query()->where('key', $key)->first();

        return $row ? $row->value : config("crm.scheduling.{$key}");
    }

    public static function write(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** @return array<string, mixed> */
    public static function allEffective(): array
    {
        $effective = config('crm.scheduling', []);

        foreach (static::query()->get() as $row) {
            if (array_key_exists($row->key, $effective)) {
                $effective[$row->key] = $row->value;
            }
        }

        return $effective;
    }
}
