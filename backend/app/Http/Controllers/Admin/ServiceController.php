<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreServiceRequest;
use App\Http\Requests\Admin\UpdateServiceRequest;
use App\Models\BusinessSettings;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ServiceController extends Controller
{
    public function index(): JsonResponse
    {
        $services = Service::query()->orderBy('id')->get();

        return response()->json([
            'data' => $services->map($this->present(...))->all(),
        ]);
    }

    public function store(StoreServiceRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $service = DB::transaction(function () use ($validated) {
            // Lock the single business_settings row first, before any other
            // business read/write for this change — see
            // docs/planejamento-barbearia-mvp.md section 5.
            BusinessSettings::query()->lockForUpdate()->first();

            // Set the default explicitly rather than relying on the column
            // default — Eloquent doesn't reload DB-applied defaults onto the
            // in-memory instance after create(), so the API response would
            // otherwise report is_active as null even though the stored row
            // is correct.
            return Service::create([
                ...$validated,
                'is_active' => $validated['is_active'] ?? true,
            ]);
        });

        return response()->json(['data' => $this->present($service)], 201);
    }

    public function update(UpdateServiceRequest $request, Service $service): JsonResponse
    {
        $validated = $request->validated();

        $service = DB::transaction(function () use ($validated, $service) {
            BusinessSettings::query()->lockForUpdate()->first();

            $service->fill($validated);
            $service->save();

            return $service;
        });

        return response()->json(['data' => $this->present($service)]);
    }

    /**
     * @return array{id: int, name: string, description: ?string, duration_minutes: int, price: string, is_active: bool}
     */
    private function present(Service $service): array
    {
        return [
            'id' => $service->id,
            'name' => $service->name,
            'description' => $service->description,
            'duration_minutes' => $service->duration_minutes,
            'price' => (string) $service->price,
            'is_active' => $service->is_active,
        ];
    }
}
