<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Professional;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->utc()->addDays(2)->setTime(13, 0);

        return [
            'professional_id' => Professional::factory(),
            'service_id' => Service::factory(),
            'customer_name' => fake()->name(),
            'customer_email' => fake()->unique()->safeEmail(),
            'customer_phone' => '+55119'.fake()->numerify('########'),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'status' => Appointment::STATUS_CONFIRMED,
            'source' => Appointment::SOURCE_PUBLIC,
            'service_name_snapshot' => fake()->words(2, true),
            'duration_minutes_snapshot' => 30,
            'price_snapshot' => '40.00',
            'cancelled_at' => null,
            'cancelled_by' => null,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', (string) Str::uuid()),
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Appointment::STATUS_CANCELLED,
            'cancelled_at' => now()->utc(),
            'cancelled_by' => 'customer',
        ]);
    }
}
