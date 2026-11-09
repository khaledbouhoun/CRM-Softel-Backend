<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\StageType;
use App\Models\SalesStage;
use Illuminate\Validation\Rule;

/** Create (POST) and partial update (PUT) of a sales stage. */
class SalesStageRequest extends ApiFormRequest
{
    public function rules(): array
    {
        /** @var SalesStage|null $stage */
        $stage = $this->route('salesStage');
        $required = $stage ? ['sometimes', 'required'] : ['required'];

        return [
            'name' => [...$required, 'string', 'max:100', Rule::unique('sales_stages', 'name')->ignore($stage?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'stage_type' => [...$required, Rule::enum(StageType::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'color.regex' => 'The color must be a hex value like #1E88E5.',
            'stage_type.enum' => 'The stage type must be one of: '.implode(', ', StageType::values()).'.',
        ];
    }
}
