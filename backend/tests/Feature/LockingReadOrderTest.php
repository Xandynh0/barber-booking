<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Why every agenda write takes the business_settings lock as its very first
 * statement (docs/desenvolvimento.md, "Fluxo dentro da transação").
 *
 * Under InnoDB's REPEATABLE READ, a transaction's read view is created by
 * its first consistent (non-locking) read, not by BEGIN. With two
 * independent MySQL connections and two scratch tables — a one-row
 * "settings" lock and a "bookings" table — these tests show:
 *
 * - forbidden order: a plain read before the lock pins an old view; the
 *   writer that got the lock first commits a booking, and after obtaining
 *   the same lock the first transaction still does not see it with plain
 *   reads — it would book the same slot twice;
 * - required order: lock first, read after — the view is created after the
 *   lock is granted and contains the other booking.
 *
 * The scratch tables keep this independent of the application schema; the
 * real booking path is raced end to end by PublicAppointmentConcurrencyTest
 * and AdminAppointmentConcurrencyTest.
 */
class LockingReadOrderTest extends TestCase
{
    private Connection $first;

    private Connection $second;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.order_probe_a' => config('database.connections.mysql')]);
        config(['database.connections.order_probe_b' => config('database.connections.mysql')]);

        Schema::dropIfExists('order_probe_bookings');
        Schema::dropIfExists('order_probe_settings');
        Schema::create('order_probe_settings', function ($table) {
            $table->id();
        });
        Schema::create('order_probe_bookings', function ($table) {
            $table->id();
            $table->string('slot');
        });
        DB::table('order_probe_settings')->insert(['id' => 1]);

        $this->first = DB::connection('order_probe_a');
        $this->second = DB::connection('order_probe_b');
    }

    protected function tearDown(): void
    {
        foreach (['order_probe_a', 'order_probe_b'] as $name) {
            if (DB::connection($name)->transactionLevel() > 0) {
                DB::connection($name)->rollBack();
            }
            DB::purge($name);
        }
        Schema::dropIfExists('order_probe_bookings');
        Schema::dropIfExists('order_probe_settings');

        parent::tearDown();
    }

    public function test_both_connections_run_repeatable_read(): void
    {
        foreach ([$this->first, $this->second] as $connection) {
            $this->assertSame('REPEATABLE-READ', $connection->selectOne('select @@SESSION.transaction_isolation as level')->level);
            $this->assertNotSame(
                $this->first->selectOne('select connection_id() as id')->id,
                $this->second->selectOne('select connection_id() as id')->id,
            );
        }
    }

    public function test_a_plain_read_before_the_lock_keeps_a_stale_view_of_the_agenda(): void
    {
        // Forbidden order: read, then lock.
        $this->first->beginTransaction();
        $this->assertSame(0, $this->bookingsFor($this->first, '10:00'));

        // Meanwhile the other request takes the lock, books 10:00, commits.
        $this->second->beginTransaction();
        $this->second->select('select id from order_probe_settings where id = 1 for update');
        $this->second->table('order_probe_bookings')->insert(['slot' => '10:00']);
        $this->second->commit();

        // The first one now obtains the same lock...
        $this->first->select('select id from order_probe_settings where id = 1 for update');

        // ...and its plain reads still answer from the old view: the slot
        // looks free, so it would insert a second booking for it.
        $this->assertSame(0, $this->bookingsFor($this->first, '10:00'), 'The stale view must hide the committed booking.');
        // A locking read in the same transaction sees the truth — the two
        // kinds of read disagree inside one transaction, which is exactly
        // the trap the lock-first rule avoids.
        $this->assertSame(1, (int) $this->first->selectOne("select count(*) as n from order_probe_bookings where slot = '10:00' for update")->n);

        $this->first->rollBack();
    }

    public function test_locking_first_makes_every_later_read_see_the_committed_booking(): void
    {
        // Required order: BEGIN creates no read view yet.
        $this->first->beginTransaction();

        $this->second->beginTransaction();
        $this->second->select('select id from order_probe_settings where id = 1 for update');
        $this->second->table('order_probe_bookings')->insert(['slot' => '10:00']);
        $this->second->commit();

        $this->first->select('select id from order_probe_settings where id = 1 for update');

        $this->assertSame(1, $this->bookingsFor($this->first, '10:00'), 'After locking first, the plain read must see the committed booking.');

        $this->first->rollBack();
    }

    private function bookingsFor(Connection $connection, string $slot): int
    {
        return (int) $connection->selectOne('select count(*) as n from order_probe_bookings where slot = ?', [$slot])->n;
    }
}
