<?php

namespace Tests\Feature\Admin;

use App\Models\Professional;
use App\Models\User;
use App\Models\WorkingHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkingHourTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_requires_an_authenticated_session(): void
    {
        $professional = Professional::factory()->create();

        $this->getJson("/api/v1/admin/professionals/{$professional->id}/working-hours")
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_update_requires_an_authenticated_session(): void
    {
        $professional = Professional::factory()->create();

        $this->putJson("/api/v1/admin/professionals/{$professional->id}/working-hours", [
            'days' => $this->emptyWeek(),
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_show_returns_all_seven_weekdays_with_empty_periods_for_a_professional_with_no_schedule(): void
    {
        $this->actingAs(User::factory()->create());
        $professional = Professional::factory()->create();

        $response = $this->getJson("/api/v1/admin/professionals/{$professional->id}/working-hours");

        $response->assertOk();
        $days = $response->json('data.days');
        $this->assertCount(7, $days);
        $this->assertSame(range(0, 6), collect($days)->pluck('weekday')->all());
        $this->assertTrue(collect($days)->every(fn (array $day) => $day['periods'] === []));
    }

    public function test_update_saves_a_lunch_break_a_day_off_and_adjacent_periods(): void
    {
        $this->actingAs(User::factory()->create());
        $professional = Professional::factory()->create();

        $days = $this->emptyWeek();
        // Monday: morning + afternoon around a lunch break.
        $days[1]['periods'] = [
            ['start_time' => '09:00', 'end_time' => '12:00'],
            ['start_time' => '13:00', 'end_time' => '18:00'],
        ];
        // Tuesday: two adjacent periods (allowed, no gap required).
        $days[2]['periods'] = [
            ['start_time' => '09:00', 'end_time' => '12:00'],
            ['start_time' => '12:00', 'end_time' => '15:00'],
        ];
        // Sunday (weekday 0) stays empty — a day off.

        $response = $this->putJson("/api/v1/admin/professionals/{$professional->id}/working-hours", [
            'days' => $days,
        ]);

        $response->assertOk();
        $responseDays = collect($response->json('data.days'))->keyBy('weekday');
        $this->assertSame([
            ['start_time' => '09:00', 'end_time' => '12:00'],
            ['start_time' => '13:00', 'end_time' => '18:00'],
        ], $responseDays[1]['periods']);
        $this->assertSame([
            ['start_time' => '09:00', 'end_time' => '12:00'],
            ['start_time' => '12:00', 'end_time' => '15:00'],
        ], $responseDays[2]['periods']);
        $this->assertSame([], $responseDays[0]['periods']);
        $this->assertDatabaseCount('working_hours', 4);
    }

    public function test_update_rejects_overlapping_periods_on_the_same_day(): void
    {
        $this->actingAs(User::factory()->create());
        $professional = Professional::factory()->create();

        $days = $this->emptyWeek();
        $days[1]['periods'] = [
            ['start_time' => '09:00', 'end_time' => '13:00'],
            ['start_time' => '12:00', 'end_time' => '18:00'],
        ];

        $response = $this->putJson("/api/v1/admin/professionals/{$professional->id}/working-hours", [
            'days' => $days,
        ]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertDatabaseCount('working_hours', 0);
    }

    public function test_update_rejects_a_period_where_start_is_not_before_end(): void
    {
        $this->actingAs(User::factory()->create());
        $professional = Professional::factory()->create();

        $days = $this->emptyWeek();
        $days[1]['periods'] = [
            ['start_time' => '18:00', 'end_time' => '09:00'],
        ];

        $response = $this->putJson("/api/v1/admin/professionals/{$professional->id}/working-hours", [
            'days' => $days,
        ]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertDatabaseCount('working_hours', 0);
    }

    public function test_update_rejects_a_payload_missing_a_weekday(): void
    {
        $this->actingAs(User::factory()->create());
        $professional = Professional::factory()->create();

        $days = $this->emptyWeek();
        unset($days[6]);

        $response = $this->putJson("/api/v1/admin/professionals/{$professional->id}/working-hours", [
            'days' => array_values($days),
        ]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_update_returns_not_found_for_a_missing_professional(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->putJson('/api/v1/admin/professionals/999999/working-hours', [
            'days' => $this->emptyWeek(),
        ]);

        $response->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_an_invalid_update_does_not_alter_the_previously_saved_schedule(): void
    {
        $this->actingAs(User::factory()->create());
        $professional = Professional::factory()->create();

        $validDays = $this->emptyWeek();
        $validDays[1]['periods'] = [['start_time' => '09:00', 'end_time' => '12:00']];
        $this->putJson("/api/v1/admin/professionals/{$professional->id}/working-hours", ['days' => $validDays])
            ->assertOk();

        $invalidDays = $this->emptyWeek();
        $invalidDays[2]['periods'] = [
            ['start_time' => '09:00', 'end_time' => '13:00'],
            ['start_time' => '12:00', 'end_time' => '18:00'],
        ];
        $this->putJson("/api/v1/admin/professionals/{$professional->id}/working-hours", ['days' => $invalidDays])
            ->assertStatus(422);

        $this->assertDatabaseCount('working_hours', 1);
        $this->assertDatabaseHas('working_hours', [
            'professional_id' => $professional->id,
            'weekday' => 1,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
        ]);
    }

    public function test_update_replaces_a_previously_saved_schedule_instead_of_appending(): void
    {
        $this->actingAs(User::factory()->create());
        $professional = Professional::factory()->create();
        WorkingHour::factory()->for($professional)->create(['weekday' => 3]);

        $days = $this->emptyWeek();
        $days[1]['periods'] = [['start_time' => '10:00', 'end_time' => '16:00']];

        $this->putJson("/api/v1/admin/professionals/{$professional->id}/working-hours", ['days' => $days])
            ->assertOk();

        $this->assertDatabaseCount('working_hours', 1);
        $this->assertDatabaseHas('working_hours', ['professional_id' => $professional->id, 'weekday' => 1]);
        $this->assertDatabaseMissing('working_hours', ['professional_id' => $professional->id, 'weekday' => 3]);
    }

    /**
     * @return array<int, array{weekday: int, periods: array<int, array{start_time: string, end_time: string}>}>
     */
    private function emptyWeek(): array
    {
        return collect(range(0, 6))->map(fn (int $weekday) => ['weekday' => $weekday, 'periods' => []])->all();
    }
}
