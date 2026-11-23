<?php

namespace App\Http\Requests\Api\V1;

class PortalMessageRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            // A reply may be text, a file, or both.
            'body' => ['required_without:file', 'nullable', 'string', 'max:10000'],
            'file' => ['nullable', 'file', 'max:'.config('crm.portal.upload_max_kb'), 'mimes:'.implode(',', config('crm.portal.upload_mimes'))],
        ];
    }
}
