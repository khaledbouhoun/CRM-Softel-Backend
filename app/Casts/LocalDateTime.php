<?php

namespace App\Casts;

use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Business date/time column stored as a naive DATETIME in the application
 * timezone (config app.timezone).
 *
 * Incoming values carrying an explicit offset (e.g. "2026-11-05T09:00:00Z")
 * are converted to the application timezone before being stored; values
 * without an offset are interpreted as application-timezone local time.
 */
class LocalDateTime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value, config('app.timezone'));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::toLocal($value)->format('Y-m-d H:i:s');
    }

    public static function toLocal(DateTimeInterface|string $value): Carbon
    {
        $date = $value instanceof DateTimeInterface
            ? Carbon::instance($value)
            : Carbon::parse($value, config('app.timezone'));

        return $date->setTimezone(config('app.timezone'));
    }
}
