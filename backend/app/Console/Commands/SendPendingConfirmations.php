<?php

namespace App\Console\Commands;

use App\Models\AppointmentNotification;
use App\Services\Booking\ConfirmationNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Recovery sweep for confirmation e-mails (docs/planejamento-barbearia-
 * mvp.md, seção 5, step 6): delivers notifications left `pending` (the
 * request died between commit and send) or `failed` (provider error),
 * through the same ConfirmationNotifier as the request path. Scheduled
 * every minute in routes/console.php.
 */
#[Signature('appointments:send-pending-confirmations {--limit=50 : Maximum notifications to process in this run}')]
#[Description('Send confirmation e-mails that are still pending or failed (bounded retries).')]
class SendPendingConfirmations extends Command
{
    /** A notification touched more recently may still be in flight in a request. */
    public const LEASE_MINUTES = 2;

    public const MAX_ATTEMPTS = 5;

    public function handle(ConfirmationNotifier $notifier): int
    {
        $notifications = AppointmentNotification::query()
            ->where('kind', AppointmentNotification::KIND_CONFIRMATION)
            ->whereIn('status', [AppointmentNotification::STATUS_PENDING, AppointmentNotification::STATUS_FAILED])
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where('updated_at', '<=', now()->subMinutes(self::LEASE_MINUTES))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        $results = [];
        foreach ($notifications as $notification) {
            $status = $notifier->deliver($notification);
            $results[$status] = ($results[$status] ?? 0) + 1;
        }

        $this->info('Processed: '.$notifications->count().($results === [] ? '' : ' ('.collect($results)->map(fn ($n, $s) => "{$s}: {$n}")->implode(', ').')'));

        return self::SUCCESS;
    }
}
