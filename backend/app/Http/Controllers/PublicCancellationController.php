<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\BusinessSettings;
use App\Services\Booking\AppointmentCanceller;
use App\Services\Booking\BookingFormatter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * GET/POST /cancelar/{public_id}?expires=...&signature=... — a Laravel
 * page outside /api, as planned (docs/planejamento-barbearia-mvp.md, seção
 * 6). Both routes sit behind `signed:relative`, so they are only reachable
 * with a valid, unexpired signature for exactly this public_id; anything
 * else is answered before this controller runs, without revealing whether
 * the reservation exists.
 *
 * GET never changes state. POST (with CSRF) cancels, then redirects to the
 * same signed GET (303) so a refresh does not resubmit the form.
 */
class PublicCancellationController extends Controller
{
    public function __construct(private readonly AppointmentCanceller $canceller) {}

    public function show(Request $request, string $publicId): View
    {
        $appointment = Appointment::query()->with('professional')->where('public_id', $publicId)->firstOrFail();
        $settings = BusinessSettings::query()->firstOrFail();

        $state = match (true) {
            $request->session()->get('cancellation_result') === AppointmentCanceller::CANCELLED => 'cancelled',
            $appointment->status === Appointment::STATUS_CANCELLED => 'already_cancelled',
            ! AppointmentCanceller::canStillBeCancelled($appointment, (int) $settings->cancel_min_notice_minutes) => 'deadline_passed',
            default => 'confirm',
        };

        return view('cancellation.show', [
            'title' => __('booking.cancel.title'),
            'state' => $state,
            'formAction' => $request->getRequestUri(),
            'serviceName' => $appointment->service_name_snapshot,
            'professionalName' => $appointment->professional->name,
            'date' => BookingFormatter::date($appointment, $settings->timezone),
            'time' => BookingFormatter::time($appointment, $settings->timezone),
            'publicId' => $appointment->public_id,
            'shopName' => $settings->name ?: config('app.name'),
            'shopPhone' => $settings->phone,
        ]);
    }

    public function store(Request $request, string $publicId): RedirectResponse
    {
        $result = $this->canceller->cancelByCustomer($publicId);

        return redirect($request->getRequestUri(), 303)->with('cancellation_result', $result);
    }
}
