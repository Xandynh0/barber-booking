<?php

namespace App\Services\Booking;

use App\Exceptions\BusinessConflictException;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\BusinessSettings;
use App\Models\Professional;
use App\Models\Service;
use App\Services\Availability\AvailabilityContext;
use App\Services\Availability\AvailabilityEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a reservation, public or by the administrator. This is where the guarantee against double
 * booking lives — docs/planejamento-barbearia-mvp.md, seção 5:
 *
 * 1. Lock the single business_settings row. It MUST be the first statement
 *    of the transaction: under InnoDB's REPEATABLE READ the read view is
 *    created by the first non-locking read, so any plain SELECT before the
 *    lock would freeze a snapshot taken before a concurrent transaction
 *    committed its reservation — and the revalidation below would not see
 *    it. Every write path that changes the agenda takes this same lock
 *    first, which serializes them.
 * 2. Lock the professional row (after the global lock, never before — fixed
 *    order, so two writers cannot hold them in opposite orders).
 * 3. Idempotency: a repeated key with the same fingerprint returns the
 *    existing reservation before any other rule (including the contact
 *    limit); the same key with a different payload is refused.
 * 4. Revalidate from current data with the availability engine — the slot
 *    returned earlier by GET /availability is never trusted.
 * 5. Contact limit, counted separately per canonical e-mail and per phone.
 * 6. Insert with server-computed end and snapshots; commit.
 *
 * Deadlocks and lock-wait timeouts are retried a limited number of times by
 * DB::transaction(). The confirmation e-mail is only recorded here
 * (`pending`); it is sent after commit by ConfirmationNotifier.
 */
class AppointmentBooker
{
    private const TRANSACTION_ATTEMPTS = 3;

    public const MODE_SCHEDULED = 'scheduled';

    public const MODE_NOW = 'now';

    public function __construct(private readonly AvailabilityEngine $engine) {}

