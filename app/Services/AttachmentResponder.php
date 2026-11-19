<?php

namespace App\Services;

use App\Models\InterventionAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Streams stored files through the Storage facade (any disk: local, s3...). Never exposes a public path. */
class AttachmentResponder
{
    public static function download(InterventionAttachment $attachment, ?string $disk = null): StreamedResponse
    {
        abort_unless(Storage::disk($disk)->exists($attachment->path), 404);

        return Storage::disk($disk)->download($attachment->path, $attachment->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public static function preview(InterventionAttachment $attachment, ?string $disk = null): StreamedResponse
    {
        if (! $attachment->isPreviewable()) {
            return self::download($attachment, $disk);
        }

        abort_unless(Storage::disk($disk)->exists($attachment->path), 404);

        return Storage::disk($disk)->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime, 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], 'inline');
    }
}
