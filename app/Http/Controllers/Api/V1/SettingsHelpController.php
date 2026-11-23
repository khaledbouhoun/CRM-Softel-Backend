<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Explanatory text for the Parameters screens: every section and field has a
 * short "help" and a concrete "example" the UI shows in a tooltip or as a
 * placeholder.
 *
 * English lives in config/crm_help.php (and is the fallback for anything not
 * translated); French and Arabic live in lang/{fr,ar}/crm_help.php. The language
 * comes from ?locale=ar|fr|en, then the X-Locale header, then Accept-Language.
 * In Arabic the company name is always written "Softel" in Latin letters.
 */
class SettingsHelpController extends Controller
{
    public const LOCALES = ['ar', 'fr', 'en'];

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->texts($this->locale($request)), 'Settings help retrieved successfully.');
    }

    public function show(Request $request, string $section): JsonResponse
    {
        $help = $this->texts($this->locale($request))[$section] ?? null;
        abort_if($help === null, 404);

        return ApiResponse::success($help, 'Settings help retrieved successfully.');
    }

    /** English texts overlaid with the translation of [$locale] (missing keys stay English). */
    public static function texts(string $locale): array
    {
        $english = config('crm_help');
        $file = lang_path("{$locale}/crm_help.php");

        if ($locale === 'en' || ! is_file($file)) {
            return $english;
        }

        return array_replace_recursive($english, require $file);
    }

    private function locale(Request $request): string
    {
        $candidates = [
            (string) $request->query('locale', ''),
            (string) $request->header('X-Locale', ''),
            substr((string) $request->header('Accept-Language', ''), 0, 2),
        ];

        foreach ($candidates as $candidate) {
            $candidate = strtolower(trim($candidate));
            if (in_array($candidate, self::LOCALES, true)) {
                return $candidate;
            }
        }

        return 'en';
    }
}
