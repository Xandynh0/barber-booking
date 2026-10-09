<?php

namespace App\Services\Booking;

use App\Mail\AppointmentConfirmationMail;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\BusinessSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers a reservation's confirmation e-mail — always after the
 * reservation's transaction has committed, never inside it
 * (docs/planejamento-barbearia-mvp.md, seção 5, step 6): the request path
 * calls it once DB::transaction() has returned, and the
 * `appointments:send-pending-confirmations` sweep retries what is left.
 *
 * - A failed send never undoes the reservation: it is recorded as `failed`
 *   with the exception class only (no message, which may carry addresses).
 * - Before sending, the reservation must still be confirmed and in the
 *   future; otherwise the notification is `skipped`.
 * - Two senders cannot both deliver the same notification: each claims it
 *   with a conditional UPDATE on `attempts`, and only the one that changed
 *   the row sends. An external provider may still deliver twice after a
 *   timeout — accepted by the plan.
 */
class ConfirmationNotifier
{
    public function deliver(AppointmentNotification $notification): string
    {
        $claimed = AppointmentNotification::query()
            ->whereKey($notification->id)
            ->whereIn('status', [AppointmentNotification::STATUS_PENDING, AppointmentNotification::STATUS_FAILED])
            ->where('attempts', $notification->attempts)
            ->update(['attempts' => $notification->attempts + 1, 'updated_at' => now()]);

        $notification->refresh();

        if ($claimed === 0) {
            return $notification->status;
        }

        $appointment = Appointment::query()->with('professional')->findOrFail($notification->appointment_id);

        if ($appointment->status !== Appointment::STATUS_CONFIRMED
            || $appointment->customer_email === null
            || ! $appointment->starts_at->isFuture()) {
            $notification->update(['status' => AppointmentNotification::STATUS_SKIPPED]);

            return $notification->status;
        }

        try {
            Mail::to($appointment->customer_email)->send(new AppointmentConfirmationMail(
                $appointment,
                BusinessSettings::query()->firstOrFail(),
                CancellationLink::for($appointment),
            ));
        } catch (Throwable $exception) {
            $notification->update([
                'status' => AppointmentNotification::STATUS_FAILED,
                'last_error_code' => class_basename($exception),
            ]);
            Log::warning('Appointment confirmation e-mail failed.', [
                'notification_id' => $notification->id,
                'error' => class_basename($exception),
            ]);

            return $notification->status;
        }

        $notification->update([
            'status' => AppointmentNotification::STATUS_SENT,
            'sent_at' => now(),
            'last_error_code' => null,
        ]);

        return $notification->status;
    }
}
