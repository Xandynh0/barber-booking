<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BusinessSettings;
use App\Models\Professional;
use App\Models\Service;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Proof that POST /api/v1/public/appointments cannot double-book, with two
 * real, independent MySQL connections racing each other.
 *
 * Each attempt runs in its own PHP process (tests/Support/
 * post-public-appointment.php) that boots the application and goes through
 * the real HTTP kernel — no simplified test-only path. To make the race
 * deterministic, a third "gate" connection holds the business_settings row
 * lock first; the test waits until MySQL reports BOTH attempts blocked on
 * that same lock, then releases it so they compete for it.
 *
 * Data is committed (DatabaseTruncation, not RefreshDatabase): rows inside
 * a test transaction would be invisible to the other processes. Tables are
 * truncated again on tearDown so no committed row leaks into other tests.
 */
class PublicAppointmentConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private const TZ = 'America/Sao_Paulo';

    private const WAIT_FOR_BLOCKED_SECONDS = 30;

    private string $date;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires a real MySQL connection (DB_CONNECTION=mysql).');
        }

        config(['database.connections.lock_gate' => config('database.connections.mysql')]);

        // The child processes use the real clock, so the booked date is
        // relative to it: a week from now, inside the 30-day horizon and far
        // past the 60-minute notice.
        $this->date = CarbonImmutable::now(self::TZ)->addDays(7)->format('Y-m-d');

        BusinessSettings::factory()->create(['timezone' => self::TZ, 'min_notice_minutes' => 60, 'booking_horizon_days' => 30]);
        $this->service = Service::factory()->create(['duration_minutes' => 45]);
    }

    protected function tearDown(): void
    {
        DB::purge('lock_gate');
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_two_requests_for_the_same_slot_create_exactly_one_reservation(): void
    {
        $professional = $this->bookableProfessional();

        [$first, $second] = $this->race(
            $this->payload($professional, '10:00', 'a'),
            $this->payload($professional, '10:00', 'b'),
        );

        $this->assertOneCreatedOneRefused($first, $second, 'SLOT_UNAVAILABLE');
        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_two_requests_for_partially_overlapping_slots_create_exactly_one_reservation(): void
    {
        $professional = $this->bookableProfessional();

        // 10:00–10:45 and 10:15–11:00 overlap by 30 minutes.
        [$first, $second] = $this->race(
            $this->payload($professional, '10:00', 'a'),
            $this->payload($professional, '10:15', 'b'),
        );

        $this->assertOneCreatedOneRefused($first, $second, 'SLOT_UNAVAILABLE');
        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_two_requests_for_adjacent_slots_are_both_accepted(): void
    {
        $professional = $this->bookableProfessional();

        // 10:00–10:45 and 10:45–11:30 only touch.
        [$first, $second] = $this->race(
            $this->payload($professional, '10:00', 'a'),
            $this->payload($professional, '10:45', 'b'),
        );

        $this->assertSame([201, 201], [$first['status'], $second['status']]);
        $this->assertSame(2, Appointment::query()->count());
    }

    public function test_the_same_time_with_different_professionals_is_accepted_for_both(): void
    {
        $rafael = $this->bookableProfessional();
        $bruno = $this->bookableProfessional();

        [$first, $second] = $this->race(
            $this->payload($rafael, '10:00', 'a'),
            $this->payload($bruno, '10:00', 'b'),
        );

        $this->assertSame([201, 201], [$first['status'], $second['status']]);
        $this->assertSame(2, Appointment::query()->count());
    }

    public function test_the_contact_limit_holds_across_professionals_under_concurrency(): void
    {
        // Two future reservations already exist for this e-mail; the limit is
        // 3. Two simultaneous attempts on different professionals: only one
        // third reservation may be accepted (planejamento, seção 8).
        $rafael = $this->bookableProfessional();
        $bruno = $this->bookableProfessional();
        foreach (['14:00', '15:00'] as $i => $time) {
            Appointment::factory()->create([
                'professional_id' => $rafael->id,
                'service_id' => $this->service->id,
                'customer_email' => 'mesmo@example.com',
                'customer_phone' => '+551190000000'.$i,
                'starts_at' => CarbonImmutable::parse("{$this->date} {$time}", self::TZ)->utc(),
                'ends_at' => CarbonImmutable::parse("{$this->date} {$time}", self::TZ)->utc()->addMinutes(45),
            ]);
        }

        [$first, $second] = $this->race(
            $this->payload($rafael, '10:00', 'a', ['customer_email' => 'mesmo@example.com']),
            $this->payload($bruno, '10:00', 'b', ['customer_email' => 'mesmo@example.com']),
        );

        $this->assertOneCreatedOneRefused($first, $second, 'CONTACT_LIMIT_REACHED');
        $this->assertSame(3, Appointment::query()->where('customer_email', 'mesmo@example.com')->count());
    }

    public function test_a_double_submit_with_the_same_key_creates_one_reservation_and_replays_it(): void
    {
        $professional = $this->bookableProfessional();
        $payload = $this->payload($professional, '10:00', 'a');
        $payload['key'] = (string) Str::uuid();

        [$first, $second] = $this->race($payload, $payload);

        $statuses = [$first['status'], $second['status']];
        sort($statuses);
        $this->assertSame([200, 201], $statuses);
        $this->assertSame($first['body']['data']['public_id'], $second['body']['data']['public_id']);
        $this->assertSame(1, Appointment::query()->count());
    }

    // --- Race machinery ---------------------------------------------------

    /**
     * Holds the business_settings lock on a separate connection, starts both
     * attempts as separate processes, waits until MySQL shows both blocked
     * on that lock, then releases it.
     *
     * @param  array{body: array<string, mixed>, key: string, ip: string}  $first
     * @param  array{body: array<string, mixed>, key: string, ip: string}  $second
     * @return array{0: array{status: int, body: array<string, mixed>}, 1: array{status: int, body: array<string, mixed>}}
     */
    private function race(array $first, array $second): array
    {
        /** @var Connection $gate */
        $gate = DB::connection('lock_gate');
        $gateId = (int) $gate->selectOne('select connection_id() as id')->id;
        $gate->beginTransaction();
        $gate->select('select id from business_settings for update');

        $processes = [$this->attempt($first), $this->attempt($second)];
        foreach ($processes as $process) {
            $process->start();
        }

        try {
            $this->waitUntilBlockedOnTheLock(2, $gateId, $processes);
        } finally {
            $gate->commit();
        }

        return array_map(function (Process $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), 'Attempt process failed: '.$process->getErrorOutput().$process->getOutput());

            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }, $processes);
    }

    /**
     * @param  array{body: array<string, mixed>, key: string, ip: string}  $attempt
     */
    private function attempt(array $attempt): Process
    {
        $mysql = config('database.connections.mysql');

        return new Process(
            [PHP_BINARY, base_path('tests/Support/post-public-appointment.php'), json_encode($attempt['body']), $attempt['key'], $attempt['ip']],
            base_path(),
            [
                // Real environment variables win over .env (and over anything
                // phpunit.xml sets), so the child is pinned to this test's
                // database and to side-effect-free drivers.
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => (string) $mysql['host'],
                'DB_PORT' => (string) $mysql['port'],
                'DB_TEST_DATABASE' => (string) $mysql['database'],
                'DB_USERNAME' => (string) $mysql['username'],
                'DB_PASSWORD' => (string) $mysql['password'],
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'MAIL_MAILER' => 'array',
                'LOG_CHANNEL' => 'stderr',
            ],
            timeout: 120,
        );
    }

    /**
     * @param  array<int, Process>  $processes
     */
    private function waitUntilBlockedOnTheLock(int $expected, int $gateId, array $processes): void
    {
        $deadline = microtime(true) + self::WAIT_FOR_BLOCKED_SECONDS;

        do {
            $blocked = (int) DB::selectOne(
                "select count(*) as total from information_schema.processlist
                 where db = database() and id not in (connection_id(), ?)
                 and info like 'select % from `business_settings` % for update'",
                [$gateId],
            )->total;

            if ($blocked >= $expected) {
                return;
            }

            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    $this->fail('An attempt finished before reaching the lock: '.$process->getErrorOutput().$process->getOutput());
                }
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        $this->fail("Expected {$expected} attempts blocked on the business_settings lock, saw {$blocked}.");
    }

    // --- Data ---------------------------------------------------------------

    private function bookableProfessional(): Professional
    {
        $professional = Professional::factory()->create();
        $professional->services()->attach($this->service);

        WorkingHour::factory()->create([
            'professional_id' => $professional->id,
            'weekday' => CarbonImmutable::parse($this->date, self::TZ)->dayOfWeek,
            'start_time' => '09:00',
            'end_time' => '18:00',
        ]);

        return $professional;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{body: array<string, mixed>, key: string, ip: string}
     */
    private function payload(Professional $professional, string $localTime, string $customer, array $overrides = []): array
    {
        return [
            'body' => array_merge([
                'service_id' => $this->service->id,
                'professional_id' => $professional->id,
                'starts_at' => CarbonImmutable::parse("{$this->date} {$localTime}", self::TZ)->toIso8601String(),
                'customer_name' => "Cliente {$customer}",
                'customer_email' => "cliente-{$customer}@example.com",
                'customer_phone' => $customer === 'a' ? '+5511911110000' : '+5511922220000',
            ], $overrides),
            'key' => (string) Str::uuid(),
            'ip' => $customer === 'a' ? '10.0.0.1' : '10.0.0.2',
        ];
    }

    /**
     * @param  array{status: int, body: array<string, mixed>}  $first
     * @param  array{status: int, body: array<string, mixed>}  $second
     */
    private function assertOneCreatedOneRefused(array $first, array $second, string $expectedCode): void
    {
        $byStatus = [$first['status'] => $first, $second['status'] => $second];
        ksort($byStatus);

        $this->assertSame([201, 409], array_keys($byStatus), 'Expected exactly one 201 and one 409, got '.json_encode([$first, $second]));
        $this->assertSame($expectedCode, $byStatus[409]['body']['error']['code']);
        $this->assertSame('confirmed', $byStatus[201]['body']['data']['status']);
    }
}
