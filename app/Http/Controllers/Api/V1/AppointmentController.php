<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AppointmentReasonRequest;
use App\Http\Requests\Api\V1\CompleteAppointmentRequest;
use App\Http\Requests\Api\V1\ConfirmAppointmentRequest;
use App\Http\Requests\Api\V1\IndexAppointmentRequest;
use App\Http\Requests\Api\V1\ProposeAppointmentRequest;
use App\Http\Requests\Api\V1\RescheduleAppointmentRequest;
use App\Http\Requests\Api\V1\ScheduleAppointmentRequest;
use App\Http\Resources\Api\V1\AppointmentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Appointment;
use App\Models\AppointmentSeries;
use App\Models\AppointmentType;
use App\Models\Product;
use App\Models\Prospect;
use App\Services\AppointmentService;
use App\Services\SchedulingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AppointmentController extends Controller
{
    private const WITH = ['type', 'prospect', 'product'];

    public function __construct(
        private readonly AppointmentService $appointments,
        private readonly SchedulingService $scheduling,
    ) {}

    private function fresh(Appointment $a): AppointmentResource
    {
        return new AppointmentResource($a->refresh()->load(self::WITH));
    }

    private function date(string $value): Carbon
    {
        return Carbon::parse($value, config('app.timezone'));
    }

    public function index(IndexAppointmentRequest $request): JsonResponse
    {
        $this->scheduling->releaseExpired();
        $v = $request->validated();

        $paginator = Appointment::query()->with(self::WITH)
            ->when($v['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($v['prospect_id'] ?? null, fn ($q, $id) => $q->where('prospect_id', $id))
            ->when($v['group'] ?? null, fn ($q, $g) => $q->whereHas('type', fn ($t) => $t->where('group', $g)))
            ->when($v['from'] ?? null, fn ($q, $d) => $q->where('starts_at', '>=', $this->date($d)->format('Y-m-d H:i:s')))
            ->when($v['to'] ?? null, fn ($q, $d) => $q->where('starts_at', '<=', $this->date($d)->endOfDay()->format('Y-m-d H:i:s')))
            ->orderBy('starts_at', $v['direction'] ?? 'asc')
            ->paginate((int) ($v['per_page'] ?? config('crm.pagination.default_per_page')));

        return ApiResponse::paginated($paginator, AppointmentResource::class, 'Appointments retrieved successfully.');
    }

    public function show(Appointment $appointment): JsonResponse
    {
        $this->scheduling->releaseExpired();

        return ApiResponse::success($this->fresh($appointment), 'Appointment retrieved successfully.');
    }

    /** Creates one confirmed session, or a whole all-or-nothing series when "occurrences" > 1. */
    public function store(ScheduleAppointmentRequest $request): JsonResponse
    {
        $v = $request->validated();
        $prospect = Prospect::query()->findOrFail($v['prospect_id']);
        $type = AppointmentType::query()->findOrFail($v['appointment_type_id']);
        $product = isset($v['product_id']) ? Product::query()->findOrFail($v['product_id']) : null;
        abort_unless($type->is_active, 422, 'This appointment type is inactive.');

        $start = $this->date($v['starts_at']);
        $end = isset($v['ends_at']) ? $this->date($v['ends_at']) : $start->copy()->addMinutes($type->default_duration_minutes);
        $extra = [
            'internal_notes' => $v['internal_notes'] ?? null, 'meeting_url' => $v['meeting_url'] ?? null,
            'send_whatsapp' => (bool) ($v['send_whatsapp'] ?? false), 'resource_url' => $v['resource_url'] ?? null,
            'decided_by' => $this->actor($request), 'decided_at' => now(),
        ];

        if ($type->group === 'accompaniment') {
            $count = (int) ($v['occurrences'] ?? $this->scheduling->setting('accompaniment_occurrences'));
            $interval = (int) ($v['interval_days'] ?? $this->scheduling->setting('accompaniment_interval_days'));
            $series = $this->appointments->createSeries($prospect, $type, $product, $start, $end, $count, $interval, $v['assigned_employee'] ?? null, $extra, $this->actor($request));
            $items = $series->appointments()->with(self::WITH)->get();

            return ApiResponse::created(
                ['series_id' => $series->id, 'appointments' => $items->map(fn ($a) => (new AppointmentResource($a))->resolve())->all()],
                'Accompaniment series scheduled.',
            );
        }

        $appointment = $this->appointments->schedule($prospect, $type, $product, $start, $end, $v['assigned_employee'] ?? null, $extra);

        return ApiResponse::created($this->fresh($appointment), 'Appointment scheduled.');
    }

    public function confirm(ConfirmAppointmentRequest $request, Appointment $appointment): JsonResponse
    {
        $v = $request->validated();
        $done = $this->appointments->confirm($appointment, $v['meeting_url'] ?? null, $v['assigned_employee'] ?? $this->actor($request), $this->actor($request), $v['message'] ?? null);

        return ApiResponse::success($this->fresh($done), 'Appointment confirmed.');
    }

    public function reject(AppointmentReasonRequest $request, Appointment $appointment): JsonResponse
    {
        $done = $this->appointments->reject($appointment, $request->validated('reason'), $this->actor($request));

        return ApiResponse::success($this->fresh($done), 'Appointment rejected and slot released.');
    }

    public function cancel(AppointmentReasonRequest $request, Appointment $appointment): JsonResponse
    {
        $done = $this->appointments->cancel($appointment, $request->validated('reason'), $this->actor($request));

        return ApiResponse::success($this->fresh($done), 'Appointment cancelled and slot released.');
    }

    public function propose(ProposeAppointmentRequest $request, Appointment $appointment): JsonResponse
    {
        $done = $this->appointments->propose($appointment, $this->date($request->validated('starts_at')), $request->validated('message'), $this->actor($request));

        return ApiResponse::success($this->fresh($done), 'New time proposed to the customer.');
    }

    public function reschedule(RescheduleAppointmentRequest $request, Appointment $appointment): JsonResponse
    {
        $v = $request->validated();
        $done = $this->appointments->reschedule($appointment, $this->date($v['starts_at']), $this->date($v['ends_at']), $v['assigned_employee'] ?? null);

        return ApiResponse::success($this->fresh($done), 'Appointment moved.');
    }

    public function complete(CompleteAppointmentRequest $request, Appointment $appointment): JsonResponse
    {
        $done = $this->appointments->complete($appointment, $request->validated('outcome'), $this->actor($request));

        return ApiResponse::success($this->fresh($done), 'Outcome recorded as an activity.');
    }

    public function noShow(Appointment $appointment): JsonResponse
    {
        return ApiResponse::success($this->fresh($this->appointments->markNoShow($appointment)), 'Marked as no-show.');
    }

    /** Cancels remaining future sessions; ?from_appointment_id limits it to that session and later ones. */
    public function cancelSeries(Request $request, AppointmentSeries $series): JsonResponse
    {
        $from = $request->query('from_appointment_id')
            ? Appointment::query()->where('series_id', $series->id)->findOrFail($request->query('from_appointment_id'))
            : null;
        $count = $this->appointments->cancelSeries($series, $from, $request->input('reason'));

        return ApiResponse::success(['cancelled' => $count], 'Remaining sessions cancelled; completed history is untouched.');
    }
}
