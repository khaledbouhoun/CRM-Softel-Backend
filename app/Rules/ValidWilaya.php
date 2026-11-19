<?php

namespace App\Rules;

use App\Support\Wilayas;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidWilaya implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || Wilayas::resolve($value) === null) {
            $fail('Please choose one of the 58 wilayas of Algeria.');
        }
    }
}
