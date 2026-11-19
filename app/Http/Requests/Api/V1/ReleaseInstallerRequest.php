<?php

namespace App\Http\Requests\Api\V1;

class ReleaseInstallerRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'installer' => ['required', 'file', 'max:'.config('crm.uploads.installer_max_kb'), 'extensions:'.implode(',', config('crm.uploads.installer_extensions'))],
        ];
    }
}
