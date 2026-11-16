<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PortalRequest;
use Illuminate\Http\Request;

/** @mixin PortalRequest */
class PortalRequestResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'prospect_id' => $this->prospect_id,
            'portal_account_id' => $this->portal_account_id,
            'product' => $this->product ? ['id' => $this->product->id, 'name' => $this->product->name] : null,
            'appointment_id' => $this->appointment_id,
            'company' => $this->company,
            'contact_name' => $this->contact_name,
            'job_title' => $this->job_title,
            'phone' => $this->phone,
            'email' => $this->email,
            'wilaya' => $this->wilaya,
            'city' => $this->city,
            'business_activity' => $this->business_activity,
            'notes' => $this->notes,
            'source' => $this->source,
            'customer_message' => $this->customer_message,
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
