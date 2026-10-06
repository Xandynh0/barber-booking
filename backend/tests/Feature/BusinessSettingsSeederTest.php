<?php

namespace Tests\Feature;

use App\Models\BusinessSettings;
use Database\Seeders\BusinessSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessSettingsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_the_singleton_row_with_documented_defaults(): void
    {
        (new BusinessSettingsSeeder)->run();

        $this->assertDatabaseCount('business_settings', 1);
        $settings = BusinessSettings::first();

        $this->assertSame('America/Sao_Paulo', $settings->timezone);
        $this->assertSame(60, $settings->min_notice_minutes);
        $this->assertSame(30, $settings->booking_horizon_days);
        $this->assertSame(0, $settings->cancel_min_notice_minutes);
        $this->assertSame(3, $settings->max_active_per_contact);
    }

    public function test_seeder_is_idempotent_and_never_overwrites_existing_values(): void
    {
        (new BusinessSettingsSeeder)->run();

        BusinessSettings::query()->first()->update([
            'name' => 'Barbearia do Zé',
            'max_active_per_contact' => 7,
        ]);

        (new BusinessSettingsSeeder)->run();

        $this->assertDatabaseCount('business_settings', 1);
        $settings = BusinessSettings::first();
        $this->assertSame('Barbearia do Zé', $settings->name);
        $this->assertSame(7, $settings->max_active_per_contact);
    }
}
