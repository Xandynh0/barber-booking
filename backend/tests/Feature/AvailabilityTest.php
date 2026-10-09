<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BusinessSettings;
use App\Models\Professional;
use App\Models\ScheduleBlock;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GET /api/v1/public/availability and GET /api/v1/admin/availability,
 * against real MySQL with a controlled clock. Unless a test says
 * otherwise: barbershop in America/Sao_Paulo (UTC-3, no DST), "now" is
 * Monday 2026-11-02 08:00 local, and the queried date is Tuesday
 * 2026-11-03 (weekday 2).
 *
 * These tests prove the availability *suggestion* only. They do not — and
 * cannot — prove that two simultaneous reservations are prevented: that
 * guarantee belongs to the reservation-creation transaction, which does
 * not exist yet.
 */
class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Sao_Paulo';

    private const DATE = '2026-11-03';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', self::TZ));
    }

    // --- Contract -------------------------------------------------------

    public function test_public_route_needs_no_session_and_returns_the_contract_shape(): void
    {
        [$service, $professional] = $this->bookable(duration: 30, periods: [[2, '09:00', '10:00']]);

        $response = $this->publicAvailability($service, $professional, self::DATE);

        $response->assertOk()
            ->assertExactJson([
                'data' => [
                    'timezone' => self::TZ,
                    'date' => self::DATE,
                    'slots' => [
                        ['starts_at' => '2026-11-03T12:00:00+00:00', 'ends_at' => '2026-11-03T12:30:00+00:00'],
                        ['starts_at' => '2026-11-03T12:15:00+00:00', 'ends_at' => '2026-11-03T12:45:00+00:00'],
                        ['starts_at' => '2026-11-03T12:30:00+00:00', 'ends_at' => '2026-11-03T13:00:00+00:00'],
                    ],
                ],
            ]);
    }

    public function test_admin_route_requires_an_authenticated_session(): void
    {
        [$service, $professional] = $this->bookable();

        $this->getJson('/api/v1/admin/availability?'.http_build_query([
            'service_id' => $service->id, 'professional_id' => $professional->id, 'date' => self::DATE,
        ]))
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_slots_never_expose_block_reasons_or_appointment_data(): void
    {
        [$service, $professional] = $this->bookable(duration: 30, periods: [[2, '09:00', '11:00']]);
        $this->block($professional, '09:00', '09:30', reason: 'Consulta médica');
        $this->appointment($professional, $service, '10:00', '10:30', customer: 'Cliente Sigiloso');

        $response = $this->publicAvailability($service, $professional, self::DATE)->assertOk();

        $this->assertStringNotContainsString('Consulta médica', $response->getContent());
        $this->assertStringNotContainsString('Cliente Sigiloso', $response->getContent());
        foreach ($response->json('data.slots') as $slot) {
            $this->assertSame(['starts_at', 'ends_at'], array_keys($slot));
        }
    }

    public function test_a_context_query_parameter_cannot_switch_the_public_route_to_admin_rules(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::DATE.' 09:20', self::TZ));
        [$service, $professional] = $this->bookable(duration: 15, periods: [[2, '09:00', '12:00']]);

        $response = $this->getJson('/api/v1/public/availability?'.http_build_query([
            'service_id' => $service->id, 'professional_id' => $professional->id, 'date' => self::DATE,
            'context' => 'admin',
        ]));

        // 60 minutes of notice still applies: nothing before 10:20.
        $this->assertSame('10:30', $this->localStarts($response)[0]);
    }

    // --- Validation and active/link filters ----------------------------

    public function test_date_must_be_a_real_calendar_date_in_yyyy_mm_dd(): void
    {
        [$service, $professional] = $this->bookable();

        foreach (['03/11/2026', '2026-11-3', '2026-02-30', ''] as $date) {
            $this->publicAvailability($service, $professional, $date)
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_ERROR')
                ->assertJsonValidationErrors(['date'], 'error.fields');
        }
    }

    public function test_an_inactive_or_unknown_service_is_rejected_in_both_contexts(): void
    {
        [$service, $professional] = $this->bookable();
        $service->update(['is_active' => false]);

        foreach (['public', 'admin'] as $context) {
            $this->availability($context, $service->id, $professional->id, self::DATE)
                ->assertStatus(422)
                ->assertJsonValidationErrors(['service_id'], 'error.fields');
            $this->availability($context, 999999, $professional->id, self::DATE)
                ->assertStatus(422)
                ->assertJsonValidationErrors(['service_id'], 'error.fields');
        }
    }

    public function test_an_inactive_or_unknown_professional_is_rejected_in_both_contexts(): void
    {
        [$service, $professional] = $this->bookable();
        $professional->update(['is_active' => false]);

        foreach (['public', 'admin'] as $context) {
            $this->availability($context, $service->id, $professional->id, self::DATE)
                ->assertStatus(422)
                ->assertJsonValidationErrors(['professional_id'], 'error.fields');
            $this->availability($context, $service->id, 999999, self::DATE)
                ->assertStatus(422)
                ->assertJsonValidationErrors(['professional_id'], 'error.fields');
        }
    }

    public function test_a_professional_not_linked_to_the_service_is_rejected_in_both_contexts(): void
    {
        [$service, $professional] = $this->bookable();
        $otherService = Service::factory()->create(['duration_minutes' => 30]);

        foreach (['public', 'admin'] as $context) {
            $this->availability($context, $otherService->id, $professional->id, self::DATE)
                ->assertStatus(422)
                ->assertJsonPath('error.fields.professional_id.0', 'Este profissional não realiza o serviço escolhido.');
        }
    }

    public function test_the_not_offered_message_is_translated_to_english(): void
    {
        [, $professional] = $this->bookable();
        $otherService = Service::factory()->create();

        $this->withHeaders(['Accept-Language' => 'en'])
            ->getJson('/api/v1/public/availability?'.http_build_query([
                'service_id' => $otherService->id, 'professional_id' => $professional->id, 'date' => self::DATE,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.fields.professional_id.0', 'This professional does not offer the selected service.');
    }

    // --- Working hours, duration and the 15-minute grid ----------------

    public function test_no_working_hours_on_that_weekday_means_no_slots(): void
    {
        // Working hours only on Wednesday (3); the queried date is a Tuesday.
        [$service, $professional] = $this->bookable(periods: [[3, '09:00', '18:00']]);

        $this->publicAvailability($service, $professional, self::DATE)
            ->assertOk()
            ->assertJsonPath('data.slots', []);
    }

    public function test_the_whole_duration_must_fit_inside_the_period(): void
    {
        [$service, $professional] = $this->bookable(duration: 45, periods: [[2, '09:00', '12:00']]);

        $starts = $this->localStarts($this->publicAvailability($service, $professional, self::DATE));

        $this->assertSame('09:00', $starts[0]);
        $this->assertSame('11:15', end($starts)); // 11:15 + 45 min = 12:00, exactly the end
        $this->assertCount(10, $starts);
    }

    public function test_a_service_longer_than_the_period_has_no_slots(): void
    {
        [$service, $professional] = $this->bookable(duration: 120, periods: [[2, '09:00', '10:30']]);

        $this->publicAvailability($service, $professional, self::DATE)
            ->assertOk()
            ->assertJsonPath('data.slots', []);
    }

    public function test_slots_never_cross_the_lunch_break(): void
    {
        // Example from docs/planejamento-barbearia-mvp.md, seção 5: 45-minute
        // service, 09h–12h and 13h–18h, existing reservation 10h–10h45.
        [$service, $professional] = $this->bookable(duration: 45, periods: [[2, '09:00', '12:00'], [2, '13:00', '18:00']]);
        $this->appointment($professional, $service, '10:00', '10:45');

        $starts = $this->localStarts($this->publicAvailability($service, $professional, self::DATE));

        $this->assertContains('09:15', $starts);      // 09:15–10:00 touches the reservation, allowed
        $this->assertNotContains('09:30', $starts);   // 09:30–10:15 conflicts
        $this->assertContains('10:45', $starts);      // starts right when the reservation ends
        $this->assertContains('11:15', $starts);      // 11:15–12:00 fits before lunch
        $this->assertNotContains('11:30', $starts);   // 11:30–12:15 crosses lunch
        $this->assertNotContains('12:30', $starts);   // inside lunch
        $this->assertSame('13:00', $starts[array_search('11:15', $starts, true) + 1]);
        $this->assertSame('17:15', end($starts));
    }

    public function test_a_period_start_is_rounded_up_to_the_next_local_quarter_hour(): void
    {
        [$service, $professional] = $this->bookable(duration: 15, periods: [[2, '09:10', '10:00']]);

        $this->assertSame(
            ['09:15', '09:30', '09:45'],
            $this->localStarts($this->publicAvailability($service, $professional, self::DATE)),
        );
    }

    // --- Blocks and appointments ---------------------------------------

    public function test_an_individual_block_removes_overlapping_slots_and_keeps_adjacent_ones(): void
    {
        [$service, $professional] = $this->bookable(duration: 30, periods: [[2, '09:00', '12:00']]);
        $this->block($professional, '10:00', '11:00');

        $starts = $this->localStarts($this->publicAvailability($service, $professional, self::DATE));

        $this->assertContains('09:30', $starts);      // 09:30–10:00 ends exactly when the block starts
        $this->assertNotContains('09:45', $starts);
        $this->assertNotContains('10:30', $starts);
        $this->assertContains('11:00', $starts);      // starts exactly when the block ends
    }

    public function test_a_shop_closure_blocks_the_whole_day(): void
    {
        [$service, $professional] = $this->bookable(duration: 30, periods: [[2, '09:00', '12:00']]);
        // A shop closure is one row per professional sharing a group_id.
        ScheduleBlock::factory()->create([
            'professional_id' => $professional->id,
            'group_id' => '01JBARBERCLOSURE0000000000',
            'starts_at' => CarbonImmutable::parse(self::DATE.' 00:00', self::TZ)->utc(),
            'ends_at' => CarbonImmutable::parse('2026-11-04 00:00', self::TZ)->utc(),
        ]);

        foreach (['public', 'admin'] as $context) {
            $this->availability($context, $service->id, $professional->id, self::DATE)
                ->assertOk()
                ->assertJsonPath('data.slots', []);
        }
    }

    public function test_blocks_and_appointments_of_another_professional_do_not_interfere(): void
    {
        [$service, $professional] = $this->bookable(duration: 30, periods: [[2, '09:00', '10:00']]);
        $other = Professional::factory()->create();
        $this->block($other, '09:00', '10:00');
        $this->appointment($other, $service, '09:00', '09:30');

        $this->assertSame(
            ['09:00', '09:15', '09:30'],
            $this->localStarts($this->publicAvailability($service, $professional, self::DATE)),
        );
    }

    public function test_a_confirmed_appointment_blocks_and_a_cancelled_one_does_not(): void
    {
        [$service, $professional] = $this->bookable(duration: 30, periods: [[2, '09:00', '10:30']]);
        $this->appointment($professional, $service, '09:00', '09:30');
        $this->appointment($professional, $service, '10:00', '10:30', cancelled: true);

        $this->assertSame(
            ['09:30', '09:45', '10:00'],
            $this->localStarts($this->publicAvailability($service, $professional, self::DATE)),
        );
    }

    public function test_an_appointment_with_a_different_duration_still_conflicts_partially(): void
    {
        // A 20-minute reservation 09:40–10:00 against a 30-minute service:
        // 09:15–09:45 and 09:30–10:00 overlap it, 09:00–09:30 and 10:00 do not.
        [$service, $professional] = $this->bookable(duration: 30, periods: [[2, '09:00', '10:30']]);
        $this->appointment($professional, $service, '09:40', '10:00');

        $this->assertSame(
            ['09:00', '10:00'],
            $this->localStarts($this->publicAvailability($service, $professional, self::DATE)),
        );
    }

    // --- Minimum notice: public vs. admin ------------------------------

    public function test_public_minimum_notice_is_inclusive_at_the_exact_boundary(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::DATE.' 08:00', self::TZ));
        [$service, $professional] = $this->bookable(duration: 15, periods: [[2, '09:00', '10:00']], minNotice: 60);

        $this->assertSame('09:00', $this->localStarts($this->publicAvailability($service, $professional, self::DATE))[0]);

        $this->travelTo(CarbonImmutable::parse(self::DATE.' 08:00:01', self::TZ));

        $this->assertSame('09:15', $this->localStarts($this->publicAvailability($service, $professional, self::DATE))[0]);
    }

    public function test_admin_ignores_minimum_notice_but_never_gets_past_slots(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::DATE.' 09:20', self::TZ));
        [$service, $professional] = $this->bookable(duration: 15, periods: [[2, '09:00', '12:00']], minNotice: 60);

        $this->assertSame('10:30', $this->localStarts($this->publicAvailability($service, $professional, self::DATE))[0]);
        $this->assertSame('09:30', $this->localStarts($this->adminAvailability($service, $professional, self::DATE))[0]);

        // A slot starting exactly now is not in the past.
        $this->travelTo(CarbonImmutable::parse(self::DATE.' 09:30', self::TZ));
        $this->assertSame('09:30', $this->localStarts($this->adminAvailability($service, $professional, self::DATE))[0]);
    }

    public function test_past_dates_have_no_slots_in_either_context(): void
    {
        [$service, $professional] = $this->bookable(periods: [[0, '09:00', '18:00']]);

        foreach (['public', 'admin'] as $context) {
            $this->availability($context, $service->id, $professional->id, '2026-11-01')
                ->assertOk()
                ->assertJsonPath('data.slots', []);
        }
    }

    // --- Booking horizon (both contexts) --------------------------------

    public function test_horizon_counts_today_as_the_first_available_date_in_both_contexts(): void
    {
        // Today is 2026-11-02; with 30 days the last date is 2026-12-01.
        [$service, $professional] = $this->bookable(duration: 30, periods: $this->everyDay('09:00', '10:00'), horizon: 30);

        foreach (['public', 'admin'] as $context) {
            $this->assertNotEmpty($this->availability($context, $service->id, $professional->id, '2026-12-01')->json('data.slots'));
            $this->availability($context, $service->id, $professional->id, '2026-12-02')
                ->assertOk()
                ->assertJsonPath('data.slots', []);
        }
    }

    public function test_horizon_uses_the_barbershop_local_date_not_utc(): void
    {
        // 22:30 on Monday in São Paulo is already 01:30 on Tuesday in UTC.
        // With a 1-day horizon only the local "today" (Monday) is open.
        $this->travelTo(CarbonImmutable::parse('2026-11-02 22:30', self::TZ));
        [$service, $professional] = $this->bookable(duration: 30, periods: $this->everyDay('23:00', '23:59'), horizon: 1);

        $this->assertSame(['23:00', '23:15'], $this->localStarts($this->adminAvailability($service, $professional, '2026-11-02')));
        $this->adminAvailability($service, $professional, '2026-11-03')
            ->assertOk()
            ->assertJsonPath('data.slots', []);
    }

    // --- Timezone conversion -------------------------------------------

    public function test_the_date_and_weekday_are_interpreted_in_the_barbershop_timezone(): void
    {
        // Tokyo is UTC+9: Tuesday 08:00 local is still Monday 23:00 in UTC,
        // and it's Tuesday's working hours (weekday 2) that must apply.
        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00', 'UTC'));
        [$service, $professional] = $this->bookable(duration: 30, periods: [[2, '08:00', '09:00']], timezone: 'Asia/Tokyo');

        $this->publicAvailability($service, $professional, self::DATE)
            ->assertOk()
            ->assertJsonPath('data.timezone', 'Asia/Tokyo')
            ->assertJsonPath('data.slots.0.starts_at', '2026-11-02T23:00:00+00:00')
            ->assertJsonPath('data.slots.0.ends_at', '2026-11-02T23:30:00+00:00');
    }

    public function test_daylight_saving_changes_the_utc_instant_of_the_same_local_hours(): void
    {
        // America/New_York leaves DST on Sunday 2026-11-01: 09:00 local is
        // 13:00 UTC on Saturday (EDT, UTC-4) and 14:00 UTC on Sunday (EST, UTC-5).
        $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00', 'UTC'));
        [$service, $professional] = $this->bookable(duration: 60, periods: [[6, '09:00', '10:00'], [0, '09:00', '10:00']], timezone: 'America/New_York');

        $this->publicAvailability($service, $professional, '2026-10-31')
            ->assertJsonPath('data.slots', [['starts_at' => '2026-10-31T13:00:00+00:00', 'ends_at' => '2026-10-31T14:00:00+00:00']]);
        $this->publicAvailability($service, $professional, '2026-11-01')
            ->assertJsonPath('data.slots', [['starts_at' => '2026-11-01T14:00:00+00:00', 'ends_at' => '2026-11-01T15:00:00+00:00']]);
    }

    public function test_a_working_period_spanning_the_daylight_saving_change_uses_real_elapsed_time(): void
    {
        // 00:00–03:00 local on the fall-back day lasts four real hours
        // (01:00–02:00 happens twice). Every candidate is a quarter of the
        // local clock and must end by 03:00 local (08:00 UTC).
        $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00', 'UTC'));
        [$service, $professional] = $this->bookable(duration: 60, periods: [[0, '00:00', '03:00']], timezone: 'America/New_York');

        $slots = $this->publicAvailability($service, $professional, '2026-11-01')->json('data.slots');

        $this->assertSame('2026-11-01T04:00:00+00:00', $slots[0]['starts_at']);   // 00:00 EDT
        $this->assertSame('2026-11-01T08:00:00+00:00', end($slots)['ends_at']);   // 03:00 EST
        $this->assertCount(13, $slots);                                           // 04:00Z … 07:00Z, every 15 min
    }

    // --- Rate limiting ---------------------------------------------------

    public function test_public_availability_is_rate_limited_per_ip(): void
    {
        [$service, $professional] = $this->bookable();

        for ($i = 0; $i < 60; $i++) {
            $this->publicAvailability($service, $professional, self::DATE)->assertOk();
        }

        $this->publicAvailability($service, $professional, self::DATE)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    // --- Helpers ---------------------------------------------------------

    /**
     * @param  array<int, array{0: int, 1: string, 2: string}>  $periods  [weekday, start, end] in local time
     * @return array{0: Service, 1: Professional}
     */
    private function bookable(
        int $duration = 30,
        array $periods = [[2, '09:00', '12:00']],
        string $timezone = self::TZ,
        int $minNotice = 60,
        int $horizon = 30,
    ): array {
        BusinessSettings::factory()->create([
            'timezone' => $timezone,
            'min_notice_minutes' => $minNotice,
            'booking_horizon_days' => $horizon,
        ]);
        $service = Service::factory()->create(['duration_minutes' => $duration]);
        $professional = Professional::factory()->create();
        $professional->services()->attach($service);

        foreach ($periods as [$weekday, $start, $end]) {
            WorkingHour::factory()->create([
                'professional_id' => $professional->id,
                'weekday' => $weekday,
                'start_time' => $start,
                'end_time' => $end,
            ]);
        }

        return [$service, $professional];
    }

    /**
     * @return array<int, array{0: int, 1: string, 2: string}>
     */
    private function everyDay(string $start, string $end): array
    {
        return array_map(fn (int $weekday) => [$weekday, $start, $end], range(0, 6));
    }

    private function block(Professional $professional, string $from, string $until, ?string $reason = null): void
    {
        ScheduleBlock::factory()->create([
            'professional_id' => $professional->id,
            'starts_at' => CarbonImmutable::parse(self::DATE.' '.$from, self::TZ)->utc(),
            'ends_at' => CarbonImmutable::parse(self::DATE.' '.$until, self::TZ)->utc(),
            'reason' => $reason,
        ]);
    }

    private function appointment(
        Professional $professional,
        Service $service,
        string $from,
        string $until,
        bool $cancelled = false,
        string $customer = 'Cliente Teste',
    ): void {
        $factory = Appointment::factory();
        if ($cancelled) {
            $factory = $factory->cancelled();
        }

        $factory->create([
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'customer_name' => $customer,
            'starts_at' => CarbonImmutable::parse(self::DATE.' '.$from, self::TZ)->utc(),
            'ends_at' => CarbonImmutable::parse(self::DATE.' '.$until, self::TZ)->utc(),
        ]);
    }

    private function publicAvailability(Service $service, Professional $professional, string $date): TestResponse
    {
        return $this->availability('public', $service->id, $professional->id, $date);
    }

    private function adminAvailability(Service $service, Professional $professional, string $date): TestResponse
    {
        return $this->availability('admin', $service->id, $professional->id, $date);
    }

    private function availability(string $context, int $serviceId, int $professionalId, string $date): TestResponse
    {
        if ($context === 'admin') {
            $this->actingAs(User::factory()->create());
        }

        return $this->getJson("/api/v1/{$context}/availability?".http_build_query([
            'service_id' => $serviceId,
            'professional_id' => $professionalId,
            'date' => $date,
        ]));
    }

    /**
     * Slot start times as "HH:MM" on the barbershop's local clock.
     *
     * @return array<int, string>
     */
    private function localStarts(TestResponse $response, string $timezone = self::TZ): array
    {
        $response->assertOk();

        return array_map(
            fn (array $slot) => CarbonImmutable::parse($slot['starts_at'])->setTimezone($timezone)->format('H:i'),
            $response->json('data.slots'),
        );
    }
}
