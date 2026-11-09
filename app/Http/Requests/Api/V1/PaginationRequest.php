<?php

namespace App\Http\Requests\Api\V1;

/** List endpoints that only support per_page, page and direction. */
class PaginationRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return $this->paginationRules();
    }
}
