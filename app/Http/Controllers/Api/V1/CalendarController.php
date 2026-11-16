<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CalendarRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Appointment;
use App\Models\FollowUpTask;
use App\Models\Intervention;
use App\Services\SchedulingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/** Unified employee calendar: appointments, CRM follow-ups and scheduled interventions, one flat list. */
class CalendarController extends Controller
{
    public function __construct(private readonly SchedulingService $scheduling) {}

    public function __invoke(CalendarRequest $request): JsonResponse
    {
        $this->scheduling->releaseExpired();
        $v = $request->validated();
        $tz = config('app.timezone');
        $from = Carbon::parse($v['from'], $tz)->startOfDay();
        $to = Carbon::parse($v['to'], $tz)->endOfDay();
        $kinds = $v['kinds'] ?? ['appointment', 'follow_up', 'intervention'];
        $items = collect();

        if (in_array('appointment', $kinds, true)) {
            $appointments = Appointment::query()->with(['type', 'prospect', 'product'])->overlapping($from, $to)
                ->whereNotIn('status', [Appointment::EXPIRED, Appointment::REJECTED])
                ->when($v['employee'] ?? null, fn ($q, $e) => $q->where('assigned_employee', $e))
                ->when($v['prospect_id'] ?? null, fn ($q, $id) => $q->where('prospect_id', $id))
                ->when($v['product_id'] ?? null, fn ($q, $id) => $q->where('product_id', $id))
                ->when($v['type'] ?? null, fn ($q, $t) => $q->whereHas('type', fn ($w) => $w->where('code', $t)))
                ->when($v['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->orderBy('starts_at')->get();

            $blocking = $appointments->whereIn('status', Appointment::BLOCKING);

            foreach ($appointments as $a) {
                $clash = $blocking->filter(fn ($o) => $o->id !== $a->id && $o->starts_at->lt($a->ends_at) && $o->ends_at->gt($a->starts_at)
                    && ($o->prospect_id === $a->prospect_id || ($a->assigned_employee !== null && $o->assigned_employee === $a->assigned_employee)));

                $items->push([
                    'kind' => 'appointment', 'id' => $a->id, 'title' => $a->title ?? $a->type->name,
                    'starts_at' => $a->starts_at->toIso8601String(), 'ends_at' => $a->ends_at->toIso8601String(),
                    'status' => $a->status, 'color' => $a->type->color, 'type_code' => $a->type->code, 'group' => $a->type->group,
                    'prospect' => ['id' => $a->prospect->id, 'name' => $a->prospect->name],
                    'product' => $a->product ? ['id' => $a->product->id, 'name' => $a->product->name] : null,
                    'assigned_employee' => $a->assigned_employee,
                    'conflicts' => $clash->map(fn ($o) => ['appointment_id' => $o->id, 'reason' => $o->prospect_id === $a->prospect_id ? 'same_customer' : 'same_employee'])->values()->all(),
                ]);
            }
        }

        if (in_array('follow_up', $kinds, true) && array_intersect_key($v, array_flip(['employee', 'product_id', 'type'])) === []) {
            FollowUpTask::query()->with('prospect')->pending()->forActiveProspects()
                ->whereBetween('scheduled_at', [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')])
                ->when($v['prospect_id'] ?? null, fn ($q, $id) => $q->where('prospect_id', $id))
                ->orderBy('scheduled_at')->get()->each(function ($f) use ($items) {
                    $items->push([
                        'kind' => 'follow_up', 'id' => $f->id, 'title' => $f->title,
                        'starts_at' => $f->scheduled_at->toIso8601String(), 'ends_at' => $f->scheduled_at->copy()->addMinutes(30)->toIso8601String(),
                        'status' => $f->status->value, 'color' => '#64748B', 'type_code' => $f->activity_type?->value, 'group' => 'follow_up',
                        'prospect' => ['id' => $f->prospect->id, 'name' => $f->prospect->name], 'product' => null,
                        'assigned_employee' => null, 'conflicts' => [],
                    ]);
                });
        }

        if (in_array('intervention', $kinds, true) && ! isset($v['type'])) {
            Intervention::query()->with(['type', 'status', 'prospect', 'product'])->whereNotNull('scheduled_at')
                ->whereBetween('scheduled_at', [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')])
                ->whereHas('status', fn ($q) => $q->where('is_closed', false))
                ->when($v['employee'] ?? null, fn ($q, $e) => $q->where('assigned_employee', $e))
                ->when($v['prospect_id'] ?? null, fn ($q, $id) => $q->where('prospect_id', $id))
                ->when($v['product_id'] ?? null, fn ($q, $id) => $q->where('product_id', $id))
                ->orderBy('scheduled_at')->get()->each(function ($i) use ($items) {
                    $items->push([
                        'kind' => 'intervention', 'id' => $i->id, 'title' => $i->reference.' '.$i->title,
                        'starts_at' => $i->scheduled_at->toIso8601String(), 'ends_at' => $i->scheduled_at->copy()->addHour()->toIso8601String(),
                        'status' => $i->status->code, 'color' => $i->type->color, 'type_code' => $i->type->code, 'group' => 'intervention',
                        'prospect' => $i->prospect ? ['id' => $i->prospect->id, 'name' => $i->prospect->name] : null,
                        'product' => $i->product ? ['id' => $i->product->id, 'name' => $i->product->name] : null,
                        'assigned_employee' => $i->assigned_employee, 'conflicts' => [],
                    ]);
                });
        }

        $sorted = $items->sortBy('starts_at')->values()->all();

        return ApiResponse::success($sorted, 'Calendar retrieved successfully.', 200, ['total' => count($sorted), 'timezone' => $tz]);
    }
}
