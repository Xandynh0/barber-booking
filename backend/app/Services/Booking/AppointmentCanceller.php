<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\BusinessSettings;
use App\Models\Professional;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cancellation by the customer, through the signed link
 * (docs/planejamento-barbearia-mvp.md, seção 6). Nothing is deleted: the
 * reservation keeps its history, gets `cancelled`, `cancelled_at` and
 * `cancelled_by = customer`, and stops occupying the slot.
 *
 * Idempotent: cancelling an already-cancelled reservation changes nothing
 * and reports `already_cancelled`. Same lock order as every agenda write —
 * business_settings first, then the professional, then the reservation
 * row — so it serializes with reservation creation.
 */
class AppointmentCanceller
{
    public const CANCELLED = 'cancelled';

    public const ALREADY_CANCELLED = 'already_cancelled';

    /** Past `starts_at - cancel_min_notice_minutes`: too late to cancel online. */
    public const DEADLINE_PASSED = 'deadline_passed';

    public function cancelByCustomer(string $publicId): string
    {
        return DB::transaction(function () use ($publicId) {
            $settings = BusinessSettings::query()->lockForUpdate()->firstOrFail();

            $professionalId = Appointment::query()->where('public_id', $publicId)->value('professional_id');
            Professional::query()->lockForUpdate()->find($professionalId);

            $appointment = Appointment::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();

            if ($appointment->status === Appointment::STATUS_CANCELLED) {
                return self::ALREADY_CANCELLED;
            }

            if (! self::canStillBeCancelled($appointment, (int) $settings->cancel_min_notice_minutes)) {
                return self::DEADLINE_PASSED;
            }

            $appointment->update([
                'status' => Appointment::STATUS_CANCELLED,
                'cancelled_at' => CarbonImmutable::now('UTC'),
                'cancelled_by' => 'customer',
            ]);

            return self::CANCELLED;
        }, 3);
    }

    /**
     * `now < starts_at - cancel_min_notice_minutes`. With the default 0,
     * only before the start. A configuration change applies to links
     * already sent.
     */
    public static function canStillBeCancelled(Appointment $appointment, int $cancelMinNoticeMinutes): bool
    {
        return CarbonImmutable::now('UTC')->lt(
            CarbonImmutable::instance($appointment->starts_at)->subMinutes($cancelMinNoticeMinutes),
        );
    }
}
