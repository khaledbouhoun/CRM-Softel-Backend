<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\MessageTemplateDefaults;
use App\Services\WhatsAppLinkBuilder;
use Illuminate\Http\JsonResponse;

class MessageTemplateAuditController extends Controller
{
    /** Which required templates (appointment kind x language) are missing, inactive or empty. */
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success(MessageTemplateDefaults::audit() + ['placeholder_help' => WhatsAppLinkBuilder::placeholderHelp()], 'Template audit retrieved successfully.');
    }
}
