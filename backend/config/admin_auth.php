<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin Login Throttling
    |--------------------------------------------------------------------------
    |
    | Two independent limits apply to POST /api/v1/admin/login, both counting
    | failed attempts: a tight one keyed by IP + normalized e-mail, and a
    | looser one keyed by IP alone. Lowered in tests (see phpunit.xml) so the
    | 429 path can be exercised without waiting a real minute.
    |
    */

    'login_throttle' => [
        'per_email' => (int) env('ADMIN_LOGIN_THROTTLE_PER_EMAIL', 5),
        'per_ip' => (int) env('ADMIN_LOGIN_THROTTLE_PER_IP', 20),
    ],

];
