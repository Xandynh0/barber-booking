<?php

namespace Tests\Feature;

use App\Mail\AppointmentConfirmationMail;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\BusinessSettings;
use App\Models\Professional;
use App\Models\Service;
use App\Models\WorkingHour;
use App\Services\Booking\ConfirmationNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Confirmation e-mail of a public reservation and its recovery sweep
 * (docs/planejamento-barbearia-mvp.md, seções 5 e 6). "Now" is Monday
 * 2026-11-02 08:00 in São Paulo; the reservation is Tuesday 2026-11-03
 * 10:00–10:45. That the e-mail only leaves after the commit is proven with
 * committed data in ConfirmationAfterCommitTest.
 */
class BookingConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Sao_Paulo';

    private Service $service;

    private Professional $professional;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', self::TZ));
        config(['app.url' => 'http://localhost:8080']);

        BusinessSettings::factory()->create(['timezone' => self::TZ, 'name' => 'Barbearia Teste', 'address' => 'Rua das Navalhas, 10', 'phone' => '(11) 3333-4444']);
        $this->service = Service::factory()->create(['name' => 'Corte degradê', 'duration_minutes' => 45, 'price' => '55.90']);
        $this->professional = Professional::factory()->create(['name' => 'Rafael Almeida']);
        $this->professional->services()->attach($this->service);
        WorkingHour::factory()->create(['professional_id' => $this->professional->id, 'weekday' => 2, 'start_time' => '09:00', 'end_time' => '18:00']);
    }

    public function test_a_new_reservation_sends_exactly_one_confirmation_and_records_it_as_sent(): void
    {
        Mail::fake();

        $response = $this->book()->assertCreated()->assertJsonPath('data.notification_status', 'sent');

        Mail::assertSent(AppointmentConfirmationMail::class, 1);
        Mail::assertSent(AppointmentConfirmationMail::class, fn (AppointmentConfirmationMail $mail) => $mail->hasTo('cliente@example.com'));

        $notification = AppointmentNotification::query()->sole();
        $this->assertSame($response->json('data.public_id'), $notification->appointment->public_id);
        $this->assertSame(AppointmentNotification::KIND_CONFIRMATION, $notification->kind);
        $this->assertSame(AppointmentNotification::STATUS_SENT, $notification->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertNotNull($notification->sent_at);
    }

    public function test_replaying_the_same_idempotency_key_does_not_send_a_second_email(): void
    {
        Mail::fake();
        $key = (string) Str::uuid();

        $this->book([], $key)->assertCreated();
        $this->book([], $key)->assertOk()->assertJsonPath('data.notification_status', 'sent');
        $this->book([], $key)->assertOk();

        Mail::assertSent(AppointmentConfirmationMail::class, 1);
        $this->assertDatabaseCount('appointment_notifications', 1);
        $this->assertSame(1, AppointmentNotification::query()->sole()->attempts);
    }

    public function test_a_refused_reservation_sends_nothing_and_records_nothing(): void
    {
        Mail::fake();
        $this->book()->assertCreated();

        $this->book(['customer_email' => 'outra@example.com', 'customer_phone' => '+5511988887777'])->assertStatus(409);

        Mail::assertSent(AppointmentConfirmationMail::class, 1);
        $this->assertDatabaseCount('appointment_notifications', 1);
    }

    public function test_the_email_contains_the_reservation_details_and_the_cancellation_link(): void
    {
        Mail::fake();
        $publicId = $this->book()->json('data.public_id');

        Mail::assertSent(AppointmentConfirmationMail::class, function (AppointmentConfirmationMail $mail) use ($publicId) {
            $html = $mail->render();

            $this->assertSame('Reserva confirmada — terça-feira, 03 de novembro de 2026', $mail->envelope()->subject);
            foreach (['Olá, Cliente Teste!', 'Corte degradê', 'Rafael Almeida', 'terça-feira, 03 de novembro de 2026', '10:00–10:45', '45 min', 'R$ 55,90', 'Rua das Navalhas, 10', $publicId, 'Barbearia Teste', '(11) 3333-4444'] as $expected) {
                $this->assertStringContainsString(e($expected), $html, "Missing in e-mail: {$expected}");
            }
            $this->assertStringContainsString(e($mail->cancellationUrl), $html);
            // Even the framework's mail footer follows the locale.
            $this->assertStringContainsString('Todos os direitos reservados.', $html);
            $this->assertStringNotContainsString('All rights reserved.', $html);

            return true;
        });
    }

    public function test_the_cancellation_link_is_signed_for_this_reservation_and_expires_at_its_start(): void
    {
        Mail::fake();
        $publicId = $this->book()->json('data.public_id');
        $appointment = Appointment::query()->where('public_id', $publicId)->sole();

        Mail::assertSent(AppointmentConfirmationMail::class, function (AppointmentConfirmationMail $mail) use ($publicId, $appointment) {
            $url = parse_url($mail->cancellationUrl);
            parse_str($url['query'], $query);

            $this->assertSame('http', $url['scheme']);
            $this->assertSame('localhost', $url['host']);
            $this->assertSame(8080, $url['port']);
            $this->assertSame("/cancelar/{$publicId}", $url['path']);
            $this->assertSame((string) $appointment->starts_at->getTimestamp(), $query['expires']);
            $this->assertNotEmpty($query['signature']);
            $this->assertStringNotContainsString((string) $appointment->id.'?', $mail->cancellationUrl);

            // The link opens this reservation's page.
            $this->get($url['path'].'?'.$url['query'])->assertOk()->assertSee($publicId);

            return true;
        });
    }

    public function test_the_email_uses_the_snapshots_not_the_current_service(): void
    {
        Mail::fake();
        $this->book()->assertCreated();
        Mail::assertSent(AppointmentConfirmationMail::class, 1);

        // The service changes after booking; a later (re)send must still say
        // what was contracted.
        $this->service->update(['name' => 'Degradê premium', 'price' => '99.00', 'duration_minutes' => 60]);
        $notification = AppointmentNotification::query()->sole();
        $notification->update(['status' => AppointmentNotification::STATUS_FAILED]);

        app(ConfirmationNotifier::class)->deliver($notification->fresh());

        Mail::assertSent(AppointmentConfirmationMail::class, 2);
        $last = Mail::sent(AppointmentConfirmationMail::class)->last();
        $html = $last->render();
        $this->assertStringContainsString('Corte degradê', $html);
        $this->assertStringContainsString('R$ 55,90', $html);
        $this->assertStringContainsString('45 min', $html);
        $this->assertStringNotContainsString('Degradê premium', $html);
        $this->assertStringNotContainsString('99,00', $html);
    }

    public function test_the_email_follows_the_request_language(): void
    {
        Mail::fake();

        $this->withHeaders(['Accept-Language' => 'en'])->book()->assertCreated();

        Mail::assertSent(AppointmentConfirmationMail::class, function (AppointmentConfirmationMail $mail) {
            $this->assertSame('Booking confirmed — Tuesday, November 3, 2026', $mail->envelope()->subject);
            $this->assertStringContainsString('R$55.90', $mail->render());

            return true;
        });
    }

    public function test_a_mail_failure_keeps_the_reservation_and_records_the_error(): void
    {
        // A real SMTP transport pointed at a closed port: the send throws.
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 2,
        ]);

        $this->book()->assertCreated()->assertJsonPath('data.notification_status', 'failed');

        $this->assertDatabaseCount('appointments', 1);
        $notification = AppointmentNotification::query()->sole();
        $this->assertSame(AppointmentNotification::STATUS_FAILED, $notification->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertNotEmpty($notification->last_error_code);
        $this->assertStringNotContainsString('@', $notification->last_error_code);
    }

    public function test_a_smtp_server_that_never_answers_does_not_hold_the_booking_request(): void
    {
        // Regression: with no SMTP timeout the request waited for PHP's
        // default_socket_timeout (60s) and the proxy answered 504 although the
        // reservation was committed. This server accepts the connection and
        // never sends the SMTP greeting — exactly what a hung provider does.
        $server = new Process([PHP_BINARY, '-r', '
            $socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
            fwrite(STDOUT, stream_socket_get_name($socket, false).PHP_EOL);
            $clients = [];
            while (true) { if ($client = @stream_socket_accept($socket, 1)) { $clients[] = $client; } }
        ']);
        $server->start();
        $address = null;
        $server->waitUntil(function (string $type, string $output) use (&$address) {
            $address = trim($output);

            return $address !== '';
        });
        [, $port] = explode(':', $address);

        try {
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.host' => '127.0.0.1',
                'mail.mailers.smtp.port' => (int) $port,
            ]);
            $this->assertSame(5, config('mail.mailers.smtp.timeout'));

            $started = microtime(true);
            $this->book()->assertCreated()->assertJsonPath('data.notification_status', 'failed');
            $elapsed = microtime(true) - $started;
        } finally {
            $server->stop(0);
        }

        $this->assertLessThan(20, $elapsed, 'The booking request must not wait for a hung SMTP server.');
        $this->assertDatabaseCount('appointments', 1);
        $this->assertSame(AppointmentNotification::STATUS_FAILED, AppointmentNotification::query()->sole()->status);
    }

    // --- Recovery sweep -----------------------------------------------------

    public function test_the_sweep_sends_stale_pending_and_failed_confirmations(): void
    {
        Mail::fake();
        $pending = $this->notification(AppointmentNotification::STATUS_PENDING, attempts: 0, minutesAgo: 5, time: '10:00');
        $failed = $this->notification(AppointmentNotification::STATUS_FAILED, attempts: 2, minutesAgo: 5, time: '11:00');

        $this->artisan('appointments:send-pending-confirmations')->assertSuccessful();

        Mail::assertSent(AppointmentConfirmationMail::class, 2);
        $this->assertSame(AppointmentNotification::STATUS_SENT, $pending->fresh()->status);
        $this->assertSame(AppointmentNotification::STATUS_SENT, $failed->fresh()->status);
        $this->assertSame(3, $failed->fresh()->attempts);
    }

    public function test_the_sweep_leaves_recent_pending_and_exhausted_confirmations_alone(): void
    {
        Mail::fake();
        $recent = $this->notification(AppointmentNotification::STATUS_PENDING, attempts: 0, minutesAgo: 0, time: '10:00');
        $exhausted = $this->notification(AppointmentNotification::STATUS_FAILED, attempts: 5, minutesAgo: 30, time: '11:00');

        $this->artisan('appointments:send-pending-confirmations')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(AppointmentNotification::STATUS_PENDING, $recent->fresh()->status);
        $this->assertSame(AppointmentNotification::STATUS_FAILED, $exhausted->fresh()->status);
    }

    public function test_the_sweep_skips_cancelled_and_past_reservations(): void
    {
        Mail::fake();
        $cancelled = $this->notification(AppointmentNotification::STATUS_PENDING, attempts: 0, minutesAgo: 5, time: '10:00');
        $cancelled->appointment->update(['status' => Appointment::STATUS_CANCELLED]);
        $past = $this->notification(AppointmentNotification::STATUS_FAILED, attempts: 1, minutesAgo: 5, time: '09:00');

        $this->travelTo(CarbonImmutable::parse('2026-11-03 10:30', self::TZ));
        $this->artisan('appointments:send-pending-confirmations')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(AppointmentNotification::STATUS_SKIPPED, $cancelled->fresh()->status);
        $this->assertSame(AppointmentNotification::STATUS_SKIPPED, $past->fresh()->status);
    }

    public function test_two_senders_holding_the_same_notification_deliver_it_only_once(): void
    {
        Mail::fake();
        $notification = $this->notification(AppointmentNotification::STATUS_PENDING, attempts: 0, minutesAgo: 5, time: '10:00');
        $staleCopy = AppointmentNotification::query()->findOrFail($notification->id);

        app(ConfirmationNotifier::class)->deliver($notification);
        $status = app(ConfirmationNotifier::class)->deliver($staleCopy);

        Mail::assertSent(AppointmentConfirmationMail::class, 1);
        $this->assertSame(AppointmentNotification::STATUS_SENT, $status);
        $this->assertSame(1, $notification->fresh()->attempts);
    }

    // --- Helpers --------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function book(array $overrides = [], ?string $key = null): TestResponse
    {
        return $this->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])
            ->postJson('/api/v1/public/appointments', array_merge([
                'service_id' => $this->service->id,
                'professional_id' => $this->professional->id,
                'starts_at' => '2026-11-03T10:00:00-03:00',
                'customer_name' => 'Cliente Teste',
                'customer_email' => 'Cliente@Example.com',
                'customer_phone' => '+5511999999999',
            ], $overrides));
    }

    private function notification(string $status, int $attempts, int $minutesAgo, string $time): AppointmentNotification
    {
        $appointment = Appointment::factory()->create([
            'professional_id' => $this->professional->id,
            'service_id' => $this->service->id,
            'service_name_snapshot' => 'Corte degradê',
            'duration_minutes_snapshot' => 45,
            'price_snapshot' => '55.90',
            'starts_at' => CarbonImmutable::parse("2026-11-03 {$time}", self::TZ)->utc(),
            'ends_at' => CarbonImmutable::parse("2026-11-03 {$time}", self::TZ)->utc()->addMinutes(45),
        ]);

        $notification = AppointmentNotification::create([
            'appointment_id' => $appointment->id,
            'kind' => AppointmentNotification::KIND_CONFIRMATION,
            'status' => $status,
            'attempts' => $attempts,
        ]);
        $notification->timestamps = false;
        $notification->forceFill(['updated_at' => now()->subMinutes($minutesAgo)])->save();

        return $notification->fresh();
    }
}
