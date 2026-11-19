<?php

namespace App\Http\Resources\Api\V1;

use App\Models\InterventionAttachment;
use App\Models\InterventionMessage;
use Illuminate\Support\Carbon;

/**
 * Shared shape of one chat entry for staff and customers: a reply with a
 * {role, name, avatar_url} sender (client | employee | system) or a timeline
 * event (status change / reopen) with from, to, actor and timestamp.
 */
class ChatPresenter
{
    private static function iso(?Carbon $d): ?string
    {
        return $d?->toIso8601String();
    }

    /** @return array{role: string, name: ?string, avatar_url: ?string} */
    public static function sender(InterventionMessage $m, bool $forCustomer): array
    {
        $role = $m->isEvent() ? 'system' : ($m->author_type === 'customer' ? 'client' : 'employee');

        // Customers never see employee names.
        $name = $forCustomer && $m->author_type === 'employee' ? 'Support' : $m->author_name;

        return ['role' => $role, 'name' => $name, 'avatar_url' => $m->author_type === 'customer' ? $m->account?->avatar_url : null];
    }

    /** @return array<string, mixed>|null */
    public static function event(InterventionMessage $m, bool $forCustomer): ?array
    {
        if (! $m->isEvent()) {
            return null;
        }

        $status = fn ($s) => $s ? ['id' => $s->id, 'name' => $forCustomer ? $s->customer_label : $s->name, 'color' => $s->color] : null;

        return [
            'type' => $m->kind,
            'actor' => ['role' => $m->author_type === 'customer' ? 'client' : 'employee', 'name' => $forCustomer && $m->author_type === 'employee' ? 'Support' : $m->author_name],
            'old_status' => $status($m->fromStatus),
            'new_status' => $status($m->status),
            'at' => self::iso($m->created_at),
        ];
    }

    /** @param  array{download: string, preview: string}  $routes  route names */
    public static function attachment(InterventionAttachment $a, array $routes, array $params, bool $staff): array
    {
        $out = [
            'id' => $a->id, 'message_id' => $a->message_id, 'name' => $a->original_name, 'mime' => $a->mime, 'size' => $a->size,
            'uploaded_by' => $a->uploaded_by_type, 'is_previewable' => $a->isPreviewable(),
            'download_url' => route($routes['download'], $params + ['attachmentId' => $a->id], false),
            'preview_url' => $a->isPreviewable() ? route($routes['preview'], $params + ['attachmentId' => $a->id], false) : null,
        ];

        if ($staff) {
            $out['visibility'] = $a->visibility;
            $out['uploaded_by_name'] = $a->uploaded_by_name;
        }

        return $out;
    }
}
