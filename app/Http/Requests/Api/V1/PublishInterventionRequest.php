<?php

namespace App\Http\Requests\Api\V1;

class PublishInterventionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return ['customer_resolution' => ['required', 'string', 'max:10000']];
    }
}
