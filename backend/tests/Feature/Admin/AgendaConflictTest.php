<?php

namespace Tests\Feature\Admin;

use App\Models\Appointment;
use App\Models\BusinessSettings;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * docs/planejamento-barbearia-mvp.md, seção 7: agenda changes that would
 * break a reservation are refused with the conflicts listed — nothing is
 * cancelled silently. "Now" is Monday 2026-11-02 08:00 in São Paulo; the
 * reservation is on Tuesday 2026-11-03, 10:00–10:45 local.
 */
class AgendaConflictTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Sao_Paulo';

    private Professional $professional;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', self::TZ));
        BusinessSettings::factory()->create(['timezone' => self::TZ]);
        $this->actingAs(User::factory()->create());

        $this->service = Service::factory()->create(['duration_minutes' => 45]);
        $this->professional = Professional::factory()->create(['name' => 'Rafael Almeida']);
        $this->professional->services()->attach($this->service);
        WorkingHour::factory()->create(['professional_id' => $this->professional->id, 'weekday' => 2, 'start_time' => '09:00', 'end_time' => '12:00']);
    }

    // --- Schedule blocks ------------------------------------------------

    public function test_an_individual_block_over_a_confirmed_reservation_is_refused_with_the_conflicts(): void
    {
        $appointment = $this->appointment('2026-11-03 10:00', '2026-11-03 10:45', ['customer_name' => 'Cliente Teste']);

        $response = $this->postJson('/api/v1/admin/schedule-blocks', [
            'scope' => 'professional',
            'professional_id' => $this->professional->id,
            'starts_at' => '2026-11-03T10:30:00-03:00',
            'ends_at' => '2026-11-03T11:30:00-03:00',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'APPOINTMENT_CONFLICT')
            ->assertJsonPath('error.message', 'Já existem reservas confirmadas neste intervalo: 03/11/2026 10:00–10:45 (Rafael Almeida). Cancele-as antes de bloquear o horário.')
            ->assertJsonPath('error.conflicts.0.public_id', $appointment->public_id)
            ->assertJsonPath('error.conflicts.0.customer_name', 'Cliente Teste')
            ->assertJsonPath('error.conflicts.0.starts_at', '2026-11-03T13:00:00+00:00');
        $this->assertDatabaseCount('schedule_blocks', 0);
        $this->assertSame(Appointment::STATUS_CONFIRMED, $appointment->fresh()->status);
    }

    public function test_the_conflict_message_follows_the_request_language(): void
    {
        $this->appointment('2026-11-03 10:00', '2026-11-03 10:45');

        $this->withHeaders(['Accept-Language' => 'en'])
            ->postJson('/api/v1/admin/schedule-blocks', [
                'scope' => 'professional',
                'professional_id' => $this->professional->id,
                'starts_at' => '2026-11-03T10:00:00-03:00',
                'ends_at' => '2026-11-03T11:00:00-03:00',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.message', 'There are confirmed bookings in this interval: 11/03/2026 10:00–10:45 (Rafael Almeida). Cancel them before blocking this time.');
    }

    public function test_a_block_adjacent_to_a_reservation_or_over_a_cancelled_one_is_allowed(): void
    {
        $this->appointment('2026-11-03 10:00', '2026-11-03 10:45');
        $this->appointment('2026-11-03 11:00', '2026-11-03 11:45', ['status' => Appointment::STATUS_CANCELLED]);

        $this->postJson('/api/v1/admin/schedule-blocks', [
            'scope' => 'professional',
            'professional_id' => $this->professional->id,
            'starts_at' => '2026-11-03T10:45:00-03:00',   // starts exactly when the reservation ends
            'ends_at' => '2026-11-03T11:45:00-03:00',     // covers only the cancelled one
        ])->assertCreated();
    }

    public function test_a_shop_closure_over_any_professionals_reservation_is_refused_and_creates_nothing(): void
    {
        $other = Professional::factory()->create(['name' => 'Bruno Costa']);
        $this->appointment('2026-11-03 10:00', '2026-11-03 10:45', ['professional_id' => $other->id]);

        $this->postJson('/api/v1/admin/schedule-blocks', [
            'scope' => 'shop',
            'starts_at' => '2026-11-03T00:00:00-03:00',
            'ends_at' => '2026-11-04T00:00:00-03:00',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'APPOINTMENT_CONFLICT')
            ->assertJsonPath('error.conflicts.0.professional.name', 'Bruno Costa');

        $this->assertDatabaseCount('schedule_blocks', 0);
    }

    public function test_a_block_on_another_professional_is_not_affected(): void
    {
        $other = Professional::factory()->create();
        $this->appointment('2026-11-03 10:00', '2026-11-03 10:45');

        $this->postJson('/api/v1/admin/schedule-blocks', [
            'scope' => 'professional',
            'professional_id' => $other->id,
            'starts_at' => '2026-11-03T10:00:00-03:00',
            'ends_at' => '2026-11-03T11:00:00-03:00',
        ])->assertCreated();
    }

    // --- Working hours --------------------------------------------------

    public function test_reducing_working_hours_so_a_future_reservation_no_longer_fits_is_refused_and_rolled_back(): void
    {
        $this->appointment('2026-11-03 10:00', '2026-11-03 10:45');

        // 09:00–10:30 would cut the 10:00–10:45 reservation.
        $this->putWeek([2 => [['09:00', '10:30']]])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'APPOINTMENT_CONFLICT')
            ->assertJsonPath('error.message', 'O novo expediente deixaria reservas futuras fora do horário: 03/11/2026 10:00–10:45 (Rafael Almeida). Cancele-as antes de reduzir o expediente.');

        $this->assertSame(
            [['09:00:00', '12:00:00']],
            WorkingHour::query()->where('professional_id', $this->professional->id)->get(['start_time', 'end_time'])->map(fn ($p) => [$p->start_time, $p->end_time])->all(),
            'The previous working hours must be kept when the change is refused.',
        );
    }

    public function test_removing_the_whole_day_of_a_future_reservation_is_refused(): void
    {
        $this->appointment('2026-11-03 10:00', '2026-11-03 10:45');

        $this->putWeek([])->assertStatus(409)->assertJsonPath('error.code', 'APPOINTMENT_CONFLICT');
    }

    public function test_a_change_that_still_contains_the_reservation_is_allowed(): void
    {
        $this->appointment('2026-11-03 10:00', '2026-11-03 10:45');

        // Exactly the reservation's interval, plus an afternoon period.
        $this->putWeek([2 => [['10:00', '10:45'], ['14:00', '18:00']]])->assertOk();
    }

    public function test_past_and_cancelled_reservations_do_not_prevent_reducing_working_hours(): void
    {
        $this->appointment('2026-10-27 10:00', '2026-10-27 10:45');   // last Tuesday
        $this->appointment('2026-11-03 10:00', '2026-11-03 10:45', ['status' => Appointment::STATUS_CANCELLED]);

        $this->putWeek([])->assertOk();
        $this->assertDatabaseCount('working_hours', 0);
    }

    // --- Helpers --------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function appointment(string $localStart, string $localEnd, array $attributes = []): Appointment
    {
        return Appointment::factory()->create(array_merge([
            'professional_id' => $this->professional->id,
            'service_id' => $this->service->id,
            'starts_at' => CarbonImmutable::parse($localStart, self::TZ)->utc(),
            'ends_at' => CarbonImmutable::parse($localEnd, self::TZ)->utc(),
        ], $attributes));
    }

    /**
     * @param  array<int, array<int, array{0: string, 1: string}>>  $periodsByWeekday
     */
    private function putWeek(array $periodsByWeekday): TestResponse
    {
        return $this->putJson("/api/v1/admin/professionals/{$this->professional->id}/working-hours", [
            'days' => collect(range(0, 6))->map(fn (int $weekday) => [
                'weekday' => $weekday,
                'periods' => array_map(
                    fn (array $period) => ['start_time' => $period[0], 'end_time' => $period[1]],
                    $periodsByWeekday[$weekday] ?? [],
                ),
            ])->all(),
        ]);
    }
}
