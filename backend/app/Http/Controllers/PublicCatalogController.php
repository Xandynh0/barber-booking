<?php

namespace App\Http\Controllers;

use App\Models\BusinessSettings;
use App\Models\Professional;
use App\Models\Service;
use Illuminate\Http\JsonResponse;

/**
 * Read-only catalog for the public booking journey and the homepage
 * (docs/planejamento-barbearia-mvp.md, seção 2). Minimal responses: only
 * what a customer needs to choose — never administrative settings,
 * internal flags, links to inactive records or anyone's contacts.
 *
 * "Bookable" means active and linked to an active counterpart, the same
 * combination the availability engine accepts.
 */
class PublicCatalogController extends Controller
{
    public function business(): JsonResponse
    {
        $settings = BusinessSettings::query()->firstOrFail();

        return response()->json([
            'data' => [
                'name' => $settings->name,
                'address' => $settings->address,
                'phone' => $settings->phone,
                // Needed by the client to show slots in the barbershop's own
                // timezone and to offer only dates inside the horizon.
                'timezone' => $settings->timezone,
                'booking_horizon_days' => (int) $settings->booking_horizon_days,
            ],
        ]);
    }

    /**
     * Active services that at least one active professional offers — a
     * service nobody can perform would only lead the customer to a dead end.
     */
    public function services(): JsonResponse
    {
        $services = Service::query()
            ->where('is_active', true)
            ->whereHas('professionals', fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $services->map(fn (Service $service) => [
                'id' => $service->id,
                'name' => $service->name,
                'description' => $service->description,
                'duration_minutes' => (int) $service->duration_minutes,
                'price' => (string) $service->price,
            ])->values(),
        ]);
    }

    /**
     * Active professionals that offer an active service. An unknown or
     * inactive service is simply not found.
     */
    public function professionals(int $service): JsonResponse
    {
        $bookable = Service::query()->whereKey($service)->where('is_active', true)->first();

        abort_if($bookable === null, 404);

        $professionals = $bookable->professionals()
            ->where('professionals.is_active', true)
            ->orderBy('professionals.name')
            ->get(['professionals.id', 'professionals.name', 'professionals.description']);

        return response()->json([
            'data' => $professionals->map(fn (Professional $professional) => [
                'id' => $professional->id,
                'name' => $professional->name,
                'description' => $professional->description,
            ])->values(),
        ]);
    }
}
