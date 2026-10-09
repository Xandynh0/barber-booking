<?php

namespace Tests\Feature\Admin;

use App\Models\Appointment;
use App\Models\BusinessSettings;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\RacesAgendaWrites;
use Tests\TestCase;

/**
 * POST /api/v1/admin/appointments under real concurrency: two independent
 * PHP processes and MySQL connections (tests/Support/post-admin-
 * appointment.php), released together from the business_settings lock.
 * The global lock processes them one after the other; the revalidation
 * inside the transaction decides which one wins.
 */
class AdminAppointmentConcurrencyTest extends TestCase
{
    use DatabaseTruncation, RacesAgendaWrites;

    private const TZ = 'America/Sao_Paulo';

    private string $date;

    private Service $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires a real MySQL connection (DB_CONNECTION=mysql).');
        }

        config(['database.connections.lock_gate' => config('database.connections.mysql')]);

        // The child processes use the real clock.
        $this->date = CarbonImmutable::now(self::TZ)->addDays(7)->format('Y-m-d');

        BusinessSettings::factory()->create(['timezone' => self::TZ, 'min_notice_minutes' => 60, 'booking_horizon_days' => 30]);
        $this->service = Service::factory()->create(['duration_minutes' => 45]);
        $this->admin = User::factory()->create();
    }

    protected function tearDown(): void
    {
        DB::purge('lock_gate');
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_two_admin_bookings_for_the_same_interval_create_exactly_one(): void
    {
        $professional = $this->professionalWorking($this->date);

        [$first, $second] = $this->race(
            $this->scheduled($professional, '10:00', 'a'),
            $this->scheduled($professional, '10:15', 'b'),
        );

        $this->assertOneCreatedOneRefused($first, $second, 'busy');
        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_two_attend_now_for_the_same_professional_create_exactly_one(): void
    {
        $today = CarbonImmutable::now(self::TZ);
        // The working period ends at 23:59; 45 minutes plus margin.
        if ($today->greaterThan($today->setTime(23, 5))) {
            $this->markTestSkipped('Too close to midnight for a 45-minute "now" inside today\'s working period.');
        }
        $professional = $this->professionalWorking($today->format('Y-m-d'));

        [$first, $second] = $this->race(
            $this->now($professional, 'a'),
            $this->now($professional, 'b'),
        );

        $this->assertOneCreatedOneRefused($first, $second, 'busy');
        $this->assertSame(1, Appointment::query()->count());
        $created = Appointment::query()->sole();
        $this->assertSame(0, $created->starts_at->second);
    }

    public function test_different_professionals_at_the_same_time_are_both_accepted(): void
    {
        $rafael = $this->professionalWorking($this->date);
        $bruno = $this->professionalWorking($this->date);

        [$first, $second] = $this->race(
            $this->scheduled($rafael, '10:00', 'a'),
            $this->scheduled($bruno, '10:00', 'b'),
        );

        $this->assertSame([201, 201], [$first['status'], $second['status']]);
        $this->assertSame(2, Appointment::query()->count());
    }

    public function test_adjacent_intervals_for_the_same_professional_are_both_accepted(): void
    {
        $professional = $this->professionalWorking($this->date);

        [$first, $second] = $this->race(
            $this->scheduled($professional, '10:00', 'a'),
            $this->scheduled($professional, '10:45', 'b'),
        );

        $this->assertSame([201, 201], [$first['status'], $second['status']]);
        $this->assertSame(2, Appointment::query()->count());
    }

    // --- Helpers ------------------------------------------------------------

    /**
     * @param  array{body: array<string, mixed>, key: string}  $first
     * @param  array{body: array<string, mixed>, key: string}  $second
     * @return array{0: array{status: int, body: array<string, mixed>}, 1: array{status: int, body: array<string, mixed>}}
     */
    private function race(array $first, array $second): array
    {
        return $this->raceScripts(
            'post-admin-appointment.php',
            [(string) $this->admin->id, json_encode($first['body']), $first['key']],
            [(string) $this->admin->id, json_encode($second['body']), $second['key']],
        );
    }

    private function professionalWorking(string $date): Professional
    {
        $professional = Professional::factory()->create();
        $professional->services()->attach($this->service);

        WorkingHour::factory()->create([
            'professional_id' => $professional->id,
            'weekday' => CarbonImmutable::parse($date, self::TZ)->dayOfWeek,
            'start_time' => $date === $this->date ? '09:00' : '00:00',
            'end_time' => $date === $this->date ? '18:00' : '23:59',
        ]);

        return $professional;
    }

    /**
     * @return array{body: array<string, mixed>, key: string}
     */
    private function scheduled(Professional $professional, string $localTime, string $customer): array
    {
        return [
            'body' => [
                'mode' => 'scheduled',
                'service_id' => $this->service->id,
                'professional_id' => $professional->id,
                'starts_at' => CarbonImmutable::parse("{$this->date} {$localTime}", self::TZ)->toIso8601String(),
                'customer_name' => "Cliente {$customer}",
            ],
            'key' => (string) Str::uuid(),
        ];
    }

    /**
     * @return array{body: array<string, mixed>, key: string}
     */
    private function now(Professional $professional, string $customer): array
    {
        return [
            'body' => [
                'mode' => 'now',
                'service_id' => $this->service->id,
                'professional_id' => $professional->id,
                'customer_name' => "Cliente {$customer}",
            ],
            'key' => (string) Str::uuid(),
        ];
    }

    /**
     * @param  array{status: int, body: array<string, mixed>}  $first
     * @param  array{status: int, body: array<string, mixed>}  $second
     */
    private function assertOneCreatedOneRefused(array $first, array $second, string $expectedReason): void
    {
        $byStatus = [$first['status'] => $first, $second['status'] => $second];
        ksort($byStatus);

        $this->assertSame([201, 409], array_keys($byStatus), 'Expected exactly one 201 and one 409, got '.json_encode([$first, $second]));
        $this->assertSame('SLOT_UNAVAILABLE', $byStatus[409]['body']['error']['code']);
        $this->assertSame($expectedReason, $byStatus[409]['body']['error']['reason']);
        $this->assertSame('admin', $byStatus[201]['body']['data']['source']);
    }
}
