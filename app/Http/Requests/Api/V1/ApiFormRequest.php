<?php

namespace App\Http\Requests\Api\V1;

use App\Casts\LocalDateTime;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Base request. There is no authentication in this CRM, so authorize() is
 * always true; network access control is done by RestrictToAllowedNetworks.
 */
abstract class ApiFormRequest extends FormRequest
{
    public const PHONE_REGEX = '/^\+?[0-9][0-9 .\-()\/]{4,28}$/';

    public function authorize(): bool
    {
        return true;
    }

    /** Shared rules for list endpoints. */
    protected function paginationRules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('crm.pagination.max_per_page')],
            'page' => ['sometimes', 'integer', 'min:1'],
            'direction' => ['sometimes', 'in:asc,desc'],
        ];
    }

    /**
     * Date/time must not be earlier than the start of today (application timezone).
     */
    protected function notBeforeToday(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (($date = $this->parseDate($value)) && $date->lt(now()->startOfDay())) {
                $fail("The {$attribute} cannot be earlier than today.");
            }
        };
    }

    /**
     * Date/time must not be later than the end of today (application timezone).
     */
    protected function notAfterToday(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (($date = $this->parseDate($value)) && $date->gt(now()->endOfDay())) {
                $fail("The {$attribute} cannot be in the future.");
            }
        };
    }

    private function parseDate(mixed $value): ?Carbon
    {
        try {
            return is_string($value) ? LocalDateTime::toLocal($value) : null;
        } catch (Throwable) {
            return null; // the "date" rule reports the format error
        }
    }
}
