<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Appointment;
use App\Models\Intervention;
use App\Models\PortalAccount;
use App\Models\PortalRequest;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use App\Models\ProductResource;
use App\Models\Trial;
use App\Support\Wilayas;
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
        $wilaya = Wilayas::resolve($a->wilaya);

        return [
            'id' => $a->id, 'name' => $a->name, 'email' => $a->email, 'phone' => $a->phone, 'locale' => $a->locale, 'avatar_url' => $a->avatar_url,
            'profile' => [
                'company' => $a->company, 'contact_name' => $a->contact_name, 'job_title' => $a->job_title,
                'wilaya' => $wilaya['label'] ?? $a->wilaya, 'wilaya_code' => $wilaya['code'] ?? null,
                'city' => $a->city, 'business_activity' => $a->business_activity,
            ],
            'email_verified' => $a->hasVerifiedEmail(),
            'is_customer' => $a->prospect_id !== null && $a->prospect?->isCustomer(),
        ];
    }

    /** @return array<string, mixed> */
    public static function product(Product $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'slug' => $p->slug, 'description' => $p->description,
            'full_description' => $p->full_description, 'image_url' => $p->image_path ? Storage::url($p->image_path) : null,
            'gallery' => collect($p->gallery ?? [])->map(fn ($path) => Storage::url($path))->all(),
            'features' => $p->features ?? [], 'specifications' => $p->specifications ?? [],
            'pack' => $p->pack_id ? ['id' => $p->pack_id, 'code' => $p->pack?->code, 'name' => $p->pack?->name] : null,
            'has_demo' => filled($p->demo_url),
        ];
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
            'can_reply' => ! $i->status->is_closed,
            'product' => $i->product ? ['id' => $i->product->id, 'name' => $i->product->name] : null,
            'created_at' => self::iso($i->created_at), 'updated_at' => self::iso($i->updated_at),
            'resolution' => $i->customer_resolution,
            'published_at' => self::iso($i->published_at),
        ];

        if ($detail) {
            $out['description'] = $i->description;
            if ($i->status->is_closed) {
                $out['closed_notice'] = 'This intervention is closed. New replies are disabled.';
            }

            $file = fn ($a) => ChatPresenter::attachment($a, [
                'download' => 'portal.interventions.download', 'preview' => 'portal.interventions.preview',
            ], ['id' => $i->id], false);

            $out['messages'] = $i->messages->where('visibility', 'customer')->map(fn ($m) => [
                'id' => $m->id, 'kind' => $m->kind, 'author' => $m->isEvent() ? 'system' : ($m->author_type === 'customer' ? 'you' : 'support'),
                'sender' => ChatPresenter::sender($m, true), 'event' => ChatPresenter::event($m, true), 'body' => $m->body,
                'status_label' => $m->status?->customer_label, 'created_at' => self::iso($m->created_at),
                'attachments' => $m->relationLoaded('attachments')
                    ? $m->attachments->where('visibility', 'customer')->map($file)->values()->all() : [],
            ])->values()->all();
            $out['attachments'] = $i->attachments->where('visibility', 'customer')->map($file)->values()->all();
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
