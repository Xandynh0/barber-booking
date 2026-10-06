<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proves that two separate MySQL connections do not share an uncommitted
 * transaction: a basic connection-isolation sanity check, not a proof of
 * any specific transaction isolation level (see docs/desenvolvimento.md
 * for the actual @@SESSION.transaction_isolation value observed) and not
 * a test of the booking-conflict locking strategy described in
 * docs/planejamento-barbearia-mvp.md (section 5) — that requires two
 * concurrent writers racing for the same slot, which doesn't exist yet.
 * Must run against a real MySQL server — it is skipped under the default
 * sqlite test config.
 */
class MySqlConnectionIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires a real MySQL connection (DB_CONNECTION=mysql).');
        }

        config(['database.connections.isolation_probe_a' => config('database.connections.mysql')]);
        config(['database.connections.isolation_probe_b' => config('database.connections.mysql')]);
    }

    protected function tearDown(): void
    {
        DB::purge('isolation_probe_a');
        DB::purge('isolation_probe_b');
        Schema::dropIfExists('isolation_probe');

        parent::tearDown();
    }

    public function test_uncommitted_writes_on_one_connection_are_invisible_on_another(): void
    {
        Schema::create('isolation_probe', function ($table) {
            $table->id();
            $table->string('value');
        });

        $connectionA = DB::connection('isolation_probe_a');
        $connectionB = DB::connection('isolation_probe_b');

        $connectionA->beginTransaction();
        $connectionA->table('isolation_probe')->insert(['value' => 'uncommitted']);

        $this->assertSame(
            0,
            $connectionB->table('isolation_probe')->count(),
            'A second connection must not see an uncommitted row written by another connection.'
        );

        $connectionA->commit();

        $this->assertSame(
            1,
            $connectionB->table('isolation_probe')->count(),
            'A second connection must see the row once it has been committed.'
        );
    }
}
