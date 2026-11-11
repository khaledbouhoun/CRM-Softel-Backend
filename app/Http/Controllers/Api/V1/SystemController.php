<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityType;
use App\Enums\FollowUpActivityType;
use App\Enums\FollowUpStatus;
use App\Enums\StageType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexFollowUpRequest;
use App\Http\Resources\Api\V1\LossReasonResource;
use App\Http\Resources\Api\V1\ProductResource;
use App\Http\Resources\Api\V1\ProspectFormOptionResource;
use App\Http\Resources\Api\V1\SalesStageResource;
use App\Http\Responses\ApiResponse;
use App\Models\LossReason;
use App\Models\Product;
use App\Models\ProspectFormOption;
use App\Models\SalesStage;
use App\Models\EmployeeSession;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Connectivity check and reference data for the Flutter client. */
class SystemController extends Controller
{
    public function health(): JsonResponse
    {
        try {
            DB::select('SELECT 1');
            $database = 'ok';
        } catch (Throwable $e) {
            report($e);
            $database = 'unavailable';
        }

        $data = [
            'status' => $database === 'ok' ? 'ok' : 'degraded',
            'database' => $database,
            'api_version' => 'v1',
            'server_time' => now()->toIso8601String(),
            'timezone' => config('app.timezone'),
        ];

        return $database === 'ok'
            ? ApiResponse::success($data, 'API is running.')
            : ApiResponse::error('Database is unavailable.', 503, 'SERVICE_UNAVAILABLE', ['health' => [$data]]);
    }

    /**
     * Everything a client needs to build forms and filters in one call.
     * Lists include inactive entries (flagged) so historical data can be labelled.
     */
    public function lookups(): JsonResponse
    {
        $resolve = fn ($items, string $resource) => $items->map(fn ($m) => (new $resource($m))->resolve())->all();

        $formOptions = ProspectFormOption::query()->ordered()->get();
        $formOptionsByType = $formOptions->groupBy('type');

        return ApiResponse::success([
            'stage_types' => StageType::values(),
            'activity_types' => ActivityType::values(),
            'follow_up_activity_types' => FollowUpActivityType::values(),
            'business_activities' => $formOptionsByType->get('business_activity', collect())->where('is_active', true)->pluck('name')->values()->all() ?: config('crm.catalogs.business_activities', []),
            'prospect_sources' => $formOptionsByType->get('source', collect())->where('is_active', true)->pluck('name')->values()->all() ?: config('crm.catalogs.prospect_sources', []),
            'previous_software' => $formOptionsByType->get('previous_software', collect())->where('is_active', true)->pluck('name')->values()->all() ?: config('crm.catalogs.previous_software', []),
            'professions' => $formOptionsByType->get('profession', collect())->where('is_active', true)->pluck('name')->values()->all(),
            'prospect_form_options' => $resolve($formOptions, ProspectFormOptionResource::class),
            'follow_up_statuses' => FollowUpStatus::values(),
            'follow_up_scopes' => IndexFollowUpRequest::SCOPES,
            'sales_stages' => $resolve(SalesStage::query()->ordered()->get(), SalesStageResource::class),
            'loss_reasons' => $resolve(LossReason::query()->ordered()->get(), LossReasonResource::class),
            'products' => $resolve(Product::query()->orderBy('name')->orderBy('id')->get(), ProductResource::class),
            'settings' => [
                'timezone' => config('app.timezone'),
                'default_per_page' => config('crm.pagination.default_per_page'),
                'max_per_page' => config('crm.pagination.max_per_page'),
                'allow_reopening_closed' => (bool) config('crm.stage_transitions.allow_reopening_closed'),
            ],
        ], 'Lookups retrieved successfully.');
    }

    /** Record heartbeat from an employee workstation. */
    public function heartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'string', 'max:50'],
            'username' => ['required', 'string', 'max:100'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'last_request_path' => ['nullable', 'string', 'max:255'],
        ]);

        $ip = $request->ip() ?? '127.0.0.1';
        $session = EmployeeSession::updateOrCreate(
            ['employee_id' => $validated['employee_id']],
            [
                'username' => $validated['username'],
                'device_name' => $validated['device_name'] ?? ('PC-' . substr(md5($ip), 0, 6)),
                'ip_address' => $ip,
                'last_request_path' => $validated['last_request_path'] ?? '/api/v1/system/heartbeat',
                'last_heartbeat_at' => now(),
            ]
        );

        return ApiResponse::success([
            'employee_id' => $session->employee_id,
            'status' => 'active',
            'server_time' => now()->toIso8601String(),
        ], 'Heartbeat received.');
    }

    /** Aggregated employee connection information for CRM Server Manager. */
    public function employees(): JsonResponse
    {
        $sessions = EmployeeSession::orderByDesc('last_heartbeat_at')->get();

        $activeThreshold = now()->subSeconds(90);
        $recentThreshold = now()->subHours(2);

        $activeCount = 0;
        $recentCount = 0;
        $items = [];

        foreach ($sessions as $session) {
            $isOnline = $session->last_heartbeat_at >= $activeThreshold;
            $isRecent = $session->last_heartbeat_at >= $recentThreshold;

            if ($isOnline) {
                $activeCount++;
                $status = 'Online';
                $recentCount++;
            } elseif ($isRecent) {
                $status = 'Idle';
                $recentCount++;
            } else {
                $status = 'Offline';
            }

            $items[] = [
                'employee_id' => $session->employee_id,
                'username' => $session->username,
                'device_name' => $session->device_name,
                'ip_address' => $session->ip_address,
                'status' => $status,
                'last_activity' => $session->last_heartbeat_at->toIso8601String(),
                'last_activity_human' => $session->last_heartbeat_at->diffForHumans(),
                'last_request' => $session->last_request_path,
            ];
        }

        $lastActivity = $sessions->first()?->last_heartbeat_at?->toIso8601String();

        return ApiResponse::success([
            'summary' => [
                'active_employees' => $activeCount,
                'recent_connections' => $recentCount,
                'total_registered' => $sessions->count(),
                'last_activity_at' => $lastActivity,
            ],
            'employees' => $items,
        ], 'Employee connections retrieved successfully.');
    }
}
