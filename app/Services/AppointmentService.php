<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Exceptions\BusinessRuleException;
use App\Models\Activity;
use App\Models\Appointment;
use App\Models\AppointmentSeries;
use App\Models\AppointmentType;
use App\Models\PortalAccount;
use App\Models\PortalRequest;
use App\Models\Product;
use App\Models\Prospect;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Appointment lifecycle. A customer demo request becomes a temporary HELD
 * appointment immediately; staff then confirm / reject / propose another time.
 * Completing an appointment is the only way it becomes an Activity.
 */
class AppointmentService
{
    public function __construct(private readonly SchedulingService $scheduling) {}

    // ---------------------------------------------------------------- customer demo holds

    public function hold(PortalAccount $account, Prospect $prospect, ?Product $product, Carbon $start, ?string $note = null): Appointment
    {
        $type = AppointmentType::query()->where('is_active', true)->where('customer_bookable', true)->orderBy('sort_order')->first()
            ?? throw new BusinessRuleException('No bookable appointment type is configured.', 'NO_BOOKABLE_TYPE', [], 422);

        $this->scheduling->assertOffered($start);
        $end = $start->copy()->addMinutes((int) $this->scheduling->setting('slot_minutes'));
        $capacity = max(1, (int) $this->scheduling->setting('demo_capacity'));

        return DB::transaction(function () use ($account, $prospect, $product, $type, $start, $end, $capacity, $note) {
            $this->scheduling->lockCalendar();
            $this->scheduling->releaseExpired();

            $overlapping = Appointment::query()->live()->overlapping($start, $end)->count();
            $taken = Appointment::query()->live()->where('starts_at', $start->format('Y-m-d H:i:s'))->whereNotNull('slot_key')->pluck('slot_key')->all();
            $lane = collect(range(1, $capacity))->first(fn ($n) => ! in_array($this->slotKey($n, $start), $taken, true));

            if ($overlapping >= $capacity || $lane === null) {
                throw $this->slotUnavailable();
            }

            try {
                return Appointment::query()->create([
                    'appointment_type_id' => $type->id,
                    'prospect_id' => $prospect->id,
                    'portal_account_id' => $account->id,
                    'product_id' => $product?->id,
                    'title' => $type->name.($product ? ' - '.$product->name : ''),
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'status' => Appointment::HELD,
                    'hold_expires_at' => now()->addMinutes((int) $this->scheduling->setting('hold_expiry_minutes')),
                    'slot_key' => $this->slotKey($lane, $start),
                    'internal_notes' => $note,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw $this->slotUnavailable();
            }
        });
    }

    public function accept(Appointment $appointment): Appointment
    {
        $this->assertStatus($appointment, [Appointment::PROPOSED], 'Only a proposed time can be accepted.');
        $this->assertNotExpired($appointment);

        $appointment->update(['status' => Appointment::CONFIRMED, 'hold_expires_at' => null, 'decided_at' => now(), 'decided_by' => 'customer']);
        $this->syncRequest($appointment, 'scheduled');

        return $appointment;
    }

    // ---------------------------------------------------------------- employee decisions

    public function confirm(Appointment $appointment, ?string $meetingUrl, ?string $employee, ?string $by, ?string $message = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $meetingUrl, $employee, $by, $message) {
            $locked = $this->lockFresh($appointment);
            $this->assertStatus($locked, [Appointment::HELD], 'Only a pending hold can be confirmed.');
            $this->assertNotExpired($locked);

            $locked->update([
                'status' => Appointment::CONFIRMED, 'hold_expires_at' => null, 'meeting_url' => $meetingUrl,
                'assigned_employee' => $employee ?? $locked->assigned_employee, 'customer_message' => $message,
                'decided_at' => now(), 'decided_by' => $by,
            ]);
            $this->syncRequest($locked, 'scheduled');

            return $locked;
        });
    }

    public function reject(Appointment $appointment, ?string $reason, ?string $by): Appointment
    {
        return $this->release($appointment, Appointment::REJECTED, 'rejected', $reason, $by, [Appointment::HELD, Appointment::PROPOSED]);
    }

