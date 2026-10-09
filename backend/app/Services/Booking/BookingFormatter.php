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

    /**
     * Day, month and year without the weekday, for layouts that show the
     * weekday on its own line (cancellation page, docs/design/3.png).
     */
    public static function dayMonthYear(Appointment $appointment, string $timezone): string
    {
        $start = self::localStart($appointment, $timezone);

        return app()->getLocale() === 'en'
            ? $start->locale('en')->translatedFormat('F j, Y')
            : $start->locale('pt_BR')->translatedFormat('j \d\e F \d\e Y');
    }

    public static function weekday(Appointment $appointment, string $timezone): string
    {
        $start = self::localStart($appointment, $timezone);

        $weekday = $start->locale(app()->getLocale() === 'en' ? 'en' : 'pt_BR')->translatedFormat('l');

        return mb_strtoupper(mb_substr($weekday, 0, 1)).mb_substr($weekday, 1);
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
