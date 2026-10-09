<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Recovery sweep for confirmation e-mails (see SendPendingConfirmations).
// Needs the scheduler running: `php artisan schedule:work` locally.
Schedule::command('appointments:send-pending-confirmations')->everyMinute()->withoutOverlapping();
