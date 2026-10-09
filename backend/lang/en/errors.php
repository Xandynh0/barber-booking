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
];
