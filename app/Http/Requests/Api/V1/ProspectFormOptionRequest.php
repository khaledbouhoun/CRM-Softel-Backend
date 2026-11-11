<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ProspectFormOption;
use Illuminate\Validation\Rule;

class ProspectFormOptionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $option = $this->route('prospect_form_option');
        $id = $option?->id;
        $type = $this->input('type', $option?->type);

        return [
            'type' => [
                $this->isMethod('POST') ? 'required' : 'sometimes',
                'string',
                Rule::in(ProspectFormOption::TYPES),
            ],
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('prospect_form_options', 'name')
                    ->where('type', $type)
                    ->ignore($id),
            ],
            'requires_details' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
