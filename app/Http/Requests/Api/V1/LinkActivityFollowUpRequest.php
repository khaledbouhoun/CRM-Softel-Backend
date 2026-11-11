<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class LinkActivityFollowUpRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'follow_up_id' => ['required_without:follow_up_task_id', 'nullable', 'integer', Rule::exists('follow_up_tasks', 'id')],
            'follow_up_task_id' => ['required_without:follow_up_id', 'nullable', 'integer', Rule::exists('follow_up_tasks', 'id')],
        ];
    }

    public function followUpId(): int
    {
        return (int) ($this->input('follow_up_id') ?? $this->input('follow_up_task_id'));
    }
}
