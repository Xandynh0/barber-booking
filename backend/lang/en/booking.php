<?php

/*
|--------------------------------------------------------------------------
| Confirmation e-mail and cancellation pages
|--------------------------------------------------------------------------
*/

return [
    'fields' => [
        'service' => 'Service',
        'professional' => 'Professional',
        'date' => 'Date',
        'time' => 'Time',
        'duration' => 'Duration',
        'price' => 'Price',
        'address' => 'Address',
        'reference' => 'Booking reference',
    ],
    'duration' => ':minutes min',

    'mail' => [
        'subject' => 'Booking confirmed — :date',
        'heading' => 'Booking confirmed',
        'greeting' => 'Hello, :name!',
        'intro' => 'Your appointment at :shop is confirmed. Here are the details:',
        'cancel_intro' => 'If you cannot make it, cancel with the button below. Opening the link only shows the booking; it is cancelled when you confirm on that page.',
        'cancel_button' => 'Cancel booking',
        'cancel_note' => 'This link is personal and valid until the appointment starts. Do not share it.',
        'contact' => 'Questions? Contact the barbershop: :phone.',
        'signature' => 'See you soon,',
    ],

    'cancel' => [
        'title' => 'Cancel booking',
        'question' => 'Do you want to cancel this booking? This cannot be undone.',
        'button' => 'Confirm cancellation',
        'done' => 'Booking cancelled. The time slot is free again.',
        'already' => 'This booking is already cancelled.',
        'deadline_passed' => 'The deadline to cancel this booking online has passed. If needed, please contact the barbershop.',
        'error_title' => 'Link unavailable',
        'invalid_link' => 'This cancellation link is invalid or has expired. If you need help, please contact the barbershop.',
        'form_expired' => 'This page was open for too long. Open the link from the e-mail again to cancel.',
        'too_many_attempts' => 'Too many attempts in a short time. Please wait a moment and try again.',
    ],
];
