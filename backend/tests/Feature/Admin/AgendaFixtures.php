<?php

namespace Tests\Feature\Admin;

use App\Models\Appointment;
use App\Models\BusinessSettings;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Shared data for the admin agenda tests: a barbershop in São Paulo, "now"
 * frozen on Monday 2026-11-02 08:00 local, a 45-minute service and a
 * professional working 09:00–12:00 and 13:00–18:00 on Mondays.
 */
trait AgendaFixtures
{
    protected const TZ = 'America/Sao_Paulo';

    protected const DAY = '2026-11-02';

    protected Service $service;

    protected Professional $professional;

    protected function setUpAgenda(string $now = '2026-11-02 08:00'): void
    {
        $this->travelTo(CarbonImmutable::parse($now, self::TZ));

        BusinessSettings::factory()->create([
            'timezone' => self::TZ,
            'min_notice_minutes' => 60,
            'booking_horizon_days' => 30,
            'max_active_per_contact' => 3,
        ]);

        $this->service = Service::factory()->create(['name' => 'Corte degradê', 'duration_minutes' => 45, 'price' => '55.90']);
        $this->professional = $this->workingProfessional('Rafael Almeida');
    }

    protected function workingProfessional(string $name, array $periods = [['09:00', '12:00'], ['13:00', '18:00']]): Professional
    {
        $professional = Professional::factory()->create(['name' => $name]);
        $professional->services()->attach($this->service);

        foreach ($periods as [$start, $end]) {
            WorkingHour::factory()->create([
                'professional_id' => $professional->id,
                'weekday' => 1,
                'start_time' => $start,
                'end_time' => $end,
            ]);
        }

        return $professional;
    }

    protected function signIn(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    protected function local(string $time, string $date = self::DAY): CarbonImmutable
    {
        return CarbonImmutable::parse("{$date} {$time}", self::TZ);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function appointmentAt(string $time, array $attributes = [], ?Professional $professional = null, string $date = self::DAY): Appointment
    {
        $start = $this->local($time, $date)->utc();

        return Appointment::factory()->create([
            'professional_id' => ($professional ?? $this->professional)->id,
            'service_id' => $this->service->id,
            'customer_name' => 'Cliente Sigiloso',
            'customer_email' => 'sigilo@example.com',
            'customer_phone' => '+5511912345678',
            'service_name_snapshot' => 'Corte degradê',
            'duration_minutes_snapshot' => 45,
            'price_snapshot' => '55.90',
            'starts_at' => $start,
            'ends_at' => $start->addMinutes(45),
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createAdmin(array $overrides = [], ?string $key = null): TestResponse
    {
        return $this->postJson('/api/v1/admin/appointments', [
            'mode' => 'scheduled',
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'starts_at' => $this->local('10:00')->toIso8601String(),
            'customer_name' => 'Cliente Balcão',
            ...$overrides,
        ], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }
}
