<?php

namespace Database\Factories;

use App\Models\BusinessSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessSettings>
 */
class BusinessSettingsFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => null,
            'address' => null,
            'phone' => null,
            'timezone' => 'America/Sao_Paulo',
            'min_notice_minutes' => 60,
            'booking_horizon_days' => 30,
            'cancel_min_notice_minutes' => 0,
            'max_active_per_contact' => 3,
        ];
    }
}
