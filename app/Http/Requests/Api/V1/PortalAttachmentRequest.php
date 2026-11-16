<?php

namespace App\Http\Requests\Api\V1;

class PortalAttachmentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:'.config('crm.portal.upload_max_kb'), 'mimes:'.implode(',', config('crm.portal.upload_mimes'))]];
    }
}