    public function cancel(Appointment $appointment, ?string $reason, ?string $by): Appointment
    {
        if ($appointment->starts_at->lt(now()) && $appointment->status === Appointment::CONFIRMED) {
            throw new BusinessRuleException('A meeting that already started cannot be cancelled; record its outcome instead.', 'APPOINTMENT_STARTED');
        }

        return $this->release($appointment, Appointment::CANCELLED, 'cancelled', $reason, $by, Appointment::BLOCKING);
    }

    /** Offers the customer another time: the new slot is held and the customer must accept it. */
    public function propose(Appointment $appointment, Carbon $start, ?string $message, ?string $by): Appointment
    {
        $this->scheduling->assertOffered($start);
        $end = $start->copy()->addMinutes((int) $this->scheduling->setting('slot_minutes'));
        $capacity = max(1, (int) $this->scheduling->setting('demo_capacity'));

        return DB::transaction(function () use ($appointment, $start, $end, $capacity, $message, $by) {
            $this->scheduling->lockCalendar();
            $this->scheduling->releaseExpired();
            $locked = $this->lockFresh($appointment);
            $this->assertStatus($locked, [Appointment::HELD, Appointment::PROPOSED], 'Only a pending request can be given a new time.');

            $others = Appointment::query()->live()->overlapping($start, $end)->where('id', '!=', $locked->id);
            $taken = (clone $others)->where('starts_at', $start->format('Y-m-d H:i:s'))->whereNotNull('slot_key')->pluck('slot_key')->all();
            $lane = collect(range(1, $capacity))->first(fn ($n) => ! in_array($this->slotKey($n, $start), $taken, true));

            if ($others->count() >= $capacity || $lane === null) {
                throw $this->slotUnavailable();
            }

            try {
                $locked->update([
                    'starts_at' => $start, 'ends_at' => $end, 'status' => Appointment::PROPOSED,
                    'slot_key' => $this->slotKey($lane, $start), 'customer_message' => $message,
                    'hold_expires_at' => now()->addMinutes((int) $this->scheduling->setting('hold_expiry_minutes')),
                    'decided_at' => now(), 'decided_by' => $by,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw $this->slotUnavailable();
            }

            $this->syncRequest($locked, 'pending');

            return $locked;
        });
    }

    /** Moves a future, non-demo appointment (training, meeting, accompaniment session). */
    public function reschedule(Appointment $appointment, Carbon $start, Carbon $end, ?string $employee = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $start, $end, $employee) {
            $this->scheduling->lockCalendar();
            $locked = $this->lockFresh($appointment);
            $this->assertStatus($locked, [Appointment::CONFIRMED], 'Only a confirmed appointment can be moved.');

            if ($locked->slot_key !== null) {
                throw new BusinessRuleException('Customer demos are moved by proposing a new time.', 'USE_PROPOSAL');
            }
            if ($locked->starts_at->lt(now())) {
                throw new BusinessRuleException('A past appointment cannot be moved; its history is kept.', 'APPOINTMENT_STARTED');
            }
            if ($end->lte($start)) {
                throw new BusinessRuleException('The end time must be after the start time.', 'INVALID_TIME_RANGE', ['ends_at' => ['The end time must be after the start time.']]);
            }

            $this->assertNoConflicts([[$start, $end]], $employee ?? $locked->assigned_employee, $locked->prospect_id, $locked->id);
            $locked->update(['starts_at' => $start, 'ends_at' => $end, 'assigned_employee' => $employee ?? $locked->assigned_employee]);

            return $locked;
        });
    }

    /** Records that the event took place: creates the Activity and links it. */
    public function complete(Appointment $appointment, string $outcome, ?string $by): Appointment
    {
        return DB::transaction(function () use ($appointment, $outcome) {
            $locked = $this->lockFresh($appointment);
            $locked->loadMissing('type');
            $this->assertStatus($locked, [Appointment::CONFIRMED], 'Only a confirmed appointment can be completed.');

            if ($locked->starts_at->gt(now())) {
                throw new BusinessRuleException('This appointment has not started yet.', 'APPOINTMENT_NOT_STARTED');
            }

            $activity = new Activity([
                'type' => ActivityType::Meeting,
                'description' => ($locked->title ?? $locked->type->name).' ('.$locked->starts_at->format('Y-m-d H:i').')',
                'outcome' => $outcome,
                'activity_at' => $locked->starts_at,
                'prospect_id' => $locked->prospect_id,
            ]);
            $activity->linked_at = now();
            $activity->save();

            $locked->update(['status' => Appointment::COMPLETED, 'outcome' => $outcome, 'activity_id' => $activity->id, 'slot_key' => null]);

            return $locked;
        });
    }

