<?php

namespace App\Http\Requests\Api\V1;

/** Employee reply: text and/or files (multipart: files[]). Internal notes stay hidden from the customer. */
class InterventionMessageRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'visibility' => ['required', 'in:customer,internal'],
            'body' => ['required_without:files', 'nullable', 'string', 'max:10000'],
            'files' => ['sometimes', 'array', 'max:'.config('crm.uploads.max_files_per_reply')],
            'files.*' => [
                'file', 'max:'.config('crm.uploads.staff_attachment_max_kb'),
                'extensions:'.implode(',', config('crm.uploads.staff_attachment_extensions')),
            ],
        ];
    }
}
