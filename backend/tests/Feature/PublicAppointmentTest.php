<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BusinessSettings;
use App\Models\Professional;
use App\Models\ScheduleBlock;
use App\Models\Service;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * POST /api/v1/public/appointments, one request at a time, against real
 * MySQL with a controlled clock. Concurrent requests are covered by
 * PublicAppointmentConcurrencyTest. Unless a test says otherwise: barbershop
 * in America/Sao_Paulo (UTC-3), "now" is Monday 2026-11-02 08:00 local, the
 * professional works Tuesday (weekday 2) 09:00–12:00 and 13:00–18:00, and
 * the service lasts 45 minutes.
 */
class PublicAppointmentTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Sao_Paulo';

    private const DATE = '2026-11-03';

    private Service $service;

    private Professional $professional;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', self::TZ));

        BusinessSettings::factory()->create(['timezone' => self::TZ]);
        $this->service = Service::factory()->create(['name' => 'Corte degradê', 'duration_minutes' => 45, 'price' => '55.90']);
        $this->professional = Professional::factory()->create(['name' => 'Rafael Almeida']);
        $this->professional->services()->attach($this->service);
        $this->workingHours($this->professional, [[2, '09:00', '12:00'], [2, '13:00', '18:00']]);
    }

    // --- Creation and stored data ---------------------------------------

    public function test_creates_a_confirmed_public_reservation(): void
    {
        $response = $this->book(['starts_at' => self::DATE.'T10:00:00-03:00']);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.starts_at', '2026-11-03T13:00:00+00:00')
            ->assertJsonPath('data.ends_at', '2026-11-03T13:45:00+00:00')
            ->assertJsonPath('data.timezone', self::TZ)
            ->assertJsonPath('data.service', ['id' => $this->service->id, 'name' => 'Corte degradê', 'duration_minutes' => 45, 'price' => '55.90'])
            ->assertJsonPath('data.professional', ['id' => $this->professional->id, 'name' => 'Rafael Almeida'])
            ->assertJsonPath('data.customer_name', 'Cliente Teste')
            ->assertJsonMissingPath('data.customer_email')
            ->assertJsonMissingPath('data.customer_phone');

        $appointment = Appointment::query()->sole();
        $this->assertTrue(Str::isUlid($appointment->public_id));
        $this->assertSame($appointment->public_id, $response->json('data.public_id'));
        $this->assertSame(Appointment::STATUS_CONFIRMED, $appointment->status);
        $this->assertSame(Appointment::SOURCE_PUBLIC, $appointment->source);
        $this->assertSame('2026-11-03 13:45:00', $appointment->ends_at->format('Y-m-d H:i:s'));
        $this->assertNull($appointment->cancelled_at);
        $this->assertNull($appointment->cancelled_by);
    }

    public function test_stores_service_snapshots_that_later_service_edits_do_not_change(): void
    {
        $key = $this->newKey();
        $this->book(['starts_at' => self::DATE.'T10:00:00-03:00'], $key)->assertCreated();

        $this->service->update(['name' => 'Degradê premium', 'price' => '99.00', 'duration_minutes' => 60]);

        $appointment = Appointment::query()->sole();
        $this->assertSame('Corte degradê', $appointment->service_name_snapshot);
        $this->assertSame('55.90', $appointment->price_snapshot);
        $this->assertSame(45, $appointment->duration_minutes_snapshot);
        $this->assertSame('2026-11-03 13:45:00', $appointment->ends_at->format('Y-m-d H:i:s'));

        // A replay reports what was contracted, not the current service.
        $this->book(['starts_at' => self::DATE.'T10:00:00-03:00'], $key)
            ->assertOk()
            ->assertJsonPath('data.service.name', 'Corte degradê')
            ->assertJsonPath('data.service.price', '55.90')
            ->assertJsonPath('data.service.duration_minutes', 45);
    }

    public function test_contacts_are_stored_in_canonical_form(): void
    {
        $this->book([
            'customer_name' => '  Cliente Teste  ',
            'customer_email' => '  Cliente.Teste+Corte@Example.COM ',
            'customer_phone' => '(11) 99999-0000',
        ])->assertCreated();

        $appointment = Appointment::query()->sole();
        $this->assertSame('Cliente Teste', $appointment->customer_name);
        $this->assertSame('cliente.teste+corte@example.com', $appointment->customer_email);
        $this->assertSame('+5511999990000', $appointment->customer_phone);
    }

    public function test_internal_fields_sent_by_the_client_are_ignored(): void
    {
        $this->book([
            'status' => 'cancelled',
            'source' => 'admin',
            'ends_at' => self::DATE.'T18:00:00-03:00',
            'price' => '0.00',
            'price_snapshot' => '0.00',
            'service_name_snapshot' => 'Grátis',
            'duration_minutes_snapshot' => 5,
            'public_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'request_fingerprint' => 'x',
        ])->assertCreated();

        $appointment = Appointment::query()->sole();
        $this->assertSame(Appointment::STATUS_CONFIRMED, $appointment->status);
        $this->assertSame(Appointment::SOURCE_PUBLIC, $appointment->source);
        $this->assertSame('55.90', $appointment->price_snapshot);
        $this->assertSame('Corte degradê', $appointment->service_name_snapshot);
        $this->assertSame(45, $appointment->duration_minutes_snapshot);
        $this->assertNotSame('01ARZ3NDEKTSV4RRFFQ69G5FAV', $appointment->public_id);
        $this->assertSame('2026-11-03 13:45:00', $appointment->ends_at->format('Y-m-d H:i:s'));
    }

    public function test_the_new_reservation_disappears_from_availability(): void
    {
        $this->book(['starts_at' => self::DATE.'T10:00:00-03:00'])->assertCreated();

        $starts = array_column($this->availability()->json('data.slots'), 'starts_at');

        $this->assertNotContains('2026-11-03T13:00:00+00:00', $starts);   // 10:00 itself
        $this->assertNotContains('2026-11-03T12:30:00+00:00', $starts);   // 09:30–10:15 overlaps
        $this->assertContains('2026-11-03T12:15:00+00:00', $starts);      // 09:15–10:00 adjacent
        $this->assertContains('2026-11-03T13:45:00+00:00', $starts);      // 10:45 adjacent
    }

    // --- Idempotency -----------------------------------------------------

    public function test_repeating_the_same_key_and_payload_returns_the_same_reservation(): void
    {
        $key = $this->newKey();

        $first = $this->book([], $key)->assertCreated();
        $replay = $this->book([], $key)->assertOk();

        $this->assertSame($first->json('data'), $replay->json('data'));
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_reusing_a_key_with_another_payload_is_refused(): void
    {
        $key = $this->newKey();
        $this->book(['starts_at' => self::DATE.'T10:00:00-03:00'], $key)->assertCreated();

        $this->book(['starts_at' => self::DATE.'T14:00:00-03:00'], $key)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_the_idempotency_key_header_is_required_and_validated(): void
    {
        $this->postJson('/api/v1/public/appointments', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key'], 'error.fields');

        $this->book([], 'short')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key'], 'error.fields');

        $this->book([], 'has spaces and ç')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key'], 'error.fields');

        $this->assertDatabaseCount('appointments', 0);
    }

    // --- Payload validation ---------------------------------------------

    public function test_required_fields_and_formats_are_validated(): void
    {
        $this->book(['service_id' => null, 'professional_id' => 'x', 'starts_at' => null, 'customer_name' => '', 'customer_email' => null, 'customer_phone' => null])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['service_id', 'professional_id', 'starts_at', 'customer_name', 'customer_email', 'customer_phone'], 'error.fields');

        $this->book(['customer_email' => 'not-an-email'])->assertStatus(422)->assertJsonValidationErrors(['customer_email'], 'error.fields');
        $this->book(['customer_email' => str_repeat('a', 250).'@x.com'])->assertStatus(422)->assertJsonValidationErrors(['customer_email'], 'error.fields');
        $this->book(['customer_name' => str_repeat('a', 121)])->assertStatus(422)->assertJsonValidationErrors(['customer_name'], 'error.fields');

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_phone_must_normalize_to_e164(): void
    {
        foreach (['123', '99999-0000', '+0 11 99999-0000', '+55 11 9999 9999 9999 99', 'abc'] as $phone) {
            $this->book(['customer_phone' => $phone])
                ->assertStatus(422)
                ->assertJsonPath('error.fields.customer_phone.0', 'Informe um telefone válido com DDD (ex.: +55 11 99999-9999).');
        }

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_starts_at_needs_an_explicit_offset(): void
    {
        foreach ([self::DATE.' 10:00', self::DATE.'T10:00:00', '03/11/2026 10:00', 'amanhã'] as $startsAt) {
            $this->book(['starts_at' => $startsAt])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['starts_at'], 'error.fields');
        }
    }

    public function test_the_same_instant_with_another_offset_is_the_same_slot(): void
    {
        // 13:00 at +01:00 is 12:00 UTC, i.e. 09:00 in São Paulo.
        $this->book(['starts_at' => self::DATE.'T13:00:00+01:00'])->assertCreated()
            ->assertJsonPath('data.starts_at', '2026-11-03T12:00:00+00:00');
        $this->book(['starts_at' => self::DATE.'T13:45:00Z'])->assertCreated()
            ->assertJsonPath('data.starts_at', '2026-11-03T13:45:00+00:00');
    }

    // --- Service / professional --------------------------------------------

    public function test_inactive_unknown_or_unlinked_service_and_professional_are_rejected(): void
    {
        $inactiveService = Service::factory()->inactive()->create();
        $this->professional->services()->attach($inactiveService);
        $inactiveProfessional = Professional::factory()->inactive()->create();
        $inactiveProfessional->services()->attach($this->service);
        $unlinked = Service::factory()->create();

        $this->book(['service_id' => $inactiveService->id])->assertStatus(422)->assertJsonValidationErrors(['service_id'], 'error.fields');
        $this->book(['service_id' => 999999])->assertStatus(422)->assertJsonValidationErrors(['service_id'], 'error.fields');
        $this->book(['professional_id' => $inactiveProfessional->id])->assertStatus(422)->assertJsonValidationErrors(['professional_id'], 'error.fields');
        $this->book(['professional_id' => 999999])->assertStatus(422)->assertJsonValidationErrors(['professional_id'], 'error.fields');
        $this->book(['service_id' => $unlinked->id])
            ->assertStatus(422)
            ->assertJsonPath('error.fields.professional_id.0', 'Este profissional não realiza o serviço escolhido.');

        $this->assertDatabaseCount('appointments', 0);
    }

    // --- Working hours, grid, blocks and existing reservations -------------

    public function test_times_the_engine_would_not_offer_are_refused_as_slot_unavailable(): void
    {
        $cases = [
            'before opening' => '08:45',
            'ends after closing' => '17:30',    // 17:30–18:15
            'crosses lunch' => '11:30',         // 11:30–12:15
            'inside lunch' => '12:15',
            'off the 15-minute grid' => '10:05',
        ];

        foreach ($cases as $case => $time) {
            $this->book(['starts_at' => self::DATE."T{$time}:00-03:00"])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'SLOT_UNAVAILABLE', "Case: {$case}");
        }

        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_a_day_without_working_hours_is_refused(): void
    {
        $this->book(['starts_at' => '2026-11-04T10:00:00-03:00'])   // Wednesday
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SLOT_UNAVAILABLE');
    }

    public function test_a_schedule_block_is_refused_and_its_edges_are_allowed(): void
    {
        $this->block('10:00', '11:00');

        $this->book(['starts_at' => self::DATE.'T10:30:00-03:00'])->assertStatus(409)->assertJsonPath('error.code', 'SLOT_UNAVAILABLE');
        $this->book(['starts_at' => self::DATE.'T09:30:00-03:00'])->assertStatus(409);   // 09:30–10:15 overlaps the block
        $this->book(['starts_at' => self::DATE.'T09:15:00-03:00'])->assertCreated();     // ends 10:00, adjacent
        $this->book(['starts_at' => self::DATE.'T11:00:00-03:00'])->assertCreated();     // starts when the block ends
    }

    public function test_existing_reservations_conflict_on_same_start_and_partial_overlap_but_not_when_adjacent(): void
    {
        $this->book(['starts_at' => self::DATE.'T10:00:00-03:00'])->assertCreated();

        $this->book(['starts_at' => self::DATE.'T10:00:00-03:00'])->assertStatus(409)->assertJsonPath('error.code', 'SLOT_UNAVAILABLE');
        $this->book(['starts_at' => self::DATE.'T10:30:00-03:00'])->assertStatus(409);   // 10:30–11:15 vs 10:00–10:45
        $this->book(['starts_at' => self::DATE.'T09:30:00-03:00'])->assertStatus(409);   // 09:30–10:15 vs 10:00–10:45
        $this->book(['starts_at' => self::DATE.'T10:45:00-03:00'])->assertCreated();     // adjacent after
        $this->book(['starts_at' => self::DATE.'T09:15:00-03:00'])->assertCreated();     // adjacent before

        $this->assertDatabaseCount('appointments', 3);
    }

    public function test_a_cancelled_reservation_does_not_block_the_slot(): void
    {
        Appointment::factory()->cancelled()->create([
            'professional_id' => $this->professional->id,
            'service_id' => $this->service->id,
            'starts_at' => CarbonImmutable::parse(self::DATE.' 10:00', self::TZ)->utc(),
            'ends_at' => CarbonImmutable::parse(self::DATE.' 10:45', self::TZ)->utc(),
        ]);

        $this->book(['starts_at' => self::DATE.'T10:00:00-03:00'])->assertCreated();
    }

    public function test_another_professionals_reservation_does_not_block(): void
    {
        $other = Professional::factory()->create();
        Appointment::factory()->create([
            'professional_id' => $other->id,
            'service_id' => $this->service->id,
            'starts_at' => CarbonImmutable::parse(self::DATE.' 10:00', self::TZ)->utc(),
            'ends_at' => CarbonImmutable::parse(self::DATE.' 10:45', self::TZ)->utc(),
        ]);

        $this->book(['starts_at' => self::DATE.'T10:00:00-03:00'])->assertCreated();
    }

    public function test_a_slot_taken_between_the_availability_query_and_the_post_is_refused(): void
    {
        $starts = array_column($this->availability()->json('data.slots'), 'starts_at');
        $this->assertContains('2026-11-03T13:00:00+00:00', $starts);

        // Someone else books 10:00 after this client saw it as free.
        $this->book(['starts_at' => self::DATE.'T10:00:00-03:00', 'customer_email' => 'outra@example.com', 'customer_phone' => '+5511988887777'])->assertCreated();

        $this->book(['starts_at' => '2026-11-03T13:00:00+00:00'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SLOT_UNAVAILABLE');
        $this->assertDatabaseCount('appointments', 1);
    }

    // --- Notice, past and horizon ---------------------------------------------

    public function test_minimum_notice_is_inclusive_at_the_exact_boundary(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::DATE.' 08:00', self::TZ));

        $this->book(['starts_at' => self::DATE.'T09:00:00-03:00'])->assertCreated();   // exactly 60 min

        // One second past the boundary: 10:00 does not touch the 09:00–09:45
        // reservation, so only the notice can refuse it.
        $this->travelTo(CarbonImmutable::parse(self::DATE.' 09:00:01', self::TZ));
        $this->book(['starts_at' => self::DATE.'T10:00:00-03:00'])->assertStatus(409)->assertJsonPath('error.code', 'SLOT_UNAVAILABLE');
    }

    public function test_a_time_in_the_past_is_refused(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::DATE.' 11:00', self::TZ));

        $this->book(['starts_at' => self::DATE.'T09:00:00-03:00'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SLOT_UNAVAILABLE');
    }

    public function test_the_last_date_of_the_horizon_is_accepted_and_the_next_is_refused(): void
    {
        // Today 2026-11-02, 30 days → last date 2026-12-01 (a Tuesday).
        $this->workingHours($this->professional, [[3, '09:00', '12:00']]);   // Wednesday too

        $this->book(['starts_at' => '2026-12-01T10:00:00-03:00'])->assertCreated();
        $this->book(['starts_at' => '2026-12-02T10:00:00-03:00'])->assertStatus(409)->assertJsonPath('error.code', 'SLOT_UNAVAILABLE');
    }

    public function test_the_horizon_uses_the_barbershop_local_date(): void
    {
        // 22:30 Monday in São Paulo is 01:30 Tuesday in UTC; with a 1-day
        // horizon only local Monday is open, so a Tuesday booking is refused.
        BusinessSettings::query()->update(['booking_horizon_days' => 1, 'min_notice_minutes' => 0]);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 22:30', self::TZ));

        $this->book(['starts_at' => self::DATE.'T10:00:00-03:00'])->assertStatus(409)->assertJsonPath('error.code', 'SLOT_UNAVAILABLE');
    }

    // --- Contact limit ------------------------------------------------------

    public function test_the_contact_limit_counts_email_and_phone_separately(): void
    {
        // Three future confirmed reservations with the same e-mail, each with
        // a different phone, one of them made by the administrator.
        foreach (['09:00', '13:00', '15:00'] as $i => $time) {
            Appointment::factory()->create([
                'professional_id' => $this->professional->id,
                'service_id' => $this->service->id,
                'customer_email' => 'cliente@example.com',
                'customer_phone' => '+551190000000'.$i,
                'source' => $i === 0 ? Appointment::SOURCE_ADMIN : Appointment::SOURCE_PUBLIC,
                'starts_at' => CarbonImmutable::parse(self::DATE.' '.$time, self::TZ)->utc(),
                'ends_at' => CarbonImmutable::parse(self::DATE.' '.$time, self::TZ)->utc()->addMinutes(45),
            ]);
        }

        $this->book(['starts_at' => self::DATE.'T16:00:00-03:00', 'customer_email' => 'Cliente@Example.com'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONTACT_LIMIT_REACHED');

        // Same phone as an existing reservation, but a new e-mail: the phone
        // has only one active reservation, so it is accepted.
        $this->book(['starts_at' => self::DATE.'T16:00:00-03:00', 'customer_email' => 'novo@example.com', 'customer_phone' => '+5511900000000'])
            ->assertCreated();
    }

    public function test_cancelled_and_past_reservations_do_not_count_towards_the_contact_limit(): void
    {
        $factory = fn (array $attributes) => Appointment::factory()->create(array_merge([
            'professional_id' => $this->professional->id,
            'service_id' => $this->service->id,
            'customer_email' => 'cliente@example.com',
        ], $attributes));

        $factory(['starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addMinutes(45)]);
        $factory(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDays(2)->addMinutes(45)]);
        $factory(['status' => Appointment::STATUS_CANCELLED, 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addMinutes(45)]);

        $this->book(['customer_email' => 'cliente@example.com'])->assertCreated();
    }

    // --- Messages and rate limit ---------------------------------------------

    public function test_conflict_messages_are_translated(): void
    {
        $this->book(['starts_at' => self::DATE.'T10:00:00-03:00'])->assertCreated();

        $this->book(['starts_at' => self::DATE.'T10:00:00-03:00'])
            ->assertJsonPath('error.message', 'Esse horário não está mais disponível. Escolha outro.');

        $this->withHeaders(['Accept-Language' => 'en'])
            ->book(['starts_at' => self::DATE.'T10:00:00-03:00'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SLOT_UNAVAILABLE')
            ->assertJsonPath('error.message', 'This time is no longer available. Please choose another one.');
    }

    public function test_errors_do_not_leak_internals(): void
    {
        $this->book(['starts_at' => self::DATE.'T10:00:00-03:00'])->assertCreated();

        $response = $this->book(['starts_at' => self::DATE.'T10:00:00-03:00', 'customer_email' => 'outra@example.com']);

        $response->assertStatus(409);
        $this->assertSame(['code', 'message'], array_keys($response->json('error')));
        $this->assertStringNotContainsString('cliente@example.com', $response->getContent());
        $this->assertStringNotContainsString('Cliente Teste', $response->getContent());
    }

    public function test_public_booking_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->book(['service_id' => 999999])->assertStatus(422);
        }

        $this->book()
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    // --- Helpers ---------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function book(array $overrides = [], ?string $idempotencyKey = null): TestResponse
    {
        return $this->withHeaders(['Idempotency-Key' => $idempotencyKey ?? $this->newKey()])
            ->postJson('/api/v1/public/appointments', $this->payload($overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'starts_at' => self::DATE.'T10:00:00-03:00',
            'customer_name' => 'Cliente Teste',
            'customer_email' => 'cliente@example.com',
            'customer_phone' => '+5511999999999',
        ], $overrides);
    }

    private function newKey(): string
    {
        return (string) Str::uuid();
    }

    private function availability(): TestResponse
    {
        return $this->getJson('/api/v1/public/availability?'.http_build_query([
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'date' => self::DATE,
        ]))->assertOk();
    }

    private function block(string $from, string $until): void
    {
        ScheduleBlock::factory()->create([
            'professional_id' => $this->professional->id,
            'starts_at' => CarbonImmutable::parse(self::DATE.' '.$from, self::TZ)->utc(),
            'ends_at' => CarbonImmutable::parse(self::DATE.' '.$until, self::TZ)->utc(),
        ]);
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: string}>  $periods
     */
    private function workingHours(Professional $professional, array $periods): void
    {
        foreach ($periods as [$weekday, $start, $end]) {
            WorkingHour::factory()->create([
                'professional_id' => $professional->id,
                'weekday' => $weekday,
                'start_time' => $start,
                'end_time' => $end,
            ]);
        }
    }
}