    /**
     * @param  array{service_id: int, professional_id: int, starts_at: CarbonImmutable, customer_name: string, customer_email: string, customer_phone: string}  $booking
     *                                                                                                                                                                    Already validated; contacts already in canonical form; starts_at in UTC.
     * @return array{appointment: Appointment, replayed: bool}
     *
     * @throws BusinessConflictException
     * @throws ValidationException
     */
    public function bookPublic(array $booking, string $idempotencyKey): array
    {
        $fingerprint = $this->fingerprint(Appointment::SOURCE_PUBLIC, $booking);

        return DB::transaction(function () use ($booking, $idempotencyKey, $fingerprint) {
            $settings = BusinessSettings::query()->lockForUpdate()->firstOrFail();
            $professional = Professional::query()->lockForUpdate()->find($booking['professional_id']);

            $existing = Appointment::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw BusinessConflictException::idempotencyKeyReused();
                }

                return ['appointment' => $existing, 'replayed' => true];
            }

            $service = Service::query()->find($booking['service_id']);
            $this->assertBookable($service, $professional);

            if (! $this->engine->isSlotAvailable($service, $professional, $booking['starts_at'], AvailabilityContext::Public)) {
                throw BusinessConflictException::slotUnavailable();
            }

            $this->assertWithinContactLimit($booking['customer_email'], $booking['customer_phone'], (int) $settings->max_active_per_contact);

            $appointment = $this->insert($service, $professional, $booking, $booking['starts_at'], Appointment::SOURCE_PUBLIC, $idempotencyKey, $fingerprint);

            return ['appointment' => $appointment, 'replayed' => false];
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Creates a reservation for the authenticated administrator
     * (docs/planejamento-barbearia-mvp.md, seções 1, 3, 5 e 11). Same
     * transaction protocol as bookPublic(), with the admin rules:
     *
     * - `scheduled`: `starts_at` must be one of the slots the engine offers
     *   in the admin context — minimum notice ignored, horizon kept (seção
     *   1: "limite de horizonte permanece para reservas futuras do admin"),
     *   never in the past, on the 15-minute grid.
     * - `now` ("Atender agora"): the start is the server's current instant,
     *   truncated to the minute, taken *inside* the transaction after the
     *   locks — never sent by the browser, never rounded to the grid. The
     *   whole service must still fit one working period, with no block or
     *   reservation in the way; otherwise it is refused with the reason,
     *   never moved to another time.
     * - No contact limit; e-mail and phone are optional. Without an e-mail
     *   no notification is created. A `now` reservation never gets one
     *   either: its cancellation link would already be expired (seção 6).
     *
     * @param  array{mode: string, service_id: int, professional_id: int, starts_at: ?CarbonImmutable, customer_name: string, customer_email: ?string, customer_phone: ?string}  $booking
     * @return array{appointment: Appointment, replayed: bool}
     *
     * @throws BusinessConflictException
     * @throws ValidationException
     */
    public function bookAdmin(array $booking, string $idempotencyKey): array
    {
        $fingerprint = $this->fingerprint(Appointment::SOURCE_ADMIN, $booking);

        return DB::transaction(function () use ($booking, $idempotencyKey, $fingerprint) {
            $settings = BusinessSettings::query()->lockForUpdate()->firstOrFail();
            $professional = Professional::query()->lockForUpdate()->find($booking['professional_id']);

            $existing = Appointment::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw BusinessConflictException::idempotencyKeyReused();
                }

                return ['appointment' => $existing, 'replayed' => true];
            }

            $service = Service::query()->find($booking['service_id']);
            $this->assertBookable($service, $professional);

            if ($booking['mode'] === self::MODE_NOW) {
                $startsAt = CarbonImmutable::now($settings->timezone)->startOfMinute()->utc();
                $refusal = $this->engine->intervalRefusal($professional, $startsAt, $startsAt->addMinutes((int) $service->duration_minutes));
                if ($refusal !== null) {
                    throw BusinessConflictException::slotUnavailableBecause($refusal);
                }
            } else {
                $startsAt = $booking['starts_at'];
                if (! $this->engine->isSlotAvailable($service, $professional, $startsAt, AvailabilityContext::Admin)) {
                    throw BusinessConflictException::slotUnavailableBecause(
                        $this->engine->refusalFor($service, $professional, $startsAt, AvailabilityContext::Admin),
                    );
                }
            }

            $appointment = $this->insert($service, $professional, $booking, $startsAt, Appointment::SOURCE_ADMIN, $idempotencyKey, $fingerprint);

            return ['appointment' => $appointment, 'replayed' => false];
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Inserts with server-computed end and snapshots. Seção 5, step 5: the
     * confirmation is recorded in the same transaction (so a rollback
     * leaves no orphan notification) — only with an e-mail and a future
     * start — and delivered after commit by ConfirmationNotifier.
     *
     * @param  array<string, mixed>  $booking
     */
    private function insert(Service $service, Professional $professional, array $booking, CarbonImmutable $startsAt, string $source, string $idempotencyKey, string $fingerprint): Appointment
    {
        $appointment = Appointment::create([
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'customer_name' => $booking['customer_name'],
            'customer_email' => $booking['customer_email'],
            'customer_phone' => $booking['customer_phone'],
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes((int) $service->duration_minutes),
            'status' => Appointment::STATUS_CONFIRMED,
            'source' => $source,
            'service_name_snapshot' => $service->name,
            'duration_minutes_snapshot' => (int) $service->duration_minutes,
            'price_snapshot' => (string) $service->price,
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => $fingerprint,
        ]);

        if ($appointment->customer_email !== null && $appointment->starts_at->isFuture()) {
            AppointmentNotification::create([
                'appointment_id' => $appointment->id,
                'kind' => AppointmentNotification::KIND_CONFIRMATION,
                'status' => AppointmentNotification::STATUS_PENDING,
            ]);
        }

        return $appointment;
    }

    /**
     * Same checks and messages as the availability endpoints, re-read
     * inside the locked transaction.
     *
     * @throws ValidationException
     */
    private function assertBookable(?Service $service, ?Professional $professional): void
    {
        if (! $service || ! $service->is_active) {
            throw ValidationException::withMessages([
                'service_id' => [__('errors.availability_service_unavailable')],
            ]);
        }

        if (! $professional || ! $professional->is_active) {
            throw ValidationException::withMessages([
                'professional_id' => [__('errors.availability_professional_unavailable')],
            ]);
        }

        if (! $this->engine->isBookableCombination($service, $professional)) {
            throw ValidationException::withMessages([
                'professional_id' => [__('errors.availability_service_not_offered')],
            ]);
        }
    }

    /**
     * Future confirmed reservations are counted separately for the e-mail
     * and for the phone, including reservations made by the administrator
     * with the same contact. The response never says which contact hit the
     * limit.
     *
     * @throws BusinessConflictException
     */
    private function assertWithinContactLimit(string $email, string $phone, int $limit): void
    {
        $now = CarbonImmutable::now('UTC');

        foreach (['customer_email' => $email, 'customer_phone' => $phone] as $column => $value) {
            $active = Appointment::query()
                ->where($column, $value)
                ->where('status', Appointment::STATUS_CONFIRMED)
                ->where('starts_at', '>', $now)
                ->count();

            if ($active >= $limit) {
                throw BusinessConflictException::contactLimitReached();
            }
        }
    }

    /**
     * Binds the idempotency key to the exact business intent and to the
     * public/admin context, so a key cannot be replayed with another payload
     * or across contexts.
     *
     * @param  array<string, mixed>  $booking
     */
    private function fingerprint(string $source, array $booking): string
    {
        $intent = [
            'source' => $source,
            'service_id' => (int) $booking['service_id'],
            'professional_id' => (int) $booking['professional_id'],
            'starts_at' => $booking['starts_at']?->utc()->toIso8601String(),
            'customer_name' => $booking['customer_name'],
            'customer_email' => $booking['customer_email'],
            'customer_phone' => $booking['customer_phone'],
        ];

        // Admin only, so public fingerprints already stored keep matching.
        // "Atender agora" has no start in the payload: the intent is "now",
        // and a replay returns the reservation created at that moment.
        if ($source === Appointment::SOURCE_ADMIN) {
            $intent['mode'] = $booking['mode'];
        }

        return hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR));
    }
}
