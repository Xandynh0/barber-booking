<?php

/*
|--------------------------------------------------------------------------
| API error messages
|--------------------------------------------------------------------------
|
| `error.code` (e.g. "NOT_FOUND") is the stable contract the frontend
| switches on — it never changes with language. Only `error.message`
| (the text here) is translated, for humans to read.
|
*/

return [
    'unauthenticated' => 'Authentication required.',
    'invalid_credentials' => 'Invalid e-mail or password.',
    'validation_error' => 'Invalid data.',
    'not_found' => 'Record not found.',
    'session_expired' => 'Session expired. Please sign in again.',
    'rate_limited' => 'Too many attempts. Please try again shortly.',
    'service_ids_missing' => 'One or more of the given services do not exist.',
    'professional_not_found' => 'The given professional does not exist.',
    'period_end_before_start' => 'The end must be after the start.',
    'periods_overlap' => 'Periods on the same day cannot overlap.',
    'service_unavailable' => 'Service unavailable right now.',
    'availability_service_unavailable' => 'Service unavailable for booking.',
    'availability_professional_unavailable' => 'Professional unavailable for booking.',
    'availability_service_not_offered' => 'This professional does not offer the selected service.',
    'slot_unavailable' => 'This time is no longer available. Please choose another one.',
    'contact_limit_reached' => 'There is a limit of upcoming bookings per contact. To book more, please contact the barbershop.',
    'idempotency_key_reused' => 'This idempotency key was already used for another booking. Generate a new key for a new booking.',
    'invalid_phone' => 'Enter a valid phone number with area code (e.g. +55 11 99999-9999).',
    'starts_at_needs_offset' => 'Enter the start with date, time and timezone offset (ISO 8601, e.g. 2026-11-03T10:00:00-03:00).',
    'appointment_conflict_block' => 'There are confirmed bookings in this interval: :list. Cancel them before blocking this time.',
    'appointment_conflict_working_hours' => 'The new schedule would leave upcoming bookings outside working hours: :list. Cancel them before reducing the schedule.',
];
