<?php

namespace App\Services\Booking;

use App\Console\Commands\SendPendingConfirmations;
use App\Exceptions\BusinessConflictException;
use App\Models\Appointment;
use App\Models\AppointmentNotification;

/**
 * "Reenviar confirmação" from the agenda (docs/planejamento-barbearia-
 * mvp.md, seções 3 e 6). Only for a confirmed, future reservation with an
 * e-mail. It reuses the single logical notification of the reservation
 * (`appointment_id` + `kind` is unique) and delivers it through
 * ConfirmationNotifier, the same path as the request and the sweep, with a
 * new signed cancellation link.
 *
 * - Attempts are bounded by the same ceiling as the sweep
 *   (SendPendingConfirmations::MAX_ATTEMPTS), counted over the life of the
 *   notification, automatic and manual together.
 * - No transaction or agenda lock is held while talking to SMTP: the
 *   notification is claimed with ConfirmationNotifier's conditional UPDATE,
 *   so a resend racing the sweep delivers at most once per attempt.
 * - The result is the outcome of this attempt (`sent`, `failed`, or the
 *   status left by a concurrent sender), never a delivery guarantee; a
 *   provider timeout after acceptance can still produce a duplicate e-mail.
 */
class ConfirmationResender
{
    public function __construct(private readonly ConfirmationNotifier $notifier) {}

    /**
     * @throws BusinessConflictException
     */
    public function resend(Appointment $appointment): AppointmentNotification
    {
        $reason = self::refusal($appointment);
        if ($reason !== null) {
            throw BusinessConflictException::resendNotAllowed($reason);
        }

        $notification = AppointmentNotification::query()->firstOrCreate(
            ['appointment_id' => $appointment->id, 'kind' => AppointmentNotification::KIND_CONFIRMATION],
            ['status' => AppointmentNotification::STATUS_PENDING],
        );

        if ($notification->attempts >= SendPendingConfirmations::MAX_ATTEMPTS) {
            throw BusinessConflictException::resendNotAllowed('attempts_exhausted');
        }

        // Back to `pending` only if nobody touched it since it was read; the
        // notifier then claims it by its attempt count.
        AppointmentNotification::query()
            ->whereKey($notification->id)
            ->where('attempts', $notification->attempts)
            ->update(['status' => AppointmentNotification::STATUS_PENDING]);

        $this->notifier->deliver($notification->refresh());

        return $notification->refresh();
    }

    /**
     * Why a resend is not possible right now, or null when it is. Also used
     * by the agenda to offer the action only when it applies.
     */
    public static function refusal(Appointment $appointment): ?string
    {
        return match (true) {
            $appointment->customer_email === null => 'no_email',
            $appointment->status !== Appointment::STATUS_CONFIRMED => 'not_confirmed',
            ! $appointment->starts_at->isFuture() => 'not_future',
            default => null,
        };
    }
}
