<?php

namespace App\Services\Agenda;

use App\Console\Commands\SendPendingConfirmations;
use App\Models\Appointment;
use App\Services\Booking\AppointmentStatusChanger;
use App\Services\Booking\ConfirmationResender;
use Carbon\CarbonImmutable;

/**
 * How a reservation is shown to the administrator. Two shapes:
 *
 * - `summary()` for the agenda grid/list: what is needed to place and
 *   label the card — no e-mail, no phone, no price;
 * - `details()` for the details panel: adds the contacts, the contracted
 *   price and the notification state.
 *
 * Both carry `allowed_actions`, computed here from the same rules the
 * write endpoints enforce, so the browser never re-implements them.
 * The service is always described by the snapshots taken at creation.
 */
class AdminAppointmentPresenter
{
    public const ACTION_CANCEL = 'cancel';

    public const ACTION_COMPLETE = 'complete';

    public const ACTION_NO_SHOW = 'no_show';

    public const ACTION_RESEND = 'resend_confirmation';

    /** No notification exists: no e-mail, or "Atender agora". */
    public const NOTIFICATION_NONE = 'none';

    /**
     * @return array<string, mixed>
     */
    public static function summary(Appointment $appointment, ?CarbonImmutable $now = null): array
    {
        return [
            'public_id' => $appointment->public_id,
            'professional_id' => $appointment->professional_id,
            'status' => $appointment->status,
            'source' => $appointment->source,
            'starts_at' => $appointment->starts_at->toIso8601String(),
            'ends_at' => $appointment->ends_at->toIso8601String(),
            'service' => [
                'id' => $appointment->service_id,
                'name' => $appointment->service_name_snapshot,
                'duration_minutes' => $appointment->duration_minutes_snapshot,
            ],
            'customer_name' => $appointment->customer_name,
            'has_email' => $appointment->customer_email !== null,
            'notification_status' => self::notificationStatus($appointment),
            'allowed_actions' => self::allowedActions($appointment, $now),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function details(Appointment $appointment, string $timezone): array
    {
        $appointment->loadMissing(['professional', 'confirmationNotification']);
        $notification = $appointment->confirmationNotification;

        return [
            'public_id' => $appointment->public_id,
            'status' => $appointment->status,
            'source' => $appointment->source,
            'starts_at' => $appointment->starts_at->toIso8601String(),
            'ends_at' => $appointment->ends_at->toIso8601String(),
            'timezone' => $timezone,
            'service' => [
                'id' => $appointment->service_id,
                'name' => $appointment->service_name_snapshot,
                'duration_minutes' => $appointment->duration_minutes_snapshot,
                'price' => $appointment->price_snapshot,
            ],
            'professional' => [
                'id' => $appointment->professional->id,
                'name' => $appointment->professional->name,
            ],
            'customer' => [
                'name' => $appointment->customer_name,
                'email' => $appointment->customer_email,
                'phone' => $appointment->customer_phone,
            ],
            'notification' => $notification === null ? null : [
                'status' => $notification->status,
                'attempts' => $notification->attempts,
                'max_attempts' => SendPendingConfirmations::MAX_ATTEMPTS,
                'sent_at' => $notification->sent_at?->toIso8601String(),
            ],
            'notification_status' => self::notificationStatus($appointment),
            'cancelled_at' => $appointment->cancelled_at?->toIso8601String(),
            'cancelled_by' => $appointment->cancelled_by,
            'created_at' => $appointment->created_at?->toIso8601String(),
            'allowed_actions' => self::allowedActions($appointment),
        ];
    }

    public static function notificationStatus(Appointment $appointment): string
    {
        $appointment->loadMissing('confirmationNotification');

        return $appointment->confirmationNotification?->status ?? self::NOTIFICATION_NONE;
    }

    /**
     * @return array<int, string>
     */
    public static function allowedActions(Appointment $appointment, ?CarbonImmutable $now = null): array
    {
        $actions = array_map(fn (string $status) => match ($status) {
            Appointment::STATUS_CANCELLED => self::ACTION_CANCEL,
            Appointment::STATUS_COMPLETED => self::ACTION_COMPLETE,
            Appointment::STATUS_NO_SHOW => self::ACTION_NO_SHOW,
        }, AppointmentStatusChanger::allowedTransitions($appointment, $now));

        $appointment->loadMissing('confirmationNotification');
        $attempts = $appointment->confirmationNotification?->attempts ?? 0;

        if (ConfirmationResender::refusal($appointment) === null && $attempts < SendPendingConfirmations::MAX_ATTEMPTS) {
            $actions[] = self::ACTION_RESEND;
        }

        return $actions;
    }
}
