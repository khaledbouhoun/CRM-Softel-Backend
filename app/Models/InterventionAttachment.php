<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InterventionAttachment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_hotfix' => 'boolean'];
    }

    /** Shown inline (preview) instead of downloaded: only types a browser renders safely. */
    public const PREVIEWABLE = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];

    public function isPreviewable(): bool
    {
        return in_array($this->mime, self::PREVIEWABLE, true);
    }
}
