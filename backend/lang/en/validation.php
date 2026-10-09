<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Only the 'attributes' overrides are actually needed here — Laravel's
    | own internal English defaults (vendor/laravel/framework/.../lang/en/
    | validation.php) already cover every rule message and are merged
    | underneath this file. This file exists to give the dotted wildcard
    | paths (e.g. "days.*.periods.*.start_time") a readable name instead of
    | the literal path with asterisks.
    |
    */

    'attributes' => [
        'service_ids.*' => 'service',
        'idempotency_key' => 'idempotency key',
        'customer_name' => 'name',
        'customer_email' => 'email',
        'customer_phone' => 'phone',
        'days.*.weekday' => 'weekday',
        'days.*.periods' => 'periods',
        'days.*.periods.*.start_time' => 'start time',
        'days.*.periods.*.end_time' => 'end time',
    ],

];
