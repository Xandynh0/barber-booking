<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BusinessSettings;
use App\Models\Professional;
use App\Models\Service;
use App\Models\WorkingHour;
use App\Services\Booking\CancellationLink;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GET/POST /cancelar/{public_id}?expires=...&signature=...
 * (docs/planejamento-barbearia-mvp.md, seção 6). "Now" is Monday
 * 2026-11-02 08:00 in São Paulo; the reservation is Tuesday 2026-11-03
 * 10:00–10:45 local (13:00–13:45 UTC), so the link expires at 13:00 UTC.
 */
class PublicCancellationTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Sao_Paulo';

    private Appointment $appointment;

    private Service $service;

    private Professional $professional;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', self::TZ));

        BusinessSettings::factory()->create(['timezone' => self::TZ]);
        $this->service = Service::factory()->create(['name' => 'Corte atual', 'duration_minutes' => 45]);
        $this->professional = Professional::factory()->create(['name' => 'Rafael Almeida']);
        $this->professional->services()->attach($this->service);
        WorkingHour::factory()->create(['professional_id' => $this->professional->id, 'weekday' => 2, 'start_time' => '09:00', 'end_time' => '12:00']);

        $this->appointment = $this->makeAppointment('10:00');
    }

    // --- GET ---------------------------------------------------------------

    public function test_opening_the_link_shows_a_minimal_summary_and_changes_nothing(): void
    {
        $response = $this->get($this->linkFor($this->appointment));

        $response->assertOk()
            ->assertSee('Cancelar reserva')
            ->assertSee('Corte degradê')          // snapshot, not the current service name
            ->assertDontSee('Corte atual')
            ->assertSee('Rafael Almeida')
            ->assertSee('terça-feira, 03 de novembro de 2026')
            ->assertSee('10:00–10:45')
            ->assertSee($this->appointment->public_id)
            ->assertSee('Confirmar cancelamento')
            ->assertDontSee('Cliente Sigiloso')
            ->assertDontSee('sigilo@example.com')
            ->assertDontSee('+5511912345678');

        $this->assertSame(Appointment::STATUS_CONFIRMED, $this->appointment->fresh()->status);
    }

    public function test_the_page_is_sent_with_privacy_headers(): void
    {
        $response = $this->get($this->linkFor($this->appointment))->assertOk();

        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('noindex', $response->headers->get('X-Robots-Tag'));
        // No script may run on a page whose URL carries a signature: the CSP
        // has no script-src (default-src 'none'), and our own templates ship
        // none. (Laravel Boost, a dev-only package, injects one into every
        // HTML page when APP_ENV=local — the CSP blocks it from running.)
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringNotContainsString('script-src', $csp);
        foreach (['layout', 'show', 'error'] as $template) {
            $this->assertStringNotContainsString('<script', file_get_contents(resource_path("views/cancellation/{$template}.blade.php")));
        }
    }

    public function test_the_page_follows_the_browser_language(): void
    {
        $this->withHeaders(['Accept-Language' => 'en'])
            ->get($this->linkFor($this->appointment))
            ->assertOk()
            ->assertSee('Cancel booking')
            ->assertSee('Tuesday, November 3, 2026')
            ->assertSee('Confirm cancellation');
    }

    // --- POST ------------------------------------------------------------

    public function test_confirming_cancels_the_reservation_without_deleting_it(): void
    {
        $link = $this->linkFor($this->appointment);

        $this->cancel($link)->assertStatus(303)->assertRedirect($link)->assertSessionHas('cancellation_result', 'cancelled');

        $cancelled = $this->appointment->fresh();
        $this->assertSame(Appointment::STATUS_CANCELLED, $cancelled->status);
        $this->assertSame('customer', $cancelled->cancelled_by);
        $this->assertSame('2026-11-02 11:00:00', $cancelled->cancelled_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('appointments', 1);

        $this->withSession(['cancellation_result' => 'cancelled'])->get($link)
            ->assertOk()
            ->assertSee('Reserva cancelada. O horário foi liberado.')
            ->assertDontSee('Confirmar cancelamento');
    }

    public function test_cancelling_keeps_snapshots_and_identity_and_creates_nothing(): void
    {
        $before = $this->appointment->fresh()->only([
            'public_id', 'professional_id', 'service_id', 'customer_name', 'customer_email', 'customer_phone',
            'source', 'service_name_snapshot', 'duration_minutes_snapshot', 'price_snapshot', 'idempotency_key', 'request_fingerprint',
        ]);

        $this->cancel($this->linkFor($this->appointment))->assertStatus(303);

        $after = $this->appointment->fresh();
        $this->assertSame($before, $after->only(array_keys($before)));
        $this->assertSame($this->appointment->starts_at->toIso8601String(), $after->starts_at->toIso8601String());
        $this->assertSame($this->appointment->ends_at->toIso8601String(), $after->ends_at->toIso8601String());
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_the_cancel_form_rejects_a_missing_csrf_token_and_accepts_a_valid_one(): void
    {
        // Laravel skips CSRF verification whenever runningUnitTests() is
        // true (APP_ENV=testing, now really in effect in Docker too). Same
        // explicit override as AuthTest, scoped to this one test, so the
        // middleware actually runs.
        $this->app->instance('env', 'production');
        $link = $this->linkFor($this->appointment);

        $this->withSession(['_token' => 'test-csrf-token'])->post($link)
            ->assertStatus(419)
            ->assertSee('Abra o link do e-mail novamente para cancelar.');
        $this->assertSame(Appointment::STATUS_CONFIRMED, $this->appointment->fresh()->status);

        $this->withSession(['_token' => 'test-csrf-token'])->post($link, ['_token' => 'test-csrf-token'])
            ->assertStatus(303);
        $this->assertSame(Appointment::STATUS_CANCELLED, $this->appointment->fresh()->status);
    }

    public function test_cancelling_twice_is_harmless(): void
    {
        $link = $this->linkFor($this->appointment);
        $this->cancel($link)->assertStatus(303);
        $firstCancelledAt = $this->appointment->fresh()->cancelled_at->toIso8601String();

        $this->travel(10)->minutes();
        $this->cancel($link)->assertStatus(303)->assertSessionHas('cancellation_result', 'already_cancelled');

        $this->assertSame($firstCancelledAt, $this->appointment->fresh()->cancelled_at->toIso8601String());
        $this->get($link)->assertOk()->assertSee('Esta reserva já está cancelada.');
    }

    public function test_fields_sent_in_the_form_cannot_change_anything_but_the_cancellation(): void
    {
        $this->cancel($this->linkFor($this->appointment), [
            'status' => 'confirmed',
            'source' => 'admin',
            'starts_at' => '2026-11-03T11:00:00-03:00',
            'price_snapshot' => '0.00',
            'id' => 999,
        ])->assertStatus(303);

        $after = $this->appointment->fresh();
        $this->assertSame(Appointment::STATUS_CANCELLED, $after->status);
        $this->assertSame(Appointment::SOURCE_PUBLIC, $after->source);
        $this->assertSame('40.00', $after->price_snapshot);
        $this->assertSame($this->appointment->id, $after->id);
    }

    public function test_a_cancelled_reservation_gives_the_slot_back_to_availability(): void
    {
        $slotStart = '2026-11-03T13:00:00+00:00';
        $this->assertNotContains($slotStart, $this->availableStarts());

        $this->cancel($this->linkFor($this->appointment))->assertStatus(303);

        $this->assertContains($slotStart, $this->availableStarts());
    }

    // --- Invalid links ---------------------------------------------------

    public function test_an_altered_signature_is_rejected_without_revealing_the_reservation(): void
    {
        $tampered = preg_replace('/signature=([0-9a-f])/', 'signature=0$1', $this->linkFor($this->appointment));

        foreach (['get', 'post'] as $method) {
            $response = $method === 'get' ? $this->get($tampered) : $this->cancel($tampered);
            $response->assertForbidden()
                ->assertSee('Este link de cancelamento é inválido ou expirou.')
                ->assertDontSee('Rafael Almeida')
                ->assertDontSee($this->appointment->public_id);
            $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        }

        $this->assertSame(Appointment::STATUS_CONFIRMED, $this->appointment->fresh()->status);
    }

    public function test_a_link_without_signature_or_with_an_altered_expiry_is_rejected(): void
    {
        $this->get('/cancelar/'.$this->appointment->public_id)->assertForbidden();
        $this->cancel('/cancelar/'.$this->appointment->public_id)->assertForbidden();

        $extended = preg_replace('/expires=\d+/', 'expires='.now()->addYear()->getTimestamp(), $this->linkFor($this->appointment));
        $this->get($extended)->assertForbidden();
        $this->cancel($extended)->assertForbidden();

        $this->assertSame(Appointment::STATUS_CONFIRMED, $this->appointment->fresh()->status);
    }

    public function test_another_reservations_id_with_this_signature_is_rejected(): void
    {
        $other = $this->makeAppointment('11:00');
        $swapped = str_replace($this->appointment->public_id, $other->public_id, $this->linkFor($this->appointment));

        $this->get($swapped)->assertForbidden();
        $this->cancel($swapped)->assertForbidden();

        $this->assertSame(Appointment::STATUS_CONFIRMED, $other->fresh()->status);
        $this->assertSame(Appointment::STATUS_CONFIRMED, $this->appointment->fresh()->status);
    }

    public function test_a_validly_signed_link_for_an_unknown_reservation_gets_the_same_generic_page(): void
    {
        $unknown = URL::temporarySignedRoute(CancellationLink::SHOW_ROUTE, now()->addDay(), ['publicId' => strtolower((string) Str::ulid())], absolute: false);

        $this->get($unknown)->assertNotFound()->assertSee('Este link de cancelamento é inválido ou expirou.');
        $this->cancel($unknown)->assertNotFound();
        $this->assertDatabaseCount('appointments', 1);
    }

    // --- Expiry and deadline ------------------------------------------------

    public function test_the_link_works_until_the_start_and_expires_at_it(): void
    {
        $link = $this->linkFor($this->appointment);

        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:59:59', self::TZ));
        $this->get($link)->assertOk()->assertSee('Confirmar cancelamento');

        $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00:01', self::TZ));
        $this->get($link)->assertForbidden()->assertSee('Este link de cancelamento é inválido ou expirou.');
        $this->cancel($link)->assertForbidden();

        $this->assertSame(Appointment::STATUS_CONFIRMED, $this->appointment->fresh()->status);
    }

    public function test_a_cancellation_notice_closes_online_cancellation_before_the_start(): void
    {
        BusinessSettings::query()->update(['cancel_min_notice_minutes' => 120]);
        $link = $this->linkFor($this->appointment);

        // 09:00 is less than 120 minutes before 10:00; the link is still
        // valid (it expires at the start), so the page can explain why.
        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00', self::TZ));
        $this->get($link)->assertOk()
            ->assertSee('O prazo para cancelar esta reserva pelo link já terminou.')
            ->assertDontSee('Confirmar cancelamento');
        $this->cancel($link)->assertStatus(303)->assertSessionHas('cancellation_result', 'deadline_passed');
        $this->assertSame(Appointment::STATUS_CONFIRMED, $this->appointment->fresh()->status);

        // Exactly 120 minutes before is already too late (now < start − notice).
        $this->travelTo(CarbonImmutable::parse('2026-11-03 08:00', self::TZ));
        $this->cancel($link)->assertSessionHas('cancellation_result', 'deadline_passed');

        $this->travelTo(CarbonImmutable::parse('2026-11-03 07:59:59', self::TZ));
        $this->cancel($link)->assertSessionHas('cancellation_result', 'cancelled');
        $this->assertSame(Appointment::STATUS_CANCELLED, $this->appointment->fresh()->status);
    }

    public function test_the_cancellation_pages_are_rate_limited(): void
    {
        $link = $this->linkFor($this->appointment);

        for ($i = 0; $i < 30; $i++) {
            $this->get($link)->assertOk();
        }

        $this->get($link)->assertStatus(429)->assertSee('Muitas tentativas em pouco tempo.');
    }

    // --- Helpers --------------------------------------------------------

    private function makeAppointment(string $localTime): Appointment
    {
        $startsAt = CarbonImmutable::parse("2026-11-03 {$localTime}", self::TZ)->utc();

        return Appointment::factory()->create([
            'professional_id' => $this->professional->id,
            'service_id' => $this->service->id,
            'customer_name' => 'Cliente Sigiloso',
            'customer_email' => 'sigilo@example.com',
            'customer_phone' => '+5511912345678',
            'service_name_snapshot' => 'Corte degradê',
            'duration_minutes_snapshot' => 45,
            'price_snapshot' => '40.00',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(45),
        ]);
    }

    /**
     * The signed path and query, as the e-mail link carries them.
     */
    private function linkFor(Appointment $appointment): string
    {
        $url = parse_url(CancellationLink::for($appointment));

        return $url['path'].'?'.$url['query'];
    }

    /**
     * The CSRF token is part of the real form; the session carries the
     * matching token, as after opening the page.
     *
     * @param  array<string, mixed>  $extra
     */
    private function cancel(string $link, array $extra = []): TestResponse
    {
        return $this->withSession(['_token' => 'test-csrf-token'])
            ->post($link, array_merge(['_token' => 'test-csrf-token'], $extra));
    }

    /**
     * @return array<int, string>
     */
    private function availableStarts(): array
    {
        return array_column($this->getJson('/api/v1/public/availability?'.http_build_query([
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'date' => '2026-11-03',
        ]))->assertOk()->json('data.slots'), 'starts_at');
    }
}
