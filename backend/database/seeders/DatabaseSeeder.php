<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * No default admin is seeded here — this MVP has no public signup, and a
     * seeded user would mean a predictable production credential. Create the
     * first admin with `php artisan admin:create` instead.
     */
    public function run(): void
    {
        //
    }
}
