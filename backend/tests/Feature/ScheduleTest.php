<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * The confirmation recovery sweep as registered in routes/console.php and
 * run by the `scheduler` Compose service.
 */
class ScheduleTest extends TestCase
{
    public function test_the_confirmation_sweep_runs_every_minute_without_overlapping_on_one_server(): void
    {
        $event = $this->sweepEvent();

        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        $this->assertSame(10, $event->expiresAt);
    }

    public function test_a_mutex_left_by_a_killed_run_expires_in_minutes_not_a_day(): void
    {
        // The backend image has no pcntl, so a SIGTERM/SIGKILL'd run never
        // releases its mutex; only the expiry does. With the framework
        // default (1440 minutes) the sweep would be skipped for a whole day.
        $event = $this->sweepEvent();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', 'UTC'));

        $event->mutex->create($event);
        $this->assertTrue($event->mutex->exists($event));

        $this->travel(9)->minutes();
        $this->assertTrue($event->mutex->exists($event), 'Still protected against overlap while a run may be alive.');

        $this->travel(2)->minutes();
        $this->assertFalse($event->mutex->exists($event), 'A stale mutex must not block the recovery for long.');
    }

    private function sweepEvent(): Event
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'appointments:send-pending-confirmations'));

        $this->assertNotNull($event, 'The confirmation sweep is not scheduled.');

        return $event;
    }
}
