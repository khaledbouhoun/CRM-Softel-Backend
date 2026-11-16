<?php

namespace App\Http\Requests\Api\V1;

class ScheduleAppointmentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'prospect_id' => ['required', 'integer', 'exists:prospects,id'],
            'appointment_type_id' => ['required', 'integer', 'exists:appointment_types,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'assigned_employee' => ['nullable', 'string', 'max:100'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'meeting_url' => ['nullable', 'url:http,https', 'max:500'],
            'send_whatsapp' => ['sometimes', 'boolean'],
            'resource_url' => ['nullable', 'url:http,https', 'max:500'],
            'occurrences' => ['nullable', 'integer', 'min:1', 'max:52'],
            'interval_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ];
    }
}
