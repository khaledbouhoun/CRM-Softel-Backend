<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Appointment;
use App\Services\WhatsAppLinkBuilder;
use Illuminate\Http\Request;

/**
 * Staff view. Requires type, prospect and product to be eager loaded.
 *
 * @mixin Appointment
 */
class AppointmentResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => 'appointment',
            'title' => $this->title,
            'status' => $this->status,
            'is_hold_expired' => $this->isHoldExpired(),
            'starts_at' => $this->iso($this->starts_at),
            'ends_at' => $this->iso($this->ends_at),
            'hold_expires_at' => $this->iso($this->hold_expires_at),
            'type' => ['id' => $this->type->id, 'code' => $this->type->code, 'name' => $this->type->name, 'group' => $this->type->group, 'color' => $this->type->color],
            'prospect' => ['id' => $this->prospect->id, 'name' => $this->prospect->name, 'contact_person' => $this->prospect->contact_person, 'phone' => $this->prospect->phone, 'is_customer' => $this->prospect->isCustomer()],
            'product' => $this->product ? ['id' => $this->product->id, 'name' => $this->product->name] : null,
            'portal_account_id' => $this->portal_account_id,
            'assigned_employee' => $this->assigned_employee,
            'meeting_url' => $this->meeting_url,
            'internal_notes' => $this->internal_notes,
            'customer_message' => $this->customer_message,
            'outcome' => $this->outcome,
            'activity_id' => $this->activity_id,
            'series_id' => $this->series_id,
            'training_kind' => $this->training_kind,
            'send_whatsapp' => $this->send_whatsapp,
            'resource_url' => $this->resource_url,
            'whatsapp' => app(WhatsAppLinkBuilder::class)->forAppointment($this->resource),
            'decided_by' => $this->decided_by,
            'decided_at' => $this->iso($this->decided_at),
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
