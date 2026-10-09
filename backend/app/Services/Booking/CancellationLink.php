<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

/**
 * The customer's cancellation link (docs/planejamento-barbearia-mvp.md,
 * seção 6): `/cancelar/{public_id}?expires=...&signature=...`, a Laravel
 * temporary signed route that expires at the reservation's start. Nothing
 * about it is stored — it is signed again at every send, with the
 * application key.
 *
 * The signature is *relative* (path + query only) and the host comes from
 * APP_URL. Behind the proxy the backend receives `Host: localhost` without
 * the public port, so an absolute signature would never validate; signing
 * the path still binds the link to this public_id and expiry — changing
 * either invalidates it. The routes validate with `signed:relative`.
 */
class CancellationLink
{
    public const SHOW_ROUTE = 'appointments.cancel.show';

    public static function for(Appointment $appointment): string
    {
        $path = URL::temporarySignedRoute(
            self::SHOW_ROUTE,
            CarbonImmutable::instance($appointment->starts_at),
            ['publicId' => $appointment->public_id],
            absolute: false,
        );

        return rtrim((string) config('app.url'), '/').$path;
    }
}
