<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ProspectFormOption;
use Illuminate\Validation\Rule;

class ReorderProspectFormOptionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(ProspectFormOption::TYPES)],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ];
    }
}
