<?php

namespace Tests\Feature\Admin;

use App\Models\AppointmentNotification;
use App\Models\Professional;
use App\Models\ScheduleBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/v1/admin/agenda — one local day for the grid and the list.
 */
class AdminAgendaTest extends TestCase
{
    use AgendaFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgenda();
        $this->signIn();
    }

    public function test_the_day_follows_the_barbershop_timezone(): void
    {
        // 22:00–22:45 on Nov 1 local is 01:00–01:45 UTC on Nov 2: it
        // belongs to Nov 1, although its UTC date is Nov 2.
        $this->appointmentAt('22:00', [], null, '2026-11-01');
        $early = $this->appointmentAt('09:00');
        $late = $this->appointmentAt('17:00');
        $this->appointmentAt('09:00', [], null, '2026-11-03');

        $response = $this->getJson('/api/v1/admin/agenda?date=2026-11-02')->assertOk();

        $response->assertJsonPath('data.date', '2026-11-02')
            ->assertJsonPath('data.timezone', self::TZ)
            ->assertJsonPath('data.day.starts_at', '2026-11-02T03:00:00+00:00')
            ->assertJsonPath('data.day.ends_at', '2026-11-03T03:00:00+00:00');
        $this->assertSame([$early->public_id, $late->public_id], $response->json('data.appointments.*.public_id'));
    }

    public function test_items_carry_only_what_the_agenda_needs(): void
    {
        $appointment = $this->appointmentAt('10:00');
        AppointmentNotification::query()->create([
            'appointment_id' => $appointment->id,
            'kind' => AppointmentNotification::KIND_CONFIRMATION,
            'status' => AppointmentNotification::STATUS_FAILED,
            'attempts' => 1,
        ]);

        $item = $this->getJson('/api/v1/admin/agenda?date=2026-11-02')->json('data.appointments.0');

        $this->assertSame([
            'public_id', 'professional_id', 'status', 'source', 'starts_at', 'ends_at', 'service',
            'customer_name', 'has_email', 'notification_status', 'allowed_actions',
            'visible_starts_at', 'visible_ends_at', 'continues_before', 'continues_after',
        ], array_keys($item));
        $this->assertSame(['id' => $this->service->id, 'name' => 'Corte degradê', 'duration_minutes' => 45], $item['service']);
        $this->assertSame('Cliente Sigiloso', $item['customer_name']);
        $this->assertTrue($item['has_email']);
        $this->assertSame('failed', $item['notification_status']);
        $this->assertSame(['cancel', 'resend_confirmation'], $item['allowed_actions']);
        $this->assertFalse($item['continues_before']);
        $this->assertStringNotContainsString('sigilo@example.com', json_encode($item));
        $this->assertStringNotContainsString('+5511912345678', json_encode($item));
    }

    public function test_professionals_with_working_periods_breaks_and_the_hours_to_draw(): void
    {
        $response = $this->getJson('/api/v1/admin/agenda?date=2026-11-02')->assertOk();

        $response->assertJsonPath('data.professionals.0.name', 'Rafael Almeida')
            ->assertJsonPath('data.professionals.0.working_periods', [
                ['starts_at' => '2026-11-02T12:00:00+00:00', 'ends_at' => '2026-11-02T15:00:00+00:00'],
                ['starts_at' => '2026-11-02T16:00:00+00:00', 'ends_at' => '2026-11-02T21:00:00+00:00'],
            ])
            ->assertJsonPath('data.professionals.0.breaks', [
                ['starts_at' => '2026-11-02T15:00:00+00:00', 'ends_at' => '2026-11-02T16:00:00+00:00'],
            ])
            ->assertJsonPath('data.hours', ['starts_at' => '2026-11-02T12:00:00+00:00', 'ends_at' => '2026-11-02T21:00:00+00:00']);
    }

    public function test_filters_by_professional(): void
    {
        $bruno = $this->workingProfessional('Bruno Costa');
        $this->appointmentAt('10:00');
        $brunos = $this->appointmentAt('10:00', [], $bruno);

        $all = $this->getJson('/api/v1/admin/agenda?date=2026-11-02')->json('data');
        $this->assertSame(['Bruno Costa', 'Rafael Almeida'], array_column($all['professionals'], 'name'));
        $this->assertCount(2, $all['appointments']);

        $only = $this->getJson("/api/v1/admin/agenda?date=2026-11-02&professional_id={$bruno->id}")->json('data');
        $this->assertSame(['Bruno Costa'], array_column($only['professionals'], 'name'));
        $this->assertSame([$brunos->public_id], array_column($only['appointments'], 'public_id'));
    }

    public function test_an_inactive_professional_is_shown_only_while_holding_reservations_that_day(): void
    {
        $inactive = $this->workingProfessional('Inativo Com Reserva');
        $inactive->update(['is_active' => false]);
        $this->appointmentAt('10:00', [], $inactive);
        Professional::factory()->create(['name' => 'Inativo Sem Nada', 'is_active' => false]);

        $names = $this->getJson('/api/v1/admin/agenda?date=2026-11-02')->json('data.professionals.*.name');

        $this->assertSame(['Inativo Com Reserva', 'Rafael Almeida'], $names);
    }

    public function test_cancelled_reservations_stay_listed_with_their_status(): void
    {
        $this->appointmentAt('10:00', ['status' => 'cancelled', 'cancelled_by' => 'customer', 'cancelled_at' => now()]);

        $this->getJson('/api/v1/admin/agenda?date=2026-11-02')
            ->assertJsonPath('data.appointments.0.status', 'cancelled')
            ->assertJsonPath('data.appointments.0.allowed_actions', []);
    }

    public function test_filters_by_status_without_reshaping_the_grid(): void
    {
        $confirmed = $this->appointmentAt('09:00');
        $cancelled = $this->appointmentAt('10:00', ['status' => 'cancelled', 'cancelled_by' => 'admin', 'cancelled_at' => now()]);
        $noShow = $this->appointmentAt('11:00', ['status' => 'no_show']);

        $all = $this->getJson('/api/v1/admin/agenda?date=2026-11-02')->json('data');
        $this->assertSame([$confirmed->public_id, $cancelled->public_id, $noShow->public_id], array_column($all['appointments'], 'public_id'));

        $some = $this->getJson('/api/v1/admin/agenda?date=2026-11-02&status[]=cancelled&status[]=no_show')->json('data');
        $this->assertSame([$cancelled->public_id, $noShow->public_id], array_column($some['appointments'], 'public_id'));
        $this->assertSame($all['hours'], $some['hours']);
        $this->assertSame($all['professionals'], $some['professionals']);

        $this->getJson('/api/v1/admin/agenda?date=2026-11-02&status[]=pending')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status.0'], 'error.fields');
    }

    public function test_a_range_of_days_lists_reservations_in_order_with_filters(): void
    {
        $bruno = $this->workingProfessional('Bruno Costa');
        $before = $this->appointmentAt('09:00', [], null, '2026-11-01');
        $first = $this->appointmentAt('17:00', [], null, '2026-11-02');
        $brunos = $this->appointmentAt('09:00', [], $bruno, '2026-11-03');
        $cancelled = $this->appointmentAt('10:00', ['status' => 'cancelled', 'cancelled_by' => 'customer', 'cancelled_at' => now()], null, '2026-11-04');
        $after = $this->appointmentAt('09:00', [], null, '2026-11-05');

        $data = $this->getJson('/api/v1/admin/appointments?from=2026-11-02&to=2026-11-04')
            ->assertOk()
            ->assertJsonPath('data.timezone', self::TZ)
            ->assertJsonPath('data.range.starts_at', '2026-11-02T03:00:00+00:00')
            ->assertJsonPath('data.range.ends_at', '2026-11-05T03:00:00+00:00')
            ->json('data');
        $this->assertSame([$first->public_id, $brunos->public_id, $cancelled->public_id], array_column($data['appointments'], 'public_id'));
        $this->assertSame(['Rafael Almeida', 'Bruno Costa', 'Rafael Almeida'], array_column($data['appointments'], 'professional_name'));
        $this->assertNotContains($before->public_id, array_column($data['appointments'], 'public_id'));
        $this->assertNotContains($after->public_id, array_column($data['appointments'], 'public_id'));

        // Like the day view, never the contacts or the price.
        $item = $data['appointments'][0];
        $this->assertArrayNotHasKey('customer', $item);
        $this->assertArrayNotHasKey('customer_email', $item);
        $this->assertArrayNotHasKey('customer_phone', $item);
        $this->assertArrayNotHasKey('price', $item['service']);

        $this->assertSame(
            [$brunos->public_id],
            array_column($this->getJson("/api/v1/admin/appointments?from=2026-11-02&to=2026-11-04&professional_id={$bruno->id}")->json('data.appointments'), 'public_id'),
        );
        $this->assertSame(
            [$cancelled->public_id],
            array_column($this->getJson('/api/v1/admin/appointments?from=2026-11-02&to=2026-11-04&status[]=cancelled')->json('data.appointments'), 'public_id'),
        );
    }

    public function test_a_range_must_be_ordered_and_at_most_31_days(): void
    {
        $this->getJson('/api/v1/admin/appointments?from=2026-11-02&to=2026-12-02')->assertOk();

        $this->getJson('/api/v1/admin/appointments?from=2026-11-02&to=2026-12-03')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to'], 'error.fields');
        $this->getJson('/api/v1/admin/appointments?from=2026-11-02&to=2026-11-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to'], 'error.fields');
        $this->getJson('/api/v1/admin/appointments?to=2026-11-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from'], 'error.fields');
    }

    public function test_blocks_and_closures_crossing_midnight_are_clipped_to_the_day(): void
    {
        ScheduleBlock::factory()->create([
            'professional_id' => $this->professional->id,
            'starts_at' => $this->local('20:00', '2026-11-01')->utc(),
            'ends_at' => $this->local('10:00')->utc(),
            'reason' => 'Consulta médica',
        ]);
        ScheduleBlock::factory()->create([
            'professional_id' => $this->professional->id,
            'group_id' => '01M481J2MVXTXKYF7QMCJB4PZ2',
            'starts_at' => $this->local('17:00')->utc(),
            'ends_at' => $this->local('09:00', '2026-11-03')->utc(),
            'reason' => 'Fechamento',
        ]);

        $blocks = $this->getJson('/api/v1/admin/agenda?date=2026-11-02')->json('data.blocks');

        $this->assertSame('professional', $blocks[0]['kind']);
        $this->assertSame('Consulta médica', $blocks[0]['reason']);
        $this->assertSame('2026-11-01T23:00:00+00:00', $blocks[0]['starts_at']);
        $this->assertSame('2026-11-02T03:00:00+00:00', $blocks[0]['visible_starts_at']);
        $this->assertTrue($blocks[0]['continues_before']);
        $this->assertFalse($blocks[0]['continues_after']);

        $this->assertSame('shop', $blocks[1]['kind']);
        $this->assertSame('2026-11-03T03:00:00+00:00', $blocks[1]['visible_ends_at']);
        $this->assertTrue($blocks[1]['continues_after']);
    }

    public function test_a_reservation_crossing_midnight_shows_on_both_days_clipped(): void
    {
        // Not creatable today (it must fit one working period, and periods
        // never cross midnight), but the agenda still has a defined answer.
        $late = $this->appointmentAt('23:30', [], null, '2026-11-01'); // until 00:15

        $previous = $this->getJson('/api/v1/admin/agenda?date=2026-11-01')->json('data.appointments.0');
        $next = $this->getJson('/api/v1/admin/agenda?date=2026-11-02')->json('data.appointments.0');

        $this->assertSame($late->public_id, $previous['public_id']);
        $this->assertTrue($previous['continues_after']);
        $this->assertSame('2026-11-02T03:00:00+00:00', $previous['visible_ends_at']);
        $this->assertSame($late->public_id, $next['public_id']);
        $this->assertTrue($next['continues_before']);
        $this->assertSame('2026-11-02T03:00:00+00:00', $next['visible_starts_at']);
        $this->assertSame('2026-11-02T03:15:00+00:00', $next['visible_ends_at']);
    }

    public function test_a_day_with_nothing_has_no_hours_to_draw(): void
    {
        // Sunday: no working periods, no reservations, no blocks.
        $this->getJson('/api/v1/admin/agenda?date=2026-11-01')
            ->assertOk()
            ->assertJsonPath('data.hours', null)
            ->assertJsonPath('data.appointments', [])
            ->assertJsonPath('data.professionals.0.working_periods', []);
    }

    public function test_invalid_date_or_unknown_professional_is_a_validation_error(): void
    {
        $this->getJson('/api/v1/admin/agenda?date=2026-02-30')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date'], 'error.fields');
        $this->getJson('/api/v1/admin/agenda?date=2026-11-02&professional_id=999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['professional_id'], 'error.fields');
    }
}
