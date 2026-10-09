<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Recovery sweep for confirmation e-mails (see SendPendingConfirmations).
// Runs in the `scheduler` Compose service (`php artisan schedule:work`).
//
// - withoutOverlapping(10): never two runs at once. The mutex lives in the
//   cache (CACHE_STORE=database) and normally is released at the end of the
//   run or on SIGTERM; 10 minutes (not the 1440-minute default) bounds how
//   long a killed run (SIGKILL, OOM, host crash) can block the recovery.
//   A normal run takes seconds; with a hanging SMTP server one could outlast
//   the 10 minutes, and the next run would overlap it — still without
//   double sends, because ConfirmationNotifier claims each notification
//   with a conditional UPDATE before sending.
// - onOneServer(): if a second scheduler is ever started by accident, only
//   one of them runs the sweep each minute.
Schedule::command('appointments:send-pending-confirmations')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer();
