<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AgendaRequest;
use App\Http\Requests\Admin\AppointmentIndexRequest;
use App\Services\Agenda\AgendaBuilder;
use Illuminate\Http\JsonResponse;

class AgendaController extends Controller
{
    public function __construct(private readonly AgendaBuilder $agenda) {}

    /**
     * One local day of the agenda; see AgendaBuilder for the contract.
     */
    public function index(AgendaRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $professionalId = isset($validated['professional_id']) ? (int) $validated['professional_id'] : null;

        return response()->json(['data' => $this->agenda->forDate($validated['date'], $professionalId, $request->statuses())]);
    }

    /**
     * Reservations over a range of local dates; see AgendaBuilder::forRange().
     */
    public function range(AppointmentIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $professionalId = isset($validated['professional_id']) ? (int) $validated['professional_id'] : null;

        return response()->json(['data' => $this->agenda->forRange($validated['from'], $validated['to'], $professionalId, $request->statuses())]);
    }
}