    public function markNoShow(Appointment $appointment): Appointment
    {
        $this->assertStatus($appointment, [Appointment::CONFIRMED], 'Only a confirmed appointment can be marked as a no-show.');

        if ($appointment->starts_at->gt(now())) {
            throw new BusinessRuleException('This appointment has not started yet.', 'APPOINTMENT_NOT_STARTED');
        }

        $appointment->update(['status' => Appointment::NO_SHOW, 'slot_key' => null]);

        return $appointment;
    }

    // ---------------------------------------------------------------- training and series

    /**
     * Single staff-created session (training, meeting). Confirmed at once.
     *
     * @param  array<string, mixed>  $extra
     */
    public function schedule(Prospect $prospect, AppointmentType $type, ?Product $product, Carbon $start, Carbon $end, ?string $employee, array $extra = []): Appointment
    {
        return $this->scheduleMany($prospect, $type, $product, [[$start, $end]], $employee, $extra, null)[0];
    }

    /**
     * Whole accompaniment series, all-or-nothing: every occurrence is checked
     * before anything is written.
     *
     * @param  array<string, mixed>  $extra
     */
    public function createSeries(Prospect $prospect, AppointmentType $type, ?Product $product, Carbon $firstStart, Carbon $firstEnd, int $occurrences, int $intervalDays, ?string $employee, array $extra = [], ?string $by = null): AppointmentSeries
    {
        $windows = [];
        for ($i = 0; $i < $occurrences; $i++) {
            $windows[] = [$firstStart->copy()->addDays($i * $intervalDays), $firstEnd->copy()->addDays($i * $intervalDays)];
        }

        return DB::transaction(function () use ($prospect, $type, $product, $windows, $occurrences, $intervalDays, $employee, $extra, $by) {
            $this->scheduling->lockCalendar();
            $this->assertNoConflicts($windows, $employee, $prospect->id);

            $series = AppointmentSeries::query()->create([
                'appointment_type_id' => $type->id, 'prospect_id' => $prospect->id, 'product_id' => $product?->id,
                'occurrences' => $occurrences, 'interval_days' => $intervalDays, 'created_by' => $by,
            ]);
            $this->scheduleMany($prospect, $type, $product, $windows, $employee, $extra, $series->id, false);

            return $series;
        });
    }

    /** Cancels the remaining future sessions of a series; completed or past sessions are untouched. */
    public function cancelSeries(AppointmentSeries $series, ?Appointment $from = null, ?string $reason = null): int
    {
        return DB::transaction(function () use ($series, $from, $reason) {
            $query = Appointment::query()->where('series_id', $series->id)
                ->where('status', Appointment::CONFIRMED)->where('starts_at', '>', now()->format('Y-m-d H:i:s'));

            if ($from) {
                $query->where('starts_at', '>=', $from->starts_at->format('Y-m-d H:i:s'));
            }

            return $query->update(['status' => Appointment::CANCELLED, 'customer_message' => $reason, 'slot_key' => null]);
        });
    }

    // ---------------------------------------------------------------- internals

    /**
     * @param  list<array{0: Carbon, 1: Carbon}>  $windows
     * @param  array<string, mixed>  $extra
     * @return list<Appointment>
     */
    private function scheduleMany(Prospect $prospect, AppointmentType $type, ?Product $product, array $windows, ?string $employee, array $extra, ?int $seriesId, bool $check = true): array
    {
        $run = function () use ($prospect, $type, $product, $windows, $employee, $extra, $seriesId, $check) {
            if ($check) {
                $this->scheduling->lockCalendar();
                $this->assertNoConflicts($windows, $employee, $prospect->id);
            }

            $kind = $type->group === 'training' ? $this->trainingKind($prospect, $product) : null;
            $created = [];

            foreach ($windows as [$start, $end]) {
                $created[] = Appointment::query()->create([
                    'appointment_type_id' => $type->id, 'prospect_id' => $prospect->id, 'product_id' => $product?->id,
                    'series_id' => $seriesId, 'title' => $type->name.($product ? ' - '.$product->name : ''),
                    'starts_at' => $start, 'ends_at' => $end, 'status' => Appointment::CONFIRMED,
                    'assigned_employee' => $employee, 'training_kind' => $kind,
                ] + $extra);
                $kind = $kind === 'first' ? 'follow_up' : $kind;
            }

            return $created;
        };

        return $check ? DB::transaction($run) : $run();
    }

