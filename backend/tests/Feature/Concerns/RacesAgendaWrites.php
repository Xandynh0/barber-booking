<?php

namespace Tests\Feature\Concerns;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Races two real HTTP requests against each other, each in its own PHP
 * process with its own MySQL connection (tests/Support/*.php boot the real
 * application and go through the HTTP kernel).
 *
 * To make the race deterministic, a third "gate" connection holds the
 * business_settings row lock first; the test waits until MySQL reports
 * both attempts blocked on that same lock, then releases it so they compete
 * for it. The using test must commit its data (DatabaseTruncation), define
 * the `lock_gate` connection and purge it on tearDown.
 */
trait RacesAgendaWrites
{
    private const WAIT_FOR_BLOCKED_SECONDS = 30;

    /**
     * @param  array<int, string>  $first  Arguments for the first attempt's script.
     * @param  array<int, string>  $second  Arguments for the second attempt's script.
     * @return array{0: array{status: int, body: array<string, mixed>}, 1: array{status: int, body: array<string, mixed>}}
     */
    private function raceScripts(string $script, array $first, array $second): array
    {
        /** @var Connection $gate */
        $gate = DB::connection('lock_gate');
        $gateId = (int) $gate->selectOne('select connection_id() as id')->id;
        $gate->beginTransaction();
        $gate->select('select id from business_settings for update');

        $processes = [$this->attemptProcess($script, $first), $this->attemptProcess($script, $second)];
        foreach ($processes as $process) {
            $process->start();
        }

        try {
            $this->waitUntilBlockedOnTheLock(2, $gateId, $processes);
        } finally {
            $gate->commit();
        }

        return array_map(function (Process $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), 'Attempt process failed: '.$process->getErrorOutput().$process->getOutput());

            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }, $processes);
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private function attemptProcess(string $script, array $arguments): Process
    {
        $mysql = config('database.connections.mysql');

        return new Process(
            [PHP_BINARY, base_path("tests/Support/{$script}"), ...$arguments],
            base_path(),
            [
                // Real environment variables win over .env (and over anything
                // phpunit.xml sets), so the child is pinned to this test's
                // database and to side-effect-free drivers.
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => (string) $mysql['host'],
                'DB_PORT' => (string) $mysql['port'],
                'DB_TEST_DATABASE' => (string) $mysql['database'],
                'DB_USERNAME' => (string) $mysql['username'],
                'DB_PASSWORD' => (string) $mysql['password'],
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'MAIL_MAILER' => 'array',
                'LOG_CHANNEL' => 'stderr',
            ],
            timeout: 120,
        );
    }

    /**
     * @param  array<int, Process>  $processes
     */
    private function waitUntilBlockedOnTheLock(int $expected, int $gateId, array $processes): void
    {
        $deadline = microtime(true) + self::WAIT_FOR_BLOCKED_SECONDS;

        do {
            $blocked = (int) DB::selectOne(
                "select count(*) as total from information_schema.processlist
                 where db = database() and id not in (connection_id(), ?)
                 and info like 'select % from `business_settings` % for update'",
                [$gateId],
            )->total;

            if ($blocked >= $expected) {
                return;
            }

            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    $this->fail('An attempt finished before reaching the lock: '.$process->getErrorOutput().$process->getOutput());
                }
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        $this->fail("Expected {$expected} attempts blocked on the business_settings lock, saw {$blocked}.");
    }
}
