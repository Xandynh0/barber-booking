<?php

namespace App\Services\Agenda;

use App\Models\Appointment;
use App\Models\BusinessSettings;
use App\Models\Professional;
use App\Models\ScheduleBlock;
use App\Services\Availability\AvailabilityEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * GET /api/v1/admin/agenda — one local day of the barbershop, for the
 * desktop grid (time rows × professional columns) and the mobile list
 * (the same items in chronological order). Everything placed on the day is
 * computed here, so the browser only draws:
 *
 * - `day`: the UTC bounds of the local calendar day [00:00, next 00:00) in
 *   the barbershop's timezone (23 or 25 hours on a DST change).
 * - `hours`: the range worth drawing — from the earliest to the latest
 *   working period, appointment or block of the shown professionals,
 *   widened to whole hours and clipped to the day; null when there is
 *   nothing at all on that day.
 * - Every appointment and block that *intersects* the day is returned, with
 *   its real `starts_at`/`ends_at` and a `visible_*` interval clipped to
 *   the day; `continues_before`/`continues_after` say it crosses midnight.
 *   Appointments cannot cross midnight today (they must fit one working
 *   period, and periods never cross it), but blocks and closures can span
 *   days.
 * - `breaks`: the gaps between a professional's consecutive working periods
 *   (lunch), shown as "Intervalo".
 *
 * Professionals: the active ones, plus any inactive one that still has a
 * reservation on that day (so it is never hidden), ordered by name; or only
 * the one asked for. Cancelled appointments are listed too, with their
 * status, so the day keeps its history.
 */
class AgendaBuilder
{
    public function __construct(private readonly AvailabilityEngine $engine) {}

    /**
     * @return array<string, mixed>
     */
    public function forDate(string $date, ?int $professionalId): array
    {
        $timezone = BusinessSettings::query()->firstOrFail()->timezone;
        $dayStart = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone)->utc();
        $dayEnd = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone)->addDay()->utc();
        $now = CarbonImmutable::now('UTC');

        $appointments = Appointment::query()
            ->with('confirmationNotification')
            ->where('starts_at', '<', $dayEnd)
            ->where('ends_at', '>', $dayStart)
            ->when($professionalId !== null, fn ($query) => $query->where('professional_id', $professionalId))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $professionals = $this->professionals($professionalId, $appointments);
        $professionalIds = $professionals->pluck('id')->all();

        $appointments = $appointments->whereIn('professional_id', $professionalIds)->values();

        $blocks = ScheduleBlock::query()
            ->whereIn('professional_id', $professionalIds)
            ->where('starts_at', '<', $dayEnd)
            ->where('ends_at', '>', $dayStart)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $professionalRows = $professionals->map(function (Professional $professional) use ($date) {
            $periods = $this->engine->workingPeriodsOn($professional, $date);
            $breaks = [];
            for ($i = 1; $i < count($periods); $i++) {
                if ($periods[$i - 1]['ends_at'] < $periods[$i]['starts_at']) {
                    $breaks[] = ['starts_at' => $periods[$i - 1]['ends_at'], 'ends_at' => $periods[$i]['starts_at']];
                }
            }

            return [
                'id' => $professional->id,
                'name' => $professional->name,
                'is_active' => $professional->is_active,
                'working_periods' => array_map(fn (array $period) => $this->interval($period['starts_at'], $period['ends_at']), $periods),
                'breaks' => array_map(fn (array $gap) => $this->interval($gap['starts_at'], $gap['ends_at']), $breaks),
                '_periods' => $periods,
            ];
        })->values();

        $appointmentRows = $appointments->map(fn (Appointment $appointment) => [
            ...AdminAppointmentPresenter::summary($appointment, $now),
            ...$this->visible($appointment->starts_at, $appointment->ends_at, $dayStart, $dayEnd),
        ])->all();

        $blockRows = $blocks->map(fn (ScheduleBlock $block) => [
            'id' => $block->id,
            'kind' => $block->group_id === null ? 'professional' : 'shop',
            'group_id' => $block->group_id,
            'professional_id' => $block->professional_id,
            'reason' => $block->reason,
            'starts_at' => $block->starts_at->toIso8601String(),
            'ends_at' => $block->ends_at->toIso8601String(),
            ...$this->visible($block->starts_at, $block->ends_at, $dayStart, $dayEnd),
        ])->all();

        return [
            'date' => $date,
            'timezone' => $timezone,
            'now' => $now->toIso8601String(),
            'day' => $this->interval($dayStart, $dayEnd),
            'hours' => $this->hours($professionalRows, $appointments, $blocks, $dayStart, $dayEnd, $timezone),
            'professionals' => $professionalRows->map(fn (array $row) => collect($row)->except('_periods')->all())->all(),
            'appointments' => $appointmentRows,
            'blocks' => $blockRows,
        ];
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     * @return Collection<int, Professional>
     */
    private function professionals(?int $professionalId, Collection $appointments): Collection
    {
        if ($professionalId !== null) {
            return Professional::query()->whereKey($professionalId)->get();
        }

        $withAppointments = $appointments->pluck('professional_id')->unique()->all();

        return Professional::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $withAppointments))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $professionalRows
     * @param  Collection<int, Appointment>  $appointments
     * @param  Collection<int, ScheduleBlock>  $blocks
     * @return array{starts_at: string, ends_at: string}|null
     */
    private function hours(Collection $professionalRows, Collection $appointments, Collection $blocks, CarbonImmutable $dayStart, CarbonImmutable $dayEnd, string $timezone): ?array
    {
        $intervals = $professionalRows->flatMap(fn (array $row) => $row['_periods'])
            ->concat($appointments->map(fn (Appointment $a) => ['starts_at' => CarbonImmutable::instance($a->starts_at), 'ends_at' => CarbonImmutable::instance($a->ends_at)]))
            ->concat($blocks->map(fn (ScheduleBlock $b) => ['starts_at' => CarbonImmutable::instance($b->starts_at), 'ends_at' => CarbonImmutable::instance($b->ends_at)]));

        if ($intervals->isEmpty()) {
            return null;
        }

        $from = max($dayStart, $intervals->min('starts_at'));
        $until = min($dayEnd, $intervals->max('ends_at'));

        $from = $from->setTimezone($timezone)->startOfHour()->utc();
        $localUntil = $until->setTimezone($timezone);
        if ($localUntil->minute !== 0 || $localUntil->second !== 0) {
            $until = $localUntil->startOfHour()->addHour()->utc();
        }

        return $this->interval(max($dayStart, $from), min($dayEnd, $until));
    }

    /**
     * @return array{visible_starts_at: string, visible_ends_at: string, continues_before: bool, continues_after: bool}
     */
    private function visible(\DateTimeInterface $startsAt, \DateTimeInterface $endsAt, CarbonImmutable $dayStart, CarbonImmutable $dayEnd): array
    {
        $start = CarbonImmutable::instance($startsAt);
        $end = CarbonImmutable::instance($endsAt);

        return [
            'visible_starts_at' => max($start, $dayStart)->toIso8601String(),
            'visible_ends_at' => min($end, $dayEnd)->toIso8601String(),
            'continues_before' => $start < $dayStart,
            'continues_after' => $end > $dayEnd,
        ];
    }

    /**
     * @return array{starts_at: string, ends_at: string}
     */
    private function interval(CarbonImmutable $startsAt, CarbonImmutable $endsAt): array
    {
        return ['starts_at' => $startsAt->toIso8601String(), 'ends_at' => $endsAt->toIso8601String()];
    }
}
