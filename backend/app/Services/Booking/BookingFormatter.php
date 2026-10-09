<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use Carbon\CarbonImmutable;

/**
 * How a reservation is shown to the customer (e-mail and cancellation
 * page): always in the barbershop's timezone, in the current locale.
 */
class BookingFormatter
{
    public static function date(Appointment $appointment, string $timezone): string
    {
        $start = self::localStart($appointment, $timezone);

        return app()->getLocale() === 'en'
            ? $start->locale('en')->translatedFormat('l, F j, Y')
            : $start->locale('pt_BR')->translatedFormat('l, d \d\e F \d\e Y');
    }

    public static function time(Appointment $appointment, string $timezone): string
    {
        return self::localStart($appointment, $timezone)->format('H:i')
            .'–'
            .CarbonImmutable::instance($appointment->ends_at)->setTimezone($timezone)->format('H:i');
    }

    /**
     * From the price snapshot, so the amount shown is what was contracted.
     */
    public static function price(Appointment $appointment): string
    {
        $amount = (float) $appointment->price_snapshot;

        return app()->getLocale() === 'en'
            ? 'R$'.number_format($amount, 2, '.', ',')
            : 'R$ '.number_format($amount, 2, ',', '.');
    }

    private static function localStart(Appointment $appointment, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::instance($appointment->starts_at)->setTimezone($timezone);
    }
}
