<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Pack;
use Illuminate\Validation\Rule;

/** Create (POST) and partial update (PUT) of a pack. */
class PackRequest extends ApiFormRequest
{
    public function rules(): array
    {
        /** @var Pack|null $pack */
        $pack = $this->route('pack');
        $required = $pack ? ['sometimes', 'required'] : ['required'];

        return [
            'name' => [...$required, 'string', 'max:150', Rule::unique('packs', 'name')->ignore($pack?->id)],
            'code' => [...$required, 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('packs', 'code')->ignore($pack?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
