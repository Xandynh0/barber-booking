<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateWorkingHoursRequest;
use App\Models\BusinessSettings;
use App\Models\Professional;
use App\Models\WorkingHour;
use App\Services\Booking\AgendaConflicts;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class WorkingHourController extends Controller
{
    public function show(Professional $professional): JsonResponse
    {
        $workingHours = WorkingHour::query()
            ->where('professional_id', $professional->id)
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get();

        return response()->json(['data' => $this->present($professional->id, $workingHours)]);
    }

    public function update(UpdateWorkingHoursRequest $request, Professional $professional, AgendaConflicts $agendaConflicts): JsonResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $professional, $agendaConflicts) {
            // Lock the single business_settings row first, then the
            // professional — the fixed order every agenda write follows
            // (docs/planejamento-barbearia-mvp.md, seção 5).
            BusinessSettings::query()->lockForUpdate()->first();
            Professional::query()->lockForUpdate()->find($professional->id);

            WorkingHour::query()->where('professional_id', $professional->id)->delete();

            foreach ($validated['days'] as $day) {
                foreach ($day['periods'] as $period) {
                    WorkingHour::create([
                        'professional_id' => $professional->id,
                        'weekday' => $day['weekday'],
                        'start_time' => $period['start_time'],
                        'end_time' => $period['end_time'],
                    ]);
                }
            }

            // Seção 7: a future confirmed reservation that no longer fits the
            // new week refuses the whole change (the exception rolls back the
            // writes above).
            $agendaConflicts->assertFutureAppointmentsFitWorkingHours($professional);
        });

        $workingHours = WorkingHour::query()
            ->where('professional_id', $professional->id)
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get();

        return response()->json(['data' => $this->present($professional->id, $workingHours)]);
    }

    /**
     * @param  Collection<int, WorkingHour>  $workingHours
     * @return array{professional_id: int, days: array<int, array{weekday: int, periods: array<int, array{start_time: string, end_time: string}>}>}
     */
    private function present(int $professionalId, Collection $workingHours): array
    {
        $days = collect(range(0, 6))->map(function (int $weekday) use ($workingHours) {
            return [
                'weekday' => $weekday,
                'periods' => $workingHours
                    ->where('weekday', $weekday)
                    ->map(fn (WorkingHour $workingHour) => [
                        // Stored as a MySQL TIME ("09:00:00"); trimmed to
                        // "09:00" for the API, matching what the form
                        // accepts back on save.
                        'start_time' => substr((string) $workingHour->start_time, 0, 5),
                        'end_time' => substr((string) $workingHour->end_time, 0, 5),
                    ])
                    ->values()
                    ->all(),
            ];
        })->all();

        return ['professional_id' => $professionalId, 'days' => $days];
    }
}
