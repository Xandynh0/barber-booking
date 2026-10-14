<?php

namespace App\Services\Booking;

use App\Exceptions\BusinessConflictException;
use App\Models\Appointment;
use App\Models\BusinessSettings;
use App\Models\Professional;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Status changes made by the administrator from the agenda
 * (docs/planejamento-barbearia-mvp.md, seção 3):
 *
 * - confirmed → cancelled: any time while confirmed, even after the start
 *   and past the customer's deadline; `cancelled_by = admin` (seção 6);
 * - confirmed → completed: only once the appointment has ended;
 * - confirmed → no_show: only once it has started.
 *
 * cancelled, completed and no_show are final. Asking for the status the
 * reservation already has is idempotent — nothing is written and the
 * result says `changed: false`; any other move out of a final status is
 * refused with STATUS_TRANSITION_NOT_ALLOWED. Nothing is ever deleted:
 * snapshots, contacts and the interval stay as they were.
 *
 * Same lock order as every agenda write — business_settings first, then
 * the professional, then the reservation row — so a cancellation
 * serializes with a reservation being created on the freed interval.
 */
class AppointmentStatusChanger
{
    private const TRANSACTION_ATTEMPTS = 3;

    /**
     * @return array{appointment: Appointment, changed: bool}
     *
     * @throws BusinessConflictException
     */
    public function cancelByAdmin(string $publicId): array
    {
        return $this->transition($publicId, Appointment::STATUS_CANCELLED);
    }

    /**
     * @return array{appointment: Appointment, changed: bool}
     *
     * @throws BusinessConflictException
     */
    public function complete(string $publicId): array
    {
        return $this->transition($publicId, Appointment::STATUS_COMPLETED);
    }

    /**
     * @return array{appointment: Appointment, changed: bool}
     *
     * @throws BusinessConflictException
     */
    public function markNoShow(string $publicId): array
    {
        return $this->transition($publicId, Appointment::STATUS_NO_SHOW);
    }

    /**
     * The transitions this reservation allows right now — what the agenda
     * offers as actions, so the rules are not repeated in the browser.
     *
     * @return array<int, string>
     */
    public static function allowedTransitions(Appointment $appointment, ?CarbonImmutable $now = null): array
    {
        if ($appointment->status !== Appointment::STATUS_CONFIRMED) {
            return [];
        }

        $now ??= CarbonImmutable::now('UTC');
        $allowed = [Appointment::STATUS_CANCELLED];

        if ($now->greaterThanOrEqualTo($appointment->starts_at)) {
            $allowed[] = Appointment::STATUS_NO_SHOW;
        }

        if ($now->greaterThanOrEqualTo($appointment->ends_at)) {
            $allowed[] = Appointment::STATUS_COMPLETED;
        }

        return $allowed;
    }

    /**
     * @return array{appointment: Appointment, changed: bool}
     *
     * @throws BusinessConflictException
     */
    private function transition(string $publicId, string $target): array
    {
        return DB::transaction(function () use ($publicId, $target) {
            BusinessSettings::query()->lockForUpdate()->firstOrFail();

            $professionalId = Appointment::query()->where('public_id', $publicId)->value('professional_id');
            Professional::query()->lockForUpdate()->find($professionalId);

            $appointment = Appointment::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();

            if ($appointment->status === $target) {
                return ['appointment' => $appointment, 'changed' => false];
            }

            if (! in_array($target, self::allowedTransitions($appointment), true)) {
                throw BusinessConflictException::statusTransitionNotAllowed($appointment->status, match (true) {
                    $appointment->status !== Appointment::STATUS_CONFIRMED => 'final_state',
                    $target === Appointment::STATUS_NO_SHOW => 'not_started',
                    default => 'not_finished',
                });
            }

            $appointment->update($target === Appointment::STATUS_CANCELLED
                ? [
                    'status' => Appointment::STATUS_CANCELLED,
                    'cancelled_at' => CarbonImmutable::now('UTC'),
                    'cancelled_by' => Appointment::CANCELLED_BY_ADMIN,
                ]
                : ['status' => $target]);

            return ['appointment' => $appointment, 'changed' => true];
        }, self::TRANSACTION_ATTEMPTS);
    }
}
