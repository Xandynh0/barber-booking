<?php

namespace App\Services\Availability;

use App\Models\Appointment;
use App\Models\BusinessSettings;
use App\Models\Professional;
use App\Models\ScheduleBlock;
use App\Models\Service;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;

/**
 * The single availability engine, shared by the public and the admin
 * contexts. Rules (docs/planejamento-barbearia-mvp.md, seções 1, 5 e 16):
 *
 * - service and professional active, and linked to each other;
 * - the requested date (in the barbershop's timezone) within the booking
 *   horizon: today plus `booking_horizon_days - 1` days, in both contexts;
 * - the whole service duration contained in a single working period;
 * - no intersection with schedule blocks or non-cancelled appointments,
 *   using half-open intervals `[start, end)` — a slot may start exactly
 *   when the previous one ends;
 * - public: start at least `min_notice_minutes` from now; admin: start not
 *   in the past (minimum notice ignored);
 * - candidates on fixed quarter hours of the barbershop's local clock
 *   (:00, :15, :30, :45), each working period's start rounded up to the
 *   next quarter.
 *
 * By itself this is a read-only suggestion and takes no locks. The
 * guarantee against two simultaneous reservations for overlapping
 * intervals comes from AppointmentBooker, which calls isSlotAvailable()
 * inside the creating transaction after locking business_settings and the
 * professional.
 */
class AvailabilityEngine
{
    public const SLOT_STEP_MINUTES = 15;

    /**
     * @param  string  $date  Calendar date `YYYY-MM-DD`, interpreted in the barbershop's timezone.
     * @return array<int, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}> Slots in UTC, ordered by start.
     */
    public function slotsFor(Service $service, Professional $professional, string $date, AvailabilityContext $context): array
    {
        $settings = BusinessSettings::query()->firstOrFail();
        $timezone = $settings->timezone;
        $now = CarbonImmutable::now('UTC');

        if (! $this->isWithinHorizon($date, $now, $timezone, (int) $settings->booking_horizon_days)) {
            return [];
        }

        if (! $this->isBookableCombination($service, $professional)) {
            return [];
        }

        $windows = $this->workingWindows($professional, $date, $timezone);

        if ($windows === []) {
            return [];
        }

        $earliestStart = $context === AvailabilityContext::Public
            ? $now->addMinutes((int) $settings->min_notice_minutes)
            : $now;

        $busy = $this->busyIntervals(
            $professional,
            min(array_column($windows, 'starts_at')),
            max(array_column($windows, 'ends_at')),
        );

        $duration = (int) $service->duration_minutes;
        $slots = [];

        foreach ($windows as $window) {
            $candidate = $this->ceilToLocalQuarter($window['starts_at'], $timezone);

            while ($candidate->addMinutes($duration) <= $window['ends_at']) {
                $end = $candidate->addMinutes($duration);

                if ($candidate >= $earliestStart && ! $this->overlapsAny($candidate, $end, $busy)) {
                    $slots[] = ['starts_at' => $candidate, 'ends_at' => $end];
                }

                $candidate = $candidate->addMinutes(self::SLOT_STEP_MINUTES);
            }
        }

        return $slots;
    }

