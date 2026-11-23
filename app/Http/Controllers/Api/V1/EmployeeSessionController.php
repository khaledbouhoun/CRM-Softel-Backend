<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Activity;
use App\Models\Appointment;
use App\Models\EmployeeSession;
use App\Models\Intervention;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Workstations (heartbeat sessions) as a team hub: friendly name, role, presence and
 * today's productivity. Open access like the rest of the LAN API: no device authorisation.
 */
class EmployeeSessionController extends Controller
{
    public function index(): JsonResponse
    {
        $sessions = EmployeeSession::orderByDesc('last_heartbeat_at')->get();
        $stats = $this->todayStats($sessions->pluck('username')->unique()->all());

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
                $recentCount++;
                $status = 'Online';
            } elseif ($isRecent) {
                $recentCount++;
                $status = 'Idle';
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
                'custom_name' => $session->custom_name,
                'role' => $session->role,
                'presence_status' => $session->presence_status,
                'status_note' => $session->status_note,
                'today_stats' => $stats[$session->username] ?? ['activities_count' => 0, 'interventions_count' => 0, 'appointments_count' => 0],
            ];
        }

        return ApiResponse::success([
            'summary' => [
                'active_employees' => $activeCount,
                'recent_connections' => $recentCount,
                'total_registered' => $sessions->count(),
                'last_activity_at' => $sessions->first()?->last_heartbeat_at?->toIso8601String(),
                'team_actions_today' => collect($items)->sum(fn ($i) => array_sum($i['today_stats'])),
            ],
            'employees' => $items,
        ], 'Employee connections retrieved successfully.');
    }

    /** Workstation nickname and department/role. */
    public function update(Request $request, string $employeeId): JsonResponse
    {
        $session = EmployeeSession::where('employee_id', $employeeId)->firstOrFail();

        $data = $request->validate([
            'custom_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'role' => ['sometimes', 'required', Rule::in(EmployeeSession::ROLES)],
        ]);

        if (array_key_exists('custom_name', $data)) {
            $name = trim((string) $data['custom_name']);
            $data['custom_name'] = $name === '' ? null : $name;
        }

        $session->update($data);

        return ApiResponse::success($this->identity($session), 'Workstation updated successfully.');
    }

    /** Presence of the calling workstation (`employee_id` is the one sent in the heartbeat). */
    public function presence(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'string', 'max:50'],
            'presence_status' => ['required', Rule::in(EmployeeSession::PRESENCE)],
            'status_note' => ['nullable', 'string', 'max:500'],
        ]);

        $session = EmployeeSession::where('employee_id', $data['employee_id'])->firstOrFail();
        $note = isset($data['status_note']) ? trim($data['status_note']) : null;
        $session->update([
            'presence_status' => $data['presence_status'],
            'status_note' => $note === '' ? null : $note,
        ]);

        return ApiResponse::success($this->identity($session), 'Presence updated successfully.');
    }

    private function identity(EmployeeSession $s): array
    {
        return [
            'employee_id' => $s->employee_id,
            'custom_name' => $s->custom_name,
            'role' => $s->role,
            'presence_status' => $s->presence_status,
            'status_note' => $s->status_note,
        ];
    }

    /**
     * Work done today per employee label, in three grouped queries.
     *
     * @param  list<string>  $usernames
     * @return array<string, array{activities_count:int, interventions_count:int, appointments_count:int}>
     */
    private function todayStats(array $usernames): array
    {
        if ($usernames === []) {
            return [];
        }

        $start = now()->startOfDay();
        $count = fn ($query, string $column) => $query->whereIn($column, $usernames)
            ->selectRaw("$column as employee, count(*) as total")->groupBy($column)->pluck('total', 'employee');

        $activities = $count(Activity::query()->where('created_at', '>=', $start), 'created_by');
        $interventions = $count(Intervention::query()->where('updated_at', '>=', $start), 'assigned_employee');
        $appointments = $count(
            Appointment::query()->where('status', Appointment::COMPLETED)->where('updated_at', '>=', $start),
            'assigned_employee',
        );

        $stats = [];
        foreach ($usernames as $name) {
            $stats[$name] = [
                'activities_count' => (int) ($activities[$name] ?? 0),
                'interventions_count' => (int) ($interventions[$name] ?? 0),
                'appointments_count' => (int) ($appointments[$name] ?? 0),
            ];
        }

        return $stats;
    }
}
