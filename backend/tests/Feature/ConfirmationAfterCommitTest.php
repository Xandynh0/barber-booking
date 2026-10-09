<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\BusinessSettings;
use App\Models\Professional;
use App\Models\Service;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Proves the confirmation e-mail leaves only after the reservation's
 * transaction committed: at the moment the message is being sent, a second,
 * independent MySQL connection must already see the reservation and its
 * notification. Uses committed data (DatabaseTruncation) — inside a
 * RefreshDatabase transaction nothing would ever be visible to another
 * connection. Tables are truncated again on tearDown.
 */
class ConfirmationAfterCommitTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires a real MySQL connection (DB_CONNECTION=mysql).');
        }

        config(['database.connections.commit_probe' => config('database.connections.mysql')]);
        // A real mail transport (no Mail::fake) so MessageSending fires. The
        // array transport keeps the message in memory: nothing reaches Mailpit.
        config(['mail.default' => 'array']);
    }

    protected function tearDown(): void
    {
        DB::purge('commit_probe');
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_the_reservation_is_already_committed_when_the_email_is_sent(): void
    {
        $timezone = 'America/Sao_Paulo';
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', $timezone));
        BusinessSettings::factory()->create(['timezone' => $timezone]);
        $service = Service::factory()->create(['duration_minutes' => 45]);
        $professional = Professional::factory()->create();
        $professional->services()->attach($service);
        WorkingHour::factory()->create(['professional_id' => $professional->id, 'weekday' => 2, 'start_time' => '09:00', 'end_time' => '18:00']);

        $seenByAnotherConnection = null;
        Event::listen(MessageSending::class, function () use (&$seenByAnotherConnection) {
            $probe = DB::connection('commit_probe');
            $seenByAnotherConnection = [
                'appointments' => $probe->table('appointments')->count(),
                'notifications' => $probe->table('appointment_notifications')->count(),
            ] + $this->lockStateDuringSend();
        });

        $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/public/appointments', [
                'service_id' => $service->id,
                'professional_id' => $professional->id,
                'starts_at' => '2026-11-03T10:00:00-03:00',
                'customer_name' => 'Cliente Teste',
                'customer_email' => 'cliente@example.com',
                'customer_phone' => '+5511999999999',
            ])
            ->assertCreated()
            ->assertJsonPath('data.notification_status', 'sent');

        $this->assertSame(
            ['appointments' => 1, 'notifications' => 1, 'open_transactions' => 0, 'agenda_lock_free' => true],
            $seenByAnotherConnection,
            'The e-mail must only be sent once another connection can see the committed reservation, '
            .'and the SMTP call must not hold any transaction or the agenda lock.',
        );
    }

    public function test_the_recovery_sweep_sends_without_holding_any_transaction_or_the_agenda_lock(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', 'America/Sao_Paulo'));
        BusinessSettings::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $appointment = Appointment::factory()->create([
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
        ]);
        $notification = AppointmentNotification::create([
            'appointment_id' => $appointment->id,
            'kind' => AppointmentNotification::KIND_CONFIRMATION,
            'status' => AppointmentNotification::STATUS_FAILED,
            'attempts' => 1,
        ]);
        $notification->timestamps = false;
        $notification->forceFill(['updated_at' => now()->subMinutes(5)])->save();

        $seen = null;
        Event::listen(MessageSending::class, function () use (&$seen) {
            $seen = $this->lockStateDuringSend();
        });

        $this->artisan('appointments:send-pending-confirmations')->assertSuccessful();

        $this->assertSame(['open_transactions' => 0, 'agenda_lock_free' => true], $seen);
        $this->assertSame(AppointmentNotification::STATUS_SENT, $notification->fresh()->status);
    }

    /**
     * Measured at the moment the message is being handed to the transport.
     *
     * @return array{open_transactions: int, agenda_lock_free: bool}
     */
    private function lockStateDuringSend(): array
    {
        $probe = DB::connection('commit_probe');
        $probe->beginTransaction();
        try {
            // NOWAIT fails immediately if any other session holds the row.
            $probe->select('select id from business_settings for update nowait');
            $lockFree = true;
        } catch (QueryException) {
            $lockFree = false;
        } finally {
            $probe->rollBack();
        }

        return ['open_transactions' => DB::transactionLevel(), 'agenda_lock_free' => $lockFree];
    }
}
