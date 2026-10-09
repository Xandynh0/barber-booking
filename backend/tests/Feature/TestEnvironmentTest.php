<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the configuration the whole suite depends on, checked inside the
 * test process itself — the place where it once silently diverged: inside
 * Docker the suite ran as APP_ENV=local, sent real mail to Mailpit and used
 * the development cache and throttle limits, because docker-compose's
 * env_file variables (in $_SERVER) beat phpunit.xml's <env force>. See the
 * comment in phpunit.xml and docs/desenvolvimento.md, "Ambiente de testes".
 */
class TestEnvironmentTest extends TestCase
{
    public function test_the_suite_runs_in_the_testing_environment(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertTrue(app()->runningUnitTests());
    }

    public function test_the_suite_uses_only_the_isolated_mysql_test_database(): void
    {
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('barber_booking_test', config('database.connections.mysql.database'));
        $this->assertSame('barber_booking_test', DB::connection()->getDatabaseName());
        $this->assertSame('barber_booking_test', DB::selectOne('select database() as name')->name);
    }

    public function test_side_effecting_drivers_are_the_in_memory_ones(): void
    {
        $this->assertSame('array', config('mail.default'), 'No message from the suite may reach Mailpit.');
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('sync', config('queue.default'));
        // env() turns the string "null" into a real null.
        $this->assertContains(config('broadcasting.default'), [null, 'null']);
        $this->assertSame('database', config('session.driver'));
    }

    public function test_the_low_rate_limits_meant_for_tests_are_active(): void
    {
        $this->assertSame(2, (int) config('admin_auth.login_throttle.per_email'));
        $this->assertSame(3, (int) config('admin_auth.login_throttle.per_ip'));
    }
}