    /** First training is per customer AND product, not global. */
    private function trainingKind(Prospect $prospect, ?Product $product): string
    {
        $exists = Appointment::query()
            ->whereHas('type', fn ($q) => $q->where('group', 'training'))
            ->where('prospect_id', $prospect->id)
            ->when($product, fn ($q) => $q->where('product_id', $product->id), fn ($q) => $q->whereNull('product_id'))
            ->whereIn('status', [Appointment::CONFIRMED, Appointment::COMPLETED])
            ->exists();

        return $exists ? 'follow_up' : 'first';
    }

    /**
     * @param  list<array{0: Carbon, 1: Carbon}>  $windows
     */
    private function assertNoConflicts(array $windows, ?string $employee, ?int $prospectId, ?int $ignoreId = null): void
    {
        $problems = [];

        foreach ($windows as $index => [$start, $end]) {
            if ($end->lte($start)) {
                throw new BusinessRuleException('The end time must be after the start time.', 'INVALID_TIME_RANGE', ['ends_at' => ['The end time must be after the start time.']]);
            }
            if ($this->scheduling->isHoliday($start)) {
                $problems[] = ['occurrence' => $index + 1, 'starts_at' => $start->toIso8601String(), 'reason' => 'holiday'];

                continue;
            }
            foreach ($this->scheduling->conflicts($start, $end, $employee, $prospectId, $ignoreId) as $other) {
                $problems[] = [
                    'occurrence' => $index + 1, 'starts_at' => $start->toIso8601String(),
                    'reason' => $other->assigned_employee === $employee && $employee !== null ? 'employee_busy' : 'customer_busy',
                    'conflicting_appointment_id' => $other->id,
                ];
            }
        }

        if ($problems !== []) {
            throw new BusinessRuleException(
                'The schedule conflicts with existing appointments or a holiday. Nothing was saved.',
                'APPOINTMENT_CONFLICT',
                ['conflicts' => $problems],
                409,
            );
        }
    }

    /** @param list<string> $from */
    private function release(Appointment $appointment, string $to, string $requestStatus, ?string $reason, ?string $by, array $from): Appointment
    {
        return DB::transaction(function () use ($appointment, $to, $requestStatus, $reason, $by, $from) {
            $locked = $this->lockFresh($appointment);
            $this->assertStatus($locked, $from, 'This appointment can no longer be changed.');
            $locked->update(['status' => $to, 'slot_key' => null, 'hold_expires_at' => null, 'customer_message' => $reason, 'decided_at' => now(), 'decided_by' => $by]);
            $this->syncRequest($locked, $requestStatus);

            return $locked;
        });
    }

    private function syncRequest(Appointment $appointment, string $status): void
    {
        PortalRequest::query()->where('appointment_id', $appointment->id)
            ->whereIn('status', ['pending', 'scheduled'])
            ->update(['status' => $status, 'customer_message' => $appointment->customer_message]);
    }

    private function lockFresh(Appointment $appointment): Appointment
    {
        return Appointment::query()->lockForUpdate()->findOrFail($appointment->id);
    }

    /** @param list<string> $allowed */
    private function assertStatus(Appointment $appointment, array $allowed, string $message): void
    {
        if (! in_array($appointment->status, $allowed, true)) {
            throw new BusinessRuleException($message, 'INVALID_APPOINTMENT_STATE', ['status' => [$message]]);
        }
    }

    private function assertNotExpired(Appointment $appointment): void
    {
        if ($appointment->isHoldExpired()) {
            $this->scheduling->releaseExpired();

            throw new BusinessRuleException('The hold on this time has expired and the slot was released.', 'HOLD_EXPIRED', [], 410);
        }
    }

    private function slotKey(int $lane, Carbon $start): string
    {
        return $lane.'|'.$start->format('Y-m-d H:i');
    }

    private function slotUnavailable(): BusinessRuleException
    {
        return new BusinessRuleException('This time was just taken. Please choose another slot.', 'SLOT_UNAVAILABLE', ['starts_at' => ['This time is no longer available.']], 409);
    }
}
