<?php

namespace App\Services\Availability;

/**
 * Who is asking for availability. Chosen by the route that serves the
 * request (public vs. authenticated admin), never by a request parameter —
 * otherwise a public caller could ask for the admin rules and skip the
 * minimum notice.
 */
enum AvailabilityContext: string
{
    /** Respects min_notice_minutes and booking_horizon_days. */
    case Public = 'public';

    /** Ignores min_notice_minutes; still bound by booking_horizon_days and never offered past instants. */
    case Admin = 'admin';
}
