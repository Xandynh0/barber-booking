<?php

namespace App\Services\Booking;

use App\Exceptions\BusinessConflictException;
use App\Models\Appointment;
use App\Models\BusinessSettings;
use App\Models\Professional;
use App\Services\Availability\AvailabilityEngine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Agenda changes that would break an existing reservation are refused,
 * listing the conflicts — never silently cancelling anything
 * (docs/planejamento-barbearia-mvp.md, seção 7). Must be called inside the
 * writing transaction, after the business_settings lock.
 */
class AgendaConflicts
{
    public function __construct(private readonly AvailabilityEngine $engine) {}

    /**
     * "Criar bloqueio em cima de reserva confirmada é recusado; exibir os
     * conflitos." Any non-cancelled reservation of these professionals that
     * intersects [$startsAt, $endsAt) blocks the new schedule block.
     *
     * @param  array<int, int>  $professionalIds
     *
     * @throws BusinessConflictException
     */
    public function assertBlockFree(array $professionalIds, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        $conflicts = Appointment::query()
            ->occupying()
            ->with('professional')
            ->whereIn('professional_id', $professionalIds)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->orderBy('starts_at')
            ->get();

        if ($conflicts->isNotEmpty()) {
            throw $this->conflict('errors.appointment_conflict_block', $conflicts);
        }
    }

    /**
     * "Reduzir expediente de modo que exclua uma reserva futura confirmada é
     * recusado." Checked against the working hours as they are *now* — call
     * it after writing the new periods, inside the same transaction, so a
     * refusal rolls the change back.
     *
     * @throws BusinessConflictException
     */
    public function assertFutureAppointmentsFitWorkingHours(Professional $professional): void
    {
        $conflicts = Appointment::query()
            ->with('professional')
            ->where('professional_id', $professional->id)
            ->where('status', Appointment::STATUS_CONFIRMED)
            ->where('starts_at', '>', CarbonImmutable::now('UTC'))
            ->orderBy('starts_at')
            ->get()
            ->reject(fn (Appointment $appointment) => $this->engine->fitsWorkingHours(
                $professional,
                CarbonImmutable::instance($appointment->starts_at),
                CarbonImmutable::instance($appointment->ends_at),
            ))
            ->values();

        if ($conflicts->isNotEmpty()) {
            throw $this->conflict('errors.appointment_conflict_working_hours', $conflicts);
        }
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     */
    private function conflict(string $messageKey, Collection $appointments): BusinessConflictException
    {
        $timezone = BusinessSettings::query()->firstOrFail()->timezone;
        $dateFormat = app()->getLocale() === 'en' ? 'm/d/Y' : 'd/m/Y';

        $list = $appointments->map(function (Appointment $appointment) use ($timezone, $dateFormat) {
            $start = CarbonImmutable::instance($appointment->starts_at)->setTimezone($timezone);
            $end = CarbonImmutable::instance($appointment->ends_at)->setTimezone($timezone);

            return $start->format($dateFormat.' H:i').'–'.$end->format('H:i').' ('.$appointment->professional->name.')';
        })->implode('; ');

        return new BusinessConflictException('APPOINTMENT_CONFLICT', __($messageKey, ['list' => $list]), [
            'conflicts' => $appointments->map(fn (Appointment $appointment) => [
                'public_id' => $appointment->public_id,
                'professional' => ['id' => $appointment->professional->id, 'name' => $appointment->professional->name],
                'customer_name' => $appointment->customer_name,
                'starts_at' => $appointment->starts_at->toIso8601String(),
                'ends_at' => $appointment->ends_at->toIso8601String(),
            ])->all(),
        ]);
    }
}
