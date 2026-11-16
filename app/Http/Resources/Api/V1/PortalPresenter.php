<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Appointment;
use App\Models\Intervention;
use App\Models\PortalAccount;
use App\Models\PortalRequest;
use App\Models\Product;
use App\Models\ProductResource;
use App\Models\Trial;
use Illuminate\Support\Carbon;

/**
 * The ONLY place that shapes data for customers. Every field is whitelisted, so
 * internal notes, assignees, priorities, internal status names, activity ids and
 * staff identities can never leak by adding a column to a model.
 */
final class PortalPresenter
{
    private static function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->setTimezone(config('app.timezone'))->toIso8601String();
    }

    /** @return array<string, mixed> */
    public static function account(PortalAccount $a): array
    {
        return [
            'id' => $a->id, 'name' => $a->name, 'email' => $a->email, 'phone' => $a->phone, 'locale' => $a->locale,
            'is_customer' => $a->prospect_id !== null && $a->prospect?->isCustomer(),
        ];
    }

    /** @return array<string, mixed> */
    public static function product(Product $p): array
    {
        return ['id' => $p->id, 'name' => $p->name, 'description' => $p->description];
    }

    /** @return array<string, mixed> */
    public static function resource(ProductResource $r): array
    {
        return ['id' => $r->id, 'product_id' => $r->product_id, 'title' => $r->title, 'kind' => $r->kind, 'url' => $r->url, 'is_training_material' => $r->is_training_material];
    }

    /** @return array<string, mixed> */
    public static function appointment(Appointment $a): array
    {
        $showLink = $a->status === Appointment::CONFIRMED;

        return [
            'id' => $a->id,
            'type' => ['code' => $a->type->code, 'name' => $a->type->name, 'group' => $a->type->group, 'color' => $a->type->color],
            'title' => $a->title,
            'product' => $a->product ? ['id' => $a->product->id, 'name' => $a->product->name] : null,
            'status' => $a->isHoldExpired() ? Appointment::EXPIRED : $a->status,
            'starts_at' => self::iso($a->starts_at),
            'ends_at' => self::iso($a->ends_at),
            'hold_expires_at' => $a->isTemporary() ? self::iso($a->hold_expires_at) : null,
            'meeting_url' => $showLink ? $a->meeting_url : null,
            'message' => $a->customer_message,
            'resource_url' => $showLink ? $a->resource_url : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function request(PortalRequest $r): array
    {
        return [
            'id' => $r->id, 'type' => $r->type, 'status' => $r->status,
            'product' => $r->product ? ['id' => $r->product->id, 'name' => $r->product->name] : null,
            'company' => $r->company, 'notes' => $r->notes, 'message' => $r->customer_message,
            'appointment' => $r->appointment ? self::appointment($r->appointment) : null,
            'created_at' => self::iso($r->created_at),
        ];
    }

    /** The URL only exists while the trial is active; otherwise only its state and dates are returned. */
    public static function trial(Trial $t): array
    {
        $state = $t->state();

        return [
            'id' => $t->id, 'product' => ['id' => $t->product->id, 'name' => $t->product->name], 'state' => $state,
            'starts_at' => self::iso($t->starts_at), 'ends_at' => self::iso($t->ends_at),
            'url' => $state === 'active' ? $t->url : null,
        ];
    }

    /**
     * @param  bool  $detail  include the conversation (customer-visible messages only)
     * @return array<string, mixed>
     */
    public static function intervention(Intervention $i, bool $detail = false): array
    {
        $out = [
            'id' => $i->id, 'reference' => $i->reference, 'source' => $i->source, 'title' => $i->title,
            'type' => ['code' => $i->type->code, 'name' => $i->type->name, 'color' => $i->type->color],
            'status' => ['label' => $i->status->customer_label, 'color' => $i->status->color, 'awaits_customer' => $i->status->awaits_customer, 'is_closed' => $i->status->is_closed],
            'product' => $i->product ? ['id' => $i->product->id, 'name' => $i->product->name] : null,
            'created_at' => self::iso($i->created_at), 'updated_at' => self::iso($i->updated_at),
            'resolution' => $i->customer_resolution,
            'published_at' => self::iso($i->published_at),
        ];

        if ($detail) {
            $out['description'] = $i->description;
            $out['messages'] = $i->messages->where('visibility', 'customer')->map(fn ($m) => [
                'id' => $m->id, 'author' => $m->author_type === 'customer' ? 'you' : 'support', 'body' => $m->body,
                'status_label' => $m->status?->customer_label, 'created_at' => self::iso($m->created_at),
            ])->values()->all();
            $out['attachments'] = $i->attachments->where('visibility', 'customer')->map(fn ($a) => [
                'id' => $a->id, 'name' => $a->original_name, 'size' => $a->size,
            ])->values()->all();
        }

        return $out;
    }

    /** Release/update note for the "updates" feed (employee-originated, published). */
    public static function update(Intervention $i): array
    {
        return [
            'id' => $i->id, 'reference' => $i->reference, 'title' => $i->title,
            'type' => ['code' => $i->type->code, 'name' => $i->type->name, 'color' => $i->type->color],
            'product' => $i->product ? ['id' => $i->product->id, 'name' => $i->product->name] : null,
            'notes' => $i->customer_resolution, 'published_at' => self::iso($i->published_at),
        ];
    }
}
