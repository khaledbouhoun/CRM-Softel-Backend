<?php

namespace App\Support;

use Illuminate\Support\Str;

/** The 58 Algerian wilayas. Stored and displayed as "16 - Alger". */
final class Wilayas
{
    /** @return list<array{code: string, name: string, name_ar: string, label: string}> */
    public static function all(): array
    {
        return array_map(fn (array $w) => ['code' => $w[0], 'name' => $w[1], 'name_ar' => $w[2], 'label' => $w[0].' - '.$w[1]], config('wilayas'));
    }

    /** Accepts a code ("16", "6"), a label ("16 - Alger"), a French name or an Arabic name, in any case or accent. */
    public static function resolve(?string $value): ?array
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\s*0*(\d{1,2})\s*(?:[-–—:.]\s*(.*))?$/u', $value, $m)) {
            $code = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $match = collect(self::all())->firstWhere('code', $code);

            // "16 - Alger" must agree with itself; a bare code is enough.
            return $match && (($m[2] ?? '') === '' || self::key($m[2]) === self::key($match['name']) || self::key($m[2]) === self::key($match['name_ar'])) ? $match : null;
        }

        $key = self::key($value);

        return collect(self::all())->first(fn ($w) => self::key($w['name']) === $key || self::key($w['name_ar']) === $key);
    }

    /** The canonical "16 - Alger" label, or the input untouched when it is not a wilaya. */
    public static function canonical(mixed $value): mixed
    {
        return is_string($value) ? (self::resolve($value)['label'] ?? $value) : $value;
    }

    /** @return array{code: string, name: string, name_ar: string, label: string}|null */
    public static function parse(?string $stored): ?array
    {
        return self::resolve($stored);
    }

    private static function key(string $s): string
    {
        $s = trim($s);

        return preg_match('/\p{Arabic}/u', $s)
            ? preg_replace('/[^\p{L}\p{N}]+/u', '', $s)
            : strtolower(preg_replace('/[^a-z0-9]+/i', '', Str::ascii($s)));
    }
}
