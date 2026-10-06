<?php

namespace Database\Factories;

use App\Models\Professional;
use App\Models\WorkingHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkingHour>
 */
class WorkingHourFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'professional_id' => Professional::factory(),
            'weekday' => fake()->numberBetween(0, 6),
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
        ];
    }
}
