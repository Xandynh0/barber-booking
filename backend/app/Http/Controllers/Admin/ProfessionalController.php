<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProfessionalRequest;
use App\Http\Requests\Admin\UpdateProfessionalRequest;
use App\Models\BusinessSettings;
use App\Models\Professional;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProfessionalController extends Controller
{
    public function index(): JsonResponse
    {
        $professionals = Professional::query()->with('services')->orderBy('id')->get();

        return response()->json([
            'data' => $professionals->map($this->present(...))->all(),
        ]);
    }

    public function store(StoreProfessionalRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $serviceIds = $validated['service_ids'] ?? [];

        $professional = DB::transaction(function () use ($validated, $serviceIds) {
            // Lock the single business_settings row first, before the
            // service-existence business read below — see
            // docs/planejamento-barbearia-mvp.md section 5.
            BusinessSettings::query()->lockForUpdate()->first();

            $this->assertServicesExist($serviceIds);

            $professional = Professional::create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            $professional->services()->attach($serviceIds);

            return $professional;
        });

        return response()->json(['data' => $this->present($professional->load('services'))], 201);
    }

    public function update(UpdateProfessionalRequest $request, Professional $professional): JsonResponse
    {
        $validated = $request->validated();
        $hasServiceIds = array_key_exists('service_ids', $validated);

        $professional = DB::transaction(function () use ($validated, $hasServiceIds, $professional) {
            BusinessSettings::query()->lockForUpdate()->first();

            if ($hasServiceIds) {
                $this->assertServicesExist($validated['service_ids']);
            }

            $professional->fill(array_filter(
                $validated,
                fn (string $key) => $key !== 'service_ids',
                ARRAY_FILTER_USE_KEY,
            ));
            $professional->save();

            if ($hasServiceIds) {
                $professional->services()->sync($validated['service_ids']);
            }

            return $professional;
        });

        return response()->json(['data' => $this->present($professional->load('services'))]);
    }

    /**
     * Existence of linked services is a business read, not a format check —
     * it must run after the business_settings lock is held, inside the same
     * transaction as the write it guards (see docs/planejamento-barbearia-mvp.md
     * section 5). A mismatch throws before anything else in the transaction
     * is written, so an invalid service_ids list can't leave the
     * professional's other fields partially updated.
     *
     * @param  array<int, int>  $serviceIds
     */
    private function assertServicesExist(array $serviceIds): void
    {
        if ($serviceIds === []) {
            return;
        }

        $existingIds = Service::query()->whereIn('id', $serviceIds)->pluck('id')->all();
        $missingIds = array_diff($serviceIds, $existingIds);

        if ($missingIds !== []) {
            throw ValidationException::withMessages([
                'service_ids' => ['Um ou mais serviços informados não existem.'],
            ]);
        }
    }

    /**
     * @return array{id: int, name: string, description: ?string, is_active: bool, services: array<int, array{id: int, name: string, is_active: bool}>}
     */
    private function present(Professional $professional): array
    {
        return [
            'id' => $professional->id,
            'name' => $professional->name,
            'description' => $professional->description,
            'is_active' => $professional->is_active,
            'services' => $professional->services->map(fn (Service $service) => [
                'id' => $service->id,
                'name' => $service->name,
                'is_active' => $service->is_active,
            ])->all(),
        ];
    }
}
