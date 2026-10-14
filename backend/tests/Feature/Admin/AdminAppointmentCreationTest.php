<?php

namespace Tests\Feature\Admin;

use App\Mail\AppointmentConfirmationMail;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\ScheduleBlock;
use App\Models\WorkingHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POST /api/v1/admin/appointments — "Agendar" and "Atender agora".
 */
class AdminAppointmentCreationTest extends TestCase
{
    use AgendaFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgenda();
    }

    // --- Scheduled ---------------------------------------------------------

    public function test_creates_a_reservation_with_email_and_sends_the_confirmation(): void
    {
        Mail::fake();
        $this->signIn();

        $response = $this->createAdmin(['customer_email' => ' Cliente@Example.com ', 'customer_phone' => '(11) 98888-7777']);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.source', 'admin')
            ->assertJsonPath('data.starts_at', '2026-11-02T13:00:00+00:00')
            ->assertJsonPath('data.ends_at', '2026-11-02T13:45:00+00:00')
            ->assertJsonPath('data.service.name', 'Corte degradê')
            ->assertJsonPath('data.service.price', '55.90')
            ->assertJsonPath('data.customer.email', 'cliente@example.com')
            ->assertJsonPath('data.customer.phone', '+5511988887777')
            ->assertJsonPath('data.notification_status', 'sent')
            ->assertJsonPath('meta.replayed', false);

        Mail::assertSent(AppointmentConfirmationMail::class, 1);
        $this->assertSame(1, AppointmentNotification::query()->count());
    }

    public function test_without_email_no_notification_is_created_and_the_response_says_so(): void
    {
        Mail::fake();
        $this->signIn();

        $response = $this->createAdmin(['customer_email' => '', 'customer_phone' => null]);

        $response->assertCreated()
            ->assertJsonPath('data.customer.email', null)
            ->assertJsonPath('data.customer.phone', null)
            ->assertJsonPath('data.notification', null)
            ->assertJsonPath('data.notification_status', 'none');
        $this->assertNotContains('resend_confirmation', $response->json('data.allowed_actions'));
        Mail::assertNothingSent();
        $this->assertSame(0, AppointmentNotification::query()->count());
    }

    public function test_the_name_is_required_and_contacts_are_validated_when_present(): void
    {
        $this->signIn();

        $this->createAdmin(['customer_name' => '', 'customer_email' => 'not-an-email', 'customer_phone' => '123'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['customer_name', 'customer_email', 'customer_phone'], 'error.fields');
        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_the_admin_ignores_the_minimum_notice_the_public_flow_enforces(): void
    {
        $this->signIn();
        // 08:00 now; 08:15 is inside the public 60-minute notice. The public
        // endpoint refuses it, the admin one accepts it (expediente permitting).
        $this->workingProfessionalEarly();

        $this->postJson('/api/v1/public/appointments', [
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'starts_at' => $this->local('08:15')->toIso8601String(),
            'customer_name' => 'Cliente Público',
            'customer_email' => 'publico@example.com',
            'customer_phone' => '+5511900000001',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('error.code', 'SLOT_UNAVAILABLE');

        $this->createAdmin(['starts_at' => $this->local('08:15')->toIso8601String()])->assertCreated();
    }

    public function test_the_admin_ignores_the_contact_limit(): void
    {
        $this->signIn();
        foreach (['13:00', '14:00', '15:00'] as $time) {
            $this->appointmentAt($time, ['customer_email' => 'mesmo@example.com']);
        }

        $this->createAdmin(['customer_email' => 'mesmo@example.com'])->assertCreated();
        $this->assertSame(4, Appointment::query()->where('customer_email', 'mesmo@example.com')->count());
    }

    public function test_a_scheduled_start_is_refused_with_the_reason(): void
    {
        $this->signIn();
        ScheduleBlock::factory()->create([
            'professional_id' => $this->professional->id,
            'starts_at' => $this->local('15:00')->utc(),
            'ends_at' => $this->local('16:00')->utc(),
        ]);
        $this->appointmentAt('10:00');

        $cases = [
            'busy' => $this->local('10:15'),
            'blocked' => $this->local('15:00'),
            'outside_working_hours' => $this->local('11:30'), // 11:30–12:15 crosses lunch
            'off_grid' => $this->local('16:10'),
            'past' => $this->local('07:00'),
        ];

        foreach ($cases as $reason => $start) {
            $this->createAdmin(['starts_at' => $start->toIso8601String()])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'SLOT_UNAVAILABLE')
                ->assertJsonPath('error.reason', $reason);
        }

        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_the_admin_books_beyond_the_public_horizon(): void
    {
        $this->signIn();
        $start = $this->local('10:00', '2026-12-07'); // today + 35 days, horizon 30

        $this->createAdmin(['starts_at' => $start->toIso8601String()])->assertCreated();
        $this->assertTrue(Appointment::query()->where('starts_at', $start->utc())->exists());
    }

    public function test_adjacent_intervals_are_accepted(): void
    {
        $this->signIn();
        $this->appointmentAt('10:00'); // 10:00–10:45

        $this->createAdmin(['starts_at' => $this->local('10:45')->toIso8601String()])->assertCreated();
        $this->createAdmin(['starts_at' => $this->local('09:15')->toIso8601String()])->assertCreated(); // ends 10:00
    }

    public function test_an_inactive_professional_or_unlinked_service_is_refused(): void
    {
        $this->signIn();
        $this->professional->update(['is_active' => false]);

        $this->createAdmin()->assertUnprocessable()->assertJsonValidationErrors(['professional_id'], 'error.fields');
    }

    public function test_the_snapshots_are_stored_and_do_not_follow_later_service_changes(): void
    {
        $this->signIn();
        $publicId = $this->createAdmin()->json('data.public_id');

        $this->service->update(['name' => 'Corte novo', 'price' => '99.00', 'duration_minutes' => 60]);

        $this->getJson("/api/v1/admin/appointments/{$publicId}")
            ->assertOk()
            ->assertJsonPath('data.service.name', 'Corte degradê')
            ->assertJsonPath('data.service.price', '55.90')
            ->assertJsonPath('data.service.duration_minutes', 45);
    }

    // --- Idempotency -------------------------------------------------------

    public function test_replaying_the_same_key_and_payload_returns_the_same_reservation(): void
    {
        Mail::fake();
        $this->signIn();
        $key = (string) Str::uuid();

        $first = $this->createAdmin(['customer_email' => 'cliente@example.com'], $key)->assertCreated();
        $second = $this->createAdmin(['customer_email' => 'cliente@example.com'], $key)->assertOk()->assertJsonPath('meta.replayed', true);

        $this->assertSame($first->json('data.public_id'), $second->json('data.public_id'));
        $this->assertSame(1, Appointment::query()->count());
        Mail::assertSent(AppointmentConfirmationMail::class, 1);
    }

    public function test_reusing_a_key_with_another_payload_is_refused(): void
    {
        $this->signIn();
        $key = (string) Str::uuid();

        $this->createAdmin([], $key)->assertCreated();
        $this->createAdmin(['starts_at' => $this->local('14:00')->toIso8601String()], $key)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_a_public_key_cannot_be_replayed_in_the_admin_context(): void
    {
        $this->signIn();
        $key = (string) Str::uuid();
        $this->postJson('/api/v1/public/appointments', [
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'starts_at' => $this->local('10:00')->toIso8601String(),
            'customer_name' => 'Cliente Balcão',
            'customer_email' => 'cliente@example.com',
            'customer_phone' => '+5511988887777',
        ], ['Idempotency-Key' => $key])->assertCreated();

        $this->createAdmin(['customer_email' => 'cliente@example.com', 'customer_phone' => '+5511988887777'], $key)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_the_idempotency_key_is_required(): void
    {
        $this->signIn();

        $this->postJson('/api/v1/admin/appointments', [
            'mode' => 'scheduled',
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'starts_at' => $this->local('10:00')->toIso8601String(),
            'customer_name' => 'Cliente',
        ])->assertUnprocessable()->assertJsonValidationErrors(['idempotency_key'], 'error.fields');
    }

    // --- "Atender agora" ---------------------------------------------------

    public function test_now_starts_at_the_current_minute_without_seconds(): void
    {
        Mail::fake();
        $this->signIn();
        $this->travelTo($this->local('10:07:42'));

        $response = $this->createAdmin(['mode' => 'now', 'starts_at' => null, 'customer_email' => 'cliente@example.com']);

        $response->assertCreated()
            ->assertJsonPath('data.starts_at', '2026-11-02T13:07:00+00:00')
            ->assertJsonPath('data.ends_at', '2026-11-02T13:52:00+00:00')
            // Its link would already be expired: no confirmation (seção 6).
            ->assertJsonPath('data.notification_status', 'none');
        Mail::assertNothingSent();
        $this->assertSame(0, AppointmentNotification::query()->count());
    }

    public function test_now_refuses_a_start_sent_by_the_browser(): void
    {
        $this->signIn();
        $this->travelTo($this->local('10:00'));

        $this->createAdmin(['mode' => 'now', 'starts_at' => $this->local('09:00')->toIso8601String()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at'], 'error.fields');
    }

    public function test_now_when_the_professional_is_busy_is_refused_and_not_moved(): void
    {
        $this->signIn();
        $this->appointmentAt('10:00');
        $this->travelTo($this->local('10:20'));

        $this->createAdmin(['mode' => 'now', 'starts_at' => null])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SLOT_UNAVAILABLE')
            ->assertJsonPath('error.reason', 'busy');
        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_now_when_the_professional_is_blocked_is_refused(): void
    {
        $this->signIn();
        ScheduleBlock::factory()->create([
            'professional_id' => $this->professional->id,
            'starts_at' => $this->local('10:30')->utc(),
            'ends_at' => $this->local('11:00')->utc(),
        ]);
        $this->travelTo($this->local('10:00'));

        $this->createAdmin(['mode' => 'now', 'starts_at' => null])
            ->assertStatus(409)
            ->assertJsonPath('error.reason', 'blocked');
    }

    public function test_now_that_would_end_after_the_working_period_is_refused(): void
    {
        $this->signIn();
        $this->travelTo($this->local('11:20')); // 11:20–12:05 crosses the 12:00 end

        $this->createAdmin(['mode' => 'now', 'starts_at' => null])
            ->assertStatus(409)
            ->assertJsonPath('error.reason', 'outside_working_hours');
    }

    public function test_now_right_after_an_appointment_ends_is_accepted(): void
    {
        $this->signIn();
        $this->appointmentAt('10:00'); // ends 10:45
        $this->travelTo($this->local('10:45:30'));

        $this->createAdmin(['mode' => 'now', 'starts_at' => null])
            ->assertCreated()
            ->assertJsonPath('data.starts_at', '2026-11-02T13:45:00+00:00');
    }

    public function test_now_with_a_free_professional_off_the_grid_is_accepted(): void
    {
        $this->signIn();
        $this->travelTo($this->local('14:08:05'));

        $this->createAdmin(['mode' => 'now', 'starts_at' => null])
            ->assertCreated()
            ->assertJsonPath('data.starts_at', '2026-11-02T17:08:00+00:00');
    }

    public function test_now_replayed_with_the_same_key_returns_the_first_reservation(): void
    {
        $this->signIn();
        $key = (string) Str::uuid();
        $this->travelTo($this->local('10:00:10'));
        $first = $this->createAdmin(['mode' => 'now', 'starts_at' => null], $key)->assertCreated();

        $this->travelTo($this->local('10:01:30'));
        $second = $this->createAdmin(['mode' => 'now', 'starts_at' => null], $key)->assertOk();

        $this->assertSame($first->json('data.public_id'), $second->json('data.public_id'));
        $this->assertSame('2026-11-02T13:00:00+00:00', $second->json('data.starts_at'));
    }

    /** A 08:00–09:00 period on Mondays, so 08:15 is inside working hours. */
    private function workingProfessionalEarly(): void
    {
        WorkingHour::factory()->create([
            'professional_id' => $this->professional->id,
            'weekday' => 1,
            'start_time' => '08:00',
            'end_time' => '09:00',
        ]);
    }
}
