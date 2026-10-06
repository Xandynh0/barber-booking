<?php

namespace Database\Seeders;

use App\Models\BusinessSettings;
use Illuminate\Database\Seeder;

/**
 * Creates the single business_settings row with the defaults from
 * docs/planejamento-barbearia-mvp.md if no row exists yet. Safe to run any
 * number of times — firstOrCreate() never overwrites an existing row, so
 * re-running `php artisan db:seed` never resets configuration an admin may
 * have changed by hand in the database.
 */
class BusinessSettingsSeeder extends Seeder
{
    public function run(): void
    {
        // Empty search criteria on purpose: this is a true singleton, so
        // "does a row exist at all" is the right check — not "does a row
        // with id=1 exist". The id column isn't fillable (on purpose; it's
        // not meant to be set by callers), and MySQL's auto_increment
        // counter isn't rolled back by a test transaction, so a row created
        // here won't reliably land on id=1 across repeated runs.
        BusinessSettings::query()->firstOrCreate([], [
            'timezone' => 'America/Sao_Paulo',
            'min_notice_minutes' => 60,
            'booking_horizon_days' => 30,
            'cancel_min_notice_minutes' => 0,
            'max_active_per_contact' => 3,
        ]);
    }
}
