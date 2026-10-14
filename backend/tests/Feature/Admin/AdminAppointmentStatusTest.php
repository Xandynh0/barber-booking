<?php

namespace Tests\Feature\Admin;

use App\Mail\AppointmentConfirmationMail;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Details, status transitions, cancellation and resending the confirmation
 * from the admin agenda.
 */
class AdminAppointmentStatusTest extends TestCase
{
    use AgendaFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgenda();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function protectedRoutes(): array
    {
        return [
            'agenda' => ['GET', '/api/v1/admin/agenda?date=2026-11-02'],
            'range' => ['GET', '/api/v1/admin/appointments?from=2026-11-02&to=2026-11-08'],
            'details' => ['GET', '/api/v1/admin/appointments/{id}'],
            'create' => ['POST', '/api/v1/admin/appointments'],
            'cancel' => ['POST', '/api/v1/admin/appointments/{id}/cancel'],
            'complete' => ['POST', '/api/v1/admin/appointments/{id}/complete'],
            'no-show' => ['POST', '/api/v1/admin/appointments/{id}/no-show'],
            'resend' => ['POST', '/api/v1/admin/appointments/{id}/resend-confirmation'],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_every_agenda_route_requires_an_admin_session(string $method, string $uri): void
    {
        $appointment = $this->appointmentAt('10:00');

        $this->json($method, str_replace('{id}', $appointment->public_id, $uri))
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->assertSame(Appointment::STATUS_CONFIRMED, $appointment->fresh()->status);
        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_details_show_contacts_price_and_allowed_actions(): void
    {
        $this->signIn();
        $appointment = $this->appointmentAt('10:00');

        $this->getJson("/api/v1/admin/appointments/{$appointment->public_id}")
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Cliente Sigiloso')
            ->assertJsonPath('data.customer.email', 'sigilo@example.com')
            ->assertJsonPath('data.customer.phone', '+5511912345678')
            ->assertJsonPath('data.professional.name', 'Rafael Almeida')
            ->assertJsonPath('data.timezone', self::TZ)
            ->assertJsonPath('data.allowed_actions', ['cancel', 'resend_confirmation']);
    }

    public function test_an_unknown_reservation_is_not_found(): void
    {
        $this->signIn();

        $this->getJson('/api/v1/admin/appointments/01ARZ3NDEKTSV4RRFFQ69G5FAV')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    // --- Cancellation -------------------------------------------------------

    public function test_cancelling_frees_the_slot_and_keeps_the_history(): void
    {
        $this->signIn();
        $appointment = $this->appointmentAt('10:00');
        $before = $appointment->only(['customer_name', 'customer_email', 'customer_phone', 'service_name_snapshot', 'price_snapshot', 'starts_at', 'ends_at']);

        $this->postJson("/api/v1/admin/appointments/{$appointment->public_id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_by', 'admin')
            ->assertJsonPath('data.allowed_actions', [])
            ->assertJsonPath('meta.changed', true);

        $cancelled = $appointment->fresh();
        $this->assertSame('2026-11-02 11:00:00', $cancelled->cancelled_at->format('Y-m-d H:i:s'));
        $this->assertEquals($before, $cancelled->only(array_keys($before)));
        $this->assertSame(1, Appointment::query()->count());

        // The same interval is offered again and can be booked again.
        $slots = $this->getJson("/api/v1/admin/availability?service_id={$this->service->id}&professional_id={$this->professional->id}&date=2026-11-02")
            ->json('data.slots.*.starts_at');
        $this->assertContains('2026-11-02T13:00:00+00:00', $slots);
        $this->createAdmin()->assertCreated();
    }

    public function test_cancelling_again_is_idempotent(): void
    {
        $this->signIn();
        $appointment = $this->appointmentAt('10:00');

        $this->postJson("/api/v1/admin/appointments/{$appointment->public_id}/cancel")->assertOk();
        $cancelledAt = $appointment->fresh()->cancelled_at;

        $this->travel(5)->minutes();
        $this->postJson("/api/v1/admin/appointments/{$appointment->public_id}/cancel")
            ->assertOk()
            ->assertJsonPath('meta.changed', false);
        $this->assertEquals($cancelledAt, $appointment->fresh()->cancelled_at);
    }

    public function test_the_admin_can_cancel_after_the_start_and_past_the_customer_deadline(): void
    {
        $this->signIn();
        $appointment = $this->appointmentAt('10:00');
        $this->travelTo($this->local('10:20'));

        $this->postJson("/api/v1/admin/appointments/{$appointment->public_id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_a_customer_cancellation_is_kept_as_customer(): void
    {
        $this->signIn();
        $appointment = $this->appointmentAt('10:00', ['status' => 'cancelled', 'cancelled_by' => 'customer', 'cancelled_at' => now()]);

        $this->postJson("/api/v1/admin/appointments/{$appointment->public_id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.cancelled_by', 'customer')
            ->assertJsonPath('meta.changed', false);
    }

    // --- Completed / no-show ----------------------------------------------

    public function test_completed_only_after_the_end(): void
    {
        $this->signIn();
        $appointment = $this->appointmentAt('10:00'); // 10:00–10:45
        $this->travelTo($this->local('10:30'));

        $this->postJson("/api/v1/admin/appointments/{$appointment->public_id}/complete")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STATUS_TRANSITION_NOT_ALLOWED')
            ->assertJsonPath('error.reason', 'not_finished')
            ->assertJsonPath('error.status', 'confirmed');

        $this->travelTo($this->local('10:45'));
        $this->postJson("/api/v1/admin/appointments/{$appointment->public_id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.allowed_actions', []);

        // Still occupies the interval in the history.
        $this->travelTo($this->local('08:00'));
        $this->createAdmin()->assertStatus(409)->assertJsonPath('error.reason', 'busy');
    }

    public function test_no_show_only_after_the_start(): void
    {
        $this->signIn();
        $appointment = $this->appointmentAt('10:00');
        $this->travelTo($this->local('09:59'));

        $this->postJson("/api/v1/admin/appointments/{$appointment->public_id}/no-show")
            ->assertStatus(409)
            ->assertJsonPath('error.reason', 'not_started');

        $this->travelTo($this->local('10:00'));
        $this->getJson("/api/v1/admin/appointments/{$appointment->public_id}")
            ->assertJsonPath('data.allowed_actions', ['cancel', 'no_show']);
        $this->postJson("/api/v1/admin/appointments/{$appointment->public_id}/no-show")
            ->assertOk()
            ->assertJsonPath('data.status', 'no_show');
    }

    public function test_final_states_cannot_change_but_repeating_them_is_harmless(): void
    {
        $this->signIn();
        $appointment = $this->appointmentAt('10:00');
        $this->travelTo($this->local('11:00'));
        $this->postJson("/api/v1/admin/appointments/{$appointment->public_id}/complete")->assertOk();

        $this->postJson("/api/v1/admin/appointments/{$appointment->public_id}/complete")
            ->assertOk()
            ->assertJsonPath('meta.changed', false);

        foreach (['cancel', 'no-show'] as $action) {
            $this->postJson("/api/v1/admin/appointments/{$appointment->public_id}/{$action}")
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'STATUS_TRANSITION_NOT_ALLOWED')
                ->assertJsonPath('error.reason', 'final_state')
                ->assertJsonPath('error.status', 'completed');
        }

        $cancelled = $this->appointmentAt('14:00', ['status' => 'cancelled', 'cancelled_by' => 'admin', 'cancelled_at' => now()]);
        $this->travelTo($this->local('15:00'));
        $this->postJson("/api/v1/admin/appointments/{$cancelled->public_id}/complete")
            ->assertStatus(409)
            ->assertJsonPath('error.reason', 'final_state');
        $this->assertSame('completed', $appointment->fresh()->status);
        $this->assertSame('cancelled', $cancelled->fresh()->status);
    }

    // --- Resending the confirmation --------------------------------------

    public function test_resending_an_eligible_confirmation_delivers_it_again(): void
    {
        Mail::fake();
        $this->signIn();
        $appointment = $this->appointmentAt('10:00');
        AppointmentNotification::query()->create([
            'appointment_id' => $appointment->id,
            'kind' => AppointmentNotification::KIND_CONFIRMATION,
            'status' => AppointmentNotification::STATUS_SENT,
            'attempts' => 1,
            'sent_at' => now()->subHour(),
        ]);

        $this->postJson("/api/v1/admin/appointments/{$appointment->public_id}/resend-confirmation")
            ->assertOk()
            ->assertJsonPath('meta.attempt_status', 'sent')
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.notification.attempts', 2);

        Mail::assertSent(AppointmentConfirmationMail::class, 1);
        $this->assertSame(1, AppointmentNotification::query()->count());
    }

    public function test_the_resent_email_carries_a_freshly_signed_cancellation_link_that_opens(): void
    {
        Mail::fake();
        $this->signIn();
        $appointment = $this->appointmentAt('10:00');

        $this->postJson("/api/v1/admin/appointments/{$appointment->public_id}/resend-confirmation")->assertOk();

        Mail::assertSent(AppointmentConfirmationMail::class, function (AppointmentConfirmationMail $mail) use ($appointment) {
            $url = parse_url($mail->cancellationUrl);
            parse_str($url['query'], $query);

            $this->assertSame("/cancelar/{$appointment->public_id}", $url['path']);
            $this->assertSame((string) $appointment->starts_at->getTimestamp(), $query['expires']);
            $this->assertNotEmpty($query['signature']);
            $this->get($url['path'].'?'.$url['query'])->assertOk()->assertSee($appointment->public_id);

            return true;
        });
        // Nothing about the link is stored: it is signed at each send.
        $this->assertStringNotContainsString('signature', json_encode(AppointmentNotification::query()->sole()->toArray()));
    }

    public function test_resending_creates_the_notification_when_there_was_none(): void
    {
        Mail::fake();
        $this->signIn();
        $appointment = $this->appointmentAt('10:00');

        $this->postJson("/api/v1/admin/appointments/{$appointment->public_id}/resend-confirmation")
            ->assertOk()
            ->assertJsonPath('meta.attempt_status', 'sent');
        Mail::assertSent(AppointmentConfirmationMail::class, 1);
    }

    public function test_resending_is_refused_without_email_after_cancellation_or_once_started(): void
    {
        Mail::fake();
        $this->signIn();

        $noEmail = $this->appointmentAt('10:00', ['customer_email' => null]);
        $cancelled = $this->appointmentAt('14:00', ['status' => 'cancelled', 'cancelled_by' => 'admin', 'cancelled_at' => now()]);
        $started = $this->appointmentAt('09:00');

        $this->postJson("/api/v1/admin/appointments/{$noEmail->public_id}/resend-confirmation")
            ->assertStatus(409)->assertJsonPath('error.code', 'RESEND_NOT_ALLOWED')->assertJsonPath('error.reason', 'no_email');
        $this->postJson("/api/v1/admin/appointments/{$cancelled->public_id}/resend-confirmation")
            ->assertStatus(409)->assertJsonPath('error.reason', 'not_confirmed');

        $this->travelTo($this->local('09:10'));
        $this->postJson("/api/v1/admin/appointments/{$started->public_id}/resend-confirmation")
            ->assertStatus(409)->assertJsonPath('error.reason', 'not_future');

        Mail::assertNothingSent();
    }

    public function test_a_smtp_failure_is_reported_without_touching_the_reservation(): void
    {
        $this->signIn();
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 2,
        ]);
        $appointment = $this->appointmentAt('10:00');

        $this->postJson("/api/v1/admin/appointments/{$appointment->public_id}/resend-confirmation")
            ->assertOk()
            ->assertJsonPath('meta.attempt_status', 'failed')
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.notification.status', 'failed');

        $this->assertSame(Appointment::STATUS_CONFIRMED, $appointment->fresh()->status);
    }

    public function test_resending_stops_at_the_attempt_limit(): void
    {
        Mail::fake();
        $this->signIn();
        $appointment = $this->appointmentAt('10:00');
        AppointmentNotification::query()->create([
            'appointment_id' => $appointment->id,
            'kind' => AppointmentNotification::KIND_CONFIRMATION,
            'status' => AppointmentNotification::STATUS_FAILED,
            'attempts' => 5,
        ]);

        $this->getJson("/api/v1/admin/appointments/{$appointment->public_id}")
            ->assertJsonPath('data.allowed_actions', ['cancel']);
        $this->postJson("/api/v1/admin/appointments/{$appointment->public_id}/resend-confirmation")
            ->assertStatus(409)
            ->assertJsonPath('error.reason', 'attempts_exhausted');
        Mail::assertNothingSent();
    }

    public function test_messages_follow_the_request_language(): void
    {
        $this->signIn();
        $appointment = $this->appointmentAt('10:00', ['customer_email' => null]);

        $pt = $this->postJson("/api/v1/admin/appointments/{$appointment->public_id}/resend-confirmation", [], ['Accept-Language' => 'pt-BR']);
        $en = $this->postJson("/api/v1/admin/appointments/{$appointment->public_id}/resend-confirmation", [], ['Accept-Language' => 'en']);

        $this->assertSame($pt->json('error.code'), $en->json('error.code'));
        $this->assertStringContainsString('e-mail', $pt->json('error.message'));
        $this->assertStringContainsString('no e-mail', $en->json('error.message'));
        $this->assertNotSame($pt->json('error.message'), $en->json('error.message'));
    }
}
