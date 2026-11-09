<?php

namespace App\Http\Resources\Api\V1;

use DateTimeInterface;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Base resource: all date/times are ISO 8601 with the application timezone
 * offset, e.g. "2026-11-01T14:30:00+01:00".
 */
abstract class ApiResource extends JsonResource
{
    protected function iso(DateTimeInterface|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $date = $value instanceof DateTimeInterface
            ? Carbon::instance($value)
            : Carbon::parse($value, config('app.timezone'));

        return $date->setTimezone(config('app.timezone'))->toIso8601String();
    }
}