    /**
     * Whether `$startsAt` is one of the slots this engine offers right now,
     * applying every rule of `slotsFor()` — this is how a reservation is
     * revalidated at creation, so the rules live in exactly one place.
     *
     * Safe against concurrent reservations only when called inside the
     * creating transaction, after the business_settings and professional
     * row locks were taken (see AppointmentBooker): a plain call is just
     * another read.
     */
    public function isSlotAvailable(Service $service, Professional $professional, CarbonImmutable $startsAt, AvailabilityContext $context): bool
    {
        $timezone = BusinessSettings::query()->firstOrFail()->timezone;
        $localDate = $startsAt->setTimezone($timezone)->format('Y-m-d');

        foreach ($this->slotsFor($service, $professional, $localDate, $context) as $slot) {
            if ($slot['starts_at']->equalTo($startsAt)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether [$startsAt, $endsAt) lies entirely inside one of the
     * professional's current working periods on that local date. Unlike
     * `isSlotAvailable()`, it ignores the slot grid, notice and horizon: it
     * answers only "is this already-booked interval still within working
     * hours?" (docs/planejamento-barbearia-mvp.md, seção 7).
     */
    public function fitsWorkingHours(Professional $professional, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        $timezone = BusinessSettings::query()->firstOrFail()->timezone;
        $localDate = $startsAt->setTimezone($timezone)->format('Y-m-d');

        foreach ($this->workingWindows($professional, $localDate, $timezone) as $window) {
            if ($startsAt >= $window['starts_at'] && $endsAt <= $window['ends_at']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this professional may currently be offered for this service.
     * The same check applies to both contexts.
     */
    public function isBookableCombination(Service $service, Professional $professional): bool
    {
        return $service->is_active
            && $professional->is_active
            && $professional->services()->whereKey($service->id)->exists();
    }

    /**
     * `booking_horizon_days` counts available calendar dates including
     * today, in the barbershop's timezone: 30 means today through today+29.
     */
    private function isWithinHorizon(string $date, CarbonImmutable $now, string $timezone, int $horizonDays): bool
    {
        $today = $now->setTimezone($timezone)->startOfDay();
        $lastDate = $today->addDays($horizonDays - 1);

        return $horizonDays > 0
            && $date >= $today->format('Y-m-d')
            && $date <= $lastDate->format('Y-m-d');
    }

    /**
     * The professional's working periods on a local date, as UTC instants.
     *
     * @return array<int, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    private function workingWindows(Professional $professional, string $date, string $timezone): array
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);

        return WorkingHour::query()
            ->where('professional_id', $professional->id)
            ->where('weekday', $day->dayOfWeek)
            ->orderBy('start_time')
            ->get()
            ->map(fn (WorkingHour $period) => [
                'starts_at' => $this->localInstant($day, $period->start_time, $timezone),
                'ends_at' => $this->localInstant($day, $period->end_time, $timezone),
            ])
            ->all();
    }

    /**
     * A wall-clock time on the given local date, as a UTC instant. On a
     * daylight-saving transition day PHP resolves a non-existent local time
     * (spring forward) to the next valid instant and an ambiguous one (fall
     * back) to the first occurrence.
     */
    private function localInstant(CarbonImmutable $day, string $time, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $day->format('Y-m-d').' '.$time, $timezone)->utc();
    }

    /**
     * Rounds up to the next :00/:15/:30/:45 of the barbershop's local clock.
     */
    private function ceilToLocalQuarter(CarbonImmutable $instant, string $timezone): CarbonImmutable
    {
        $local = $instant->setTimezone($timezone);
        $remainder = $local->minute % self::SLOT_STEP_MINUTES;

        if ($remainder === 0 && $local->second === 0) {
            return $instant;
        }

        return $local->second(0)->addMinutes(self::SLOT_STEP_MINUTES - $remainder)->utc();
    }

    /**
     * Blocks and non-cancelled appointments of this professional that
     * intersect [$from, $until), as pairs of Unix timestamps.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private function busyIntervals(Professional $professional, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $blocks = ScheduleBlock::query()
            ->where('professional_id', $professional->id)
            ->where('starts_at', '<', $until)
            ->where('ends_at', '>', $from)
            ->get(['starts_at', 'ends_at']);

        $appointments = Appointment::query()
            ->occupying()
            ->where('professional_id', $professional->id)
            ->where('starts_at', '<', $until)
            ->where('ends_at', '>', $from)
            ->get(['starts_at', 'ends_at']);

        return $blocks->concat($appointments)
            ->map(fn (ScheduleBlock|Appointment $row) => [$row->starts_at->getTimestamp(), $row->ends_at->getTimestamp()])
            ->all();
    }

    /**
     * Half-open intervals: [a, b) and [c, d) conflict when a < d and b > c.
     *
     * @param  array<int, array{0: int, 1: int}>  $busy
     */
    private function overlapsAny(CarbonImmutable $start, CarbonImmutable $end, array $busy): bool
    {
        $startTimestamp = $start->getTimestamp();
        $endTimestamp = $end->getTimestamp();

        foreach ($busy as [$busyStart, $busyEnd]) {
            if ($busyStart < $endTimestamp && $busyEnd > $startTimestamp) {
                return true;
            }
        }

        return false;
    }
}
