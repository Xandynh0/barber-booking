<?php

namespace App\Http\Controllers;

use App\Http\Requests\AvailabilityRequest;
use App\Models\BusinessSettings;
use App\Models\Professional;
use App\Models\Service;
use App\Services\Availability\AvailabilityContext;
use App\Services\Availability\AvailabilityEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Both availability routes share this controller; each route is bound to
 * one method, and each method fixes the context. The admin method is only
 * reachable behind `auth:sanctum` (routes/api.php), so the public route can
 * never be told to apply the admin rules.
 */
class AvailabilityController extends Controller
{
    public function __construct(private readonly AvailabilityEngine $engine) {}

    public function forPublic(AvailabilityRequest $request): JsonResponse
    {
        return $this->respond($request, AvailabilityContext::Public);
    }

    public function forAdmin(AvailabilityRequest $request): JsonResponse
    {
        return $this->respond($request, AvailabilityContext::Admin);
    }

    private function respond(AvailabilityRequest $request, AvailabilityContext $context): JsonResponse
    {
        $validated = $request->validated();

        $service = Service::query()->find($validated['service_id']);
        if (! $service || ! $service->is_active) {
            throw ValidationException::withMessages([
                'service_id' => [__('errors.availability_service_unavailable')],
            ]);
        }

        $professional = Professional::query()->find($validated['professional_id']);
        if (! $professional || ! $professional->is_active) {
            throw ValidationException::withMessages([
                'professional_id' => [__('errors.availability_professional_unavailable')],
            ]);
        }

        if (! $this->engine->isBookableCombination($service, $professional)) {
            throw ValidationException::withMessages([
                'professional_id' => [__('errors.availability_service_not_offered')],
            ]);
        }

        $slots = $this->engine->slotsFor($service, $professional, $validated['date'], $context);

        return response()->json([
            'data' => [
                'timezone' => BusinessSettings::query()->firstOrFail()->timezone,
                'date' => $validated['date'],
                'slots' => array_map(fn (array $slot) => [
                    'starts_at' => $slot['starts_at']->toIso8601String(),
                    'ends_at' => $slot['ends_at']->toIso8601String(),
                ], $slots),
            ],
        ]);
    }
}
