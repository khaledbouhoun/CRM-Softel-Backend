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
            'prospect' => $this->relationLoaded('prospect') && $this->prospect ? ['id' => $this->prospect->id, 'name' => $this->prospect->name] : null,
            'product' => $this->relationLoaded('product') && $this->product ? ['id' => $this->product->id, 'name' => $this->product->name] : null,
            'customers' => $this->whenLoaded('customers', fn () => $this->customers->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all()),
            'assigned_employee' => $this->assigned_employee,
            'is_published' => $this->is_published,
            'published_at' => $this->iso($this->published_at),
            'customer_resolution' => $this->customer_resolution,
            'scheduled_at' => $this->iso($this->scheduled_at),
            'closed_at' => $this->iso($this->closed_at),
            'is_closed' => (bool) $this->status?->is_closed,
            'can_reply' => ! $this->status?->is_closed,
            'messages' => $this->whenLoaded('messages', fn () => $this->messages->map(fn ($m) => [
                'id' => $m->id, 'kind' => $m->kind, 'author_type' => $m->author_type, 'author_name' => $m->author_name,
                'sender' => ChatPresenter::sender($m, false), 'event' => ChatPresenter::event($m, false),
                'visibility' => $m->visibility, 'body' => $m->body, 'status_id' => $m->status_id, 'from_status_id' => $m->from_status_id,
                'attachments' => $m->relationLoaded('attachments')
                    ? $m->attachments->map(fn ($a) => $this->file($a))->values()->all() : [],
                'created_at' => $this->iso($m->created_at),
            ])->all()),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($a) => $this->file($a))->all()),
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    private function file($a): array
    {
        return ChatPresenter::attachment($a, [
            'download' => 'api.v1.interventions.attachments.download', 'preview' => 'api.v1.interventions.attachments.preview',
        ], ['intervention' => $this->id], true);
    }
}
