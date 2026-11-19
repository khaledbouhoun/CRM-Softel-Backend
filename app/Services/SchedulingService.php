<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Appointment;
use App\Models\CrmSetting;
use App\Models\Holiday;
use App\Models\PortalRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Working calendar rules: settings, holidays, offered slots, expiry and conflict detection. */
class SchedulingService
{
    private const DATETIME = 'Y-m-d H:i:s';

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return CrmSetting::allEffective();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateSettings(array $data): array
    {
        $known = array_keys(config('crm.scheduling', []));

        foreach ($data as $key => $value) {
            if (in_array($key, $known, true)) {
                CrmSetting::write($key, $value);
            }
        }

        return $this->settings();
    }

    public function setting(string $key): mixed
    {
        return CrmSetting::read($key);
    }

    public function isHoliday(Carbon $day): bool
    {
        return Holiday::query()->where('is_active', true)->whereDate('date', $day->toDateString())->exists();
    }

    public function isWorkingDay(Carbon $day): bool
    {
        return in_array($day->dayOfWeek, array_map('intval', (array) $this->setting('working_days')), true)
            && ! $this->isHoliday($day);
    }

    /** Marks every held/proposed appointment whose hold ran out as expired and frees its slot. */
    public function releaseExpired(): int
    {
        $due = Appointment::query()
            ->whereIn('status', [Appointment::HELD, Appointment::PROPOSED])
            ->where('hold_expires_at', '<=', now()->format(self::DATETIME))
            ->pluck('id');

        if ($due->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($due) {
            Appointment::query()->whereIn('id', $due)->update(['status' => Appointment::EXPIRED, 'slot_key' => null]);
            PortalRequest::query()->whereIn('appointment_id', $due)->whereIn('status', ['pending', 'scheduled'])
                ->update(['status' => 'expired']);

            return $due->count();
        });
    }

    /**
     * Bookable demo slots between two dates (inclusive), after expiry release.
     *
     * @return list<array{starts_at: string, ends_at: string}>
     */
    public function availableSlots(Carbon $from, Carbon $to): array
    {
        $this->releaseExpired();

        $capacity = 1;
        $minutes = max(5, (int) $this->setting('slot_minutes'));
        $earliest = now()->addHours((int) $this->setting('min_notice_hours'));
        $latest = now()->addDays((int) $this->setting('booking_horizon_days'))->endOfDay();
        $to = $to->copy()->endOfDay()->min($latest);

        $busy = Appointment::query()->live()->overlapping($from->copy()->startOfDay(), $to)->get(['starts_at', 'ends_at']);
        $slots = [];

        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            if (! $this->isWorkingDay($day)) {
                continue;
            }

            foreach ($this->daySlots($day, $minutes) as [$start, $end]) {
                if ($start->lt($earliest)) {
                    continue;
                }

                $taken = $busy->filter(fn ($a) => $a->starts_at->lt($end) && $a->ends_at->gt($start))->count();

                if ($taken < $capacity) {
                    $slots[] = ['starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String()];
                }
            }
        }

        return $slots;
    }

    /** @return list<array{0: Carbon, 1: Carbon}> */
    private function daySlots(Carbon $day, int $minutes): array
    {
        [$sh, $sm] = array_map('intval', explode(':', (string) $this->setting('work_start')));
        [$eh, $em] = array_map('intval', explode(':', (string) $this->setting('work_end')));
        $cursor = $day->copy()->setTime($sh, $sm);
        $close = $day->copy()->setTime($eh, $em);
        $slots = [];

        while ($cursor->copy()->addMinutes($minutes)->lte($close)) {
            $slots[] = [$cursor->copy(), $cursor->copy()->addMinutes($minutes)];
            $cursor->addMinutes($minutes);
        }

        return $slots;
    }

    /** The start must be one of the configured slots (grid, working day, notice, horizon). */
    public function assertOffered(Carbon $start): void
    {
        $minutes = max(5, (int) $this->setting('slot_minutes'));
        $offered = $this->isWorkingDay($start)
            && collect($this->daySlots($start->copy()->startOfDay(), $minutes))->contains(fn ($s) => $s[0]->equalTo($start))
            && $start->gte(now()->addHours((int) $this->setting('min_notice_hours')))
            && $start->lte(now()->addDays((int) $this->setting('booking_horizon_days'))->endOfDay());

        if (! $offered) {
            throw new BusinessRuleException('This time is not an available appointment slot.', 'SLOT_NOT_OFFERED', ['starts_at' => ['This time is not an available appointment slot.']]);
        }
    }

    /** Serialises every calendar write (InnoDB row lock; a no-op on SQLite where writes are already serial). */
    public function lockCalendar(): void
    {
        DB::table('crm_settings')->insertOrIgnore(['key' => 'calendar_lock', 'value' => null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('crm_settings')->where('key', 'calendar_lock')->lockForUpdate()->first();
    }

    /**
     * Live appointments overlapping the window that belong to the same employee or customer.
     *
     * @return Collection<int, Appointment>
     */
    public function conflicts(Carbon $start, Carbon $end, ?string $employee, ?int $prospectId, ?int $ignoreId = null)
    {
        if ($employee === null && $prospectId === null) {
            return collect();
        }

        return Appointment::query()->live()->overlapping($start, $end)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where(function ($q) use ($employee, $prospectId) {
                if ($employee !== null) {
                    $q->orWhere('assigned_employee', $employee);
                }
                if ($prospectId !== null) {
                    $q->orWhere('prospect_id', $prospectId);
                }
            })
            ->get();
    }
}
