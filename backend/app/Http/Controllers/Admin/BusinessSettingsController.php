<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessSettings;
use Illuminate\Http\JsonResponse;

/**
 * Read-only for now — no edit screen/endpoint exists yet (see
 * docs/planejamento-barbearia-mvp.md section 13). Added in this entrega
 * because the frontend needs the barbershop's timezone to display and
 * submit schedule-block instants without relying on the browser's
 * timezone.
 */
class BusinessSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $settings = BusinessSettings::query()->firstOrFail();

        return response()->json([
            'data' => [
                'name' => $settings->name,
                'address' => $settings->address,
                'phone' => $settings->phone,
                'timezone' => $settings->timezone,
                'min_notice_minutes' => $settings->min_notice_minutes,
                'booking_horizon_days' => $settings->booking_horizon_days,
                'cancel_min_notice_minutes' => $settings->cancel_min_notice_minutes,
                'max_active_per_contact' => $settings->max_active_per_contact,
            ],
        ]);
    }
}
