<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Explanatory text for the Parameters screens: every section and field has a
 * short "help" and a concrete "example" the UI shows in a tooltip or as a
 * placeholder. Texts live in config/crm_help.php.
 */
class SettingsHelpController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(config('crm_help'), 'Settings help retrieved successfully.');
    }

    public function show(string $section): JsonResponse
    {
        $help = config("crm_help.{$section}");
        abort_if($help === null, 404);

        return ApiResponse::success($help, 'Settings help retrieved successfully.');
    }
}
