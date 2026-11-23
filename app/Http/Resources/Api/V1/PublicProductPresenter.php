<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use Illuminate\Support\Facades\Storage;

/**
 * Marketing catalogue shape served to the public website (no authentication).
 * Every text is returned for all three languages; missing translations fall back to the product's own
 * name / description / features so the website never receives an empty block.
 */
final class PublicProductPresenter
{
    public const LOCALES = ['ar', 'fr', 'en'];

    /** @return array<string, mixed> */
    public static function item(Product $p, ?string $locale = null): array
    {
        $title = self::texts($p->title_i18n, (string) $p->name);
        $summary = self::texts($p->summary_i18n, (string) ($p->description ?? ''));
        $description = self::texts($p->description_i18n, (string) ($p->full_description ?: $p->description ?: ''));
        $tech = self::lists($p->tech_stack_i18n, array_values((array) ($p->features ?? [])));

        $item = [
            'id' => $p->id,
            'slug' => $p->slug,
            'sort_order' => (int) $p->sort_order,
            'image_path' => $p->image_path,
            'image_url' => self::url($p->image_path),
            'gallery' => collect($p->gallery ?? [])->map(fn ($path) => self::url($path))->filter()->values()->all(),
            'title' => $title,
            'summary' => $summary,
            'description' => $description,
            'tech_stack' => $tech,
            'has_demo' => filled($p->demo_url),
        ];

        if ($locale !== null && in_array($locale, self::LOCALES, true)) {
            $item['localized'] = [
                'locale' => $locale,
                'title' => $title[$locale],
                'summary' => $summary[$locale],
                'description' => $description[$locale],
                'tech_stack' => $tech[$locale],
            ];
        }

        return $item;
    }

    /** Absolute URL for an uploaded asset; http(s) values are kept as they are. */
    private static function url(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return preg_match('#^https?://#i', $path) ? $path : url(Storage::url($path));
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array<string, string>
     */
    private static function texts(?array $raw, string $fallback): array
    {
        $out = [];
        foreach (self::LOCALES as $l) {
            $out[$l] = trim((string) ($raw[$l] ?? '')) !== '' ? (string) $raw[$l] : ((string) ($raw['en'] ?? '') !== '' ? (string) $raw['en'] : $fallback);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @param  array<int, string>  $fallback
     * @return array<string, array<int, string>>
     */
    private static function lists(?array $raw, array $fallback): array
    {
        $out = [];
        foreach (self::LOCALES as $l) {
            $list = array_values(array_filter((array) ($raw[$l] ?? []), 'is_string'));
            $out[$l] = $list !== [] ? $list : (array_values(array_filter((array) ($raw['en'] ?? []), 'is_string')) ?: $fallback);
        }

        return $out;
    }
}
