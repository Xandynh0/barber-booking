<?php

namespace App\Mail;

use App\Models\Appointment;
use App\Models\BusinessSettings;
use App\Services\Booking\BookingFormatter;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Confirmation of a public reservation (docs/planejamento-barbearia-mvp.md,
 * seção 6): service, professional, date and time in the barbershop's
 * timezone, address, price and the cancellation link.
 *
 * Service name, duration and price come from the reservation's snapshots,
 * so a later edit of the service never changes what the customer is told
 * they booked. Not queued: there is no queue worker in this project yet —
 * see ConfirmationNotifier for how it is sent after commit.
 */
class AppointmentConfirmationMail extends Mailable
{
    public function __construct(
        public readonly Appointment $appointment,
        public readonly BusinessSettings $settings,
        public readonly string $cancellationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('booking.mail.subject', [
                'date' => BookingFormatter::date($this->appointment, $this->settings->timezone),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.appointment-confirmation',
            with: [
                'customerName' => $this->appointment->customer_name,
                'serviceName' => $this->appointment->service_name_snapshot,
                'durationMinutes' => $this->appointment->duration_minutes_snapshot,
                'price' => BookingFormatter::price($this->appointment),
                'professionalName' => $this->appointment->professional->name,
                'date' => BookingFormatter::date($this->appointment, $this->settings->timezone),
                'time' => BookingFormatter::time($this->appointment, $this->settings->timezone),
                'publicId' => $this->appointment->public_id,
                'shopName' => $this->settings->name ?: config('app.name'),
                'shopAddress' => $this->settings->address,
                'shopPhone' => $this->settings->phone,
                'cancellationUrl' => $this->cancellationUrl,
            ],
        );
    }
}
