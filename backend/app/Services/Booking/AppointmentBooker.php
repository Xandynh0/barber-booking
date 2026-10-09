<?php

namespace App\Services\Booking;

use App\Exceptions\BusinessConflictException;
use App\Models\Appointment;
use App\Models\BusinessSettings;
use App\Models\Professional;
use App\Models\Service;
use App\Services\Availability\AvailabilityContext;
use App\Services\Availability\AvailabilityEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a public reservation. This is where the guarantee against double
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
 * DB::transaction(). E-mail confirmation is not part of this delivery.
 */
class AppointmentBooker
{
    private const TRANSACTION_ATTEMPTS = 3;

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

            $appointment = Appointment::create([
                'professional_id' => $professional->id,
                'service_id' => $service->id,
                'customer_name' => $booking['customer_name'],
                'customer_email' => $booking['customer_email'],
                'customer_phone' => $booking['customer_phone'],
                'starts_at' => $booking['starts_at'],
                'ends_at' => $booking['starts_at']->addMinutes((int) $service->duration_minutes),
                'status' => Appointment::STATUS_CONFIRMED,
                'source' => Appointment::SOURCE_PUBLIC,
                'service_name_snapshot' => $service->name,
                'duration_minutes_snapshot' => (int) $service->duration_minutes,
                'price_snapshot' => (string) $service->price,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
            ]);

            return ['appointment' => $appointment, 'replayed' => false];
        }, self::TRANSACTION_ATTEMPTS);
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
        return hash('sha256', json_encode([
            'source' => $source,
            'service_id' => (int) $booking['service_id'],
            'professional_id' => (int) $booking['professional_id'],
            'starts_at' => $booking['starts_at']->utc()->toIso8601String(),
            'customer_name' => $booking['customer_name'],
            'customer_email' => $booking['customer_email'],
            'customer_phone' => $booking['customer_phone'],
        ], JSON_THROW_ON_ERROR));
    }
}
