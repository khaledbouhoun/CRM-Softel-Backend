<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Intervention;
use Illuminate\Http\Request;

/**
 * Staff view (includes internal notes). Eager load: type, status, priority,
 * prospect, product, customers, messages (+ attachments on show).
 *
 * @mixin Intervention
 */
class InterventionResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'source' => $this->source,
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type,
            'status' => $this->status,
            'priority' => $this->priority,
            'prospect' => $this->prospect ? ['id' => $this->prospect->id, 'name' => $this->prospect->name] : null,
            'product' => $this->product ? ['id' => $this->product->id, 'name' => $this->product->name] : null,
            'customers' => $this->whenLoaded('customers', fn () => $this->customers->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all()),
            'assigned_employee' => $this->assigned_employee,
            'is_published' => $this->is_published,
            'published_at' => $this->iso($this->published_at),
            'customer_resolution' => $this->customer_resolution,
            'scheduled_at' => $this->iso($this->scheduled_at),
            'closed_at' => $this->iso($this->closed_at),
            'messages' => $this->whenLoaded('messages', fn () => $this->messages->map(fn ($m) => [
                'id' => $m->id, 'author_type' => $m->author_type, 'author_name' => $m->author_name,
                'visibility' => $m->visibility, 'body' => $m->body, 'status_id' => $m->status_id,
                'created_at' => $this->iso($m->created_at),
            ])->all()),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($a) => [
                'id' => $a->id, 'name' => $a->original_name, 'mime' => $a->mime, 'size' => $a->size, 'visibility' => $a->visibility,
            ])->all()),
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
