<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreScheduleBlockRequest;
use App\Models\BusinessSettings;
use App\Models\Professional;
use App\Models\ScheduleBlock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleBlockController extends Controller
{
    public function index(): JsonResponse
    {
        $blocks = ScheduleBlock::query()->with('professional')->orderBy('starts_at')->get();

        return response()->json(['data' => $blocks->map($this->present(...))->all()]);
    }

    /**
     * A "shop" scope block is not a distinct kind of row — it is a
     * convenience at creation time that fans out into one schedule_blocks
     * row per professional, created atomically, exactly as
     * docs/planejamento-barbearia-mvp.md section 4 prescribes ("para fechar
     * a barbearia inteira, criar bloqueios para todos os profissionais numa
     * operação atômica"). There is no group/batch column: each row is
     * independently listed and removable, and always names its own
     * professional, so the scope of any given row is never ambiguous — see
     * docs/desenvolvimento.md for the full rationale.
     */
    public function store(StoreScheduleBlockRequest $request): JsonResponse
    {
        $validated = $request->validated();
        // Eloquent's `datetime` cast formats the value for storage using
        // whatever timezone the parsed Carbon instance carries — it does
        // NOT convert to the app timezone on its own. Inputs arrive as ISO
        // 8601 with an explicit offset (docs/planejamento-barbearia-mvp.md
        // section 11); converting to UTC here is what actually makes the
        // stored instant UTC, as the project's storage convention requires.
        $startsAt = CarbonImmutable::parse($validated['starts_at'])->utc();
        $endsAt = CarbonImmutable::parse($validated['ends_at'])->utc();

        $createdIds = DB::transaction(function () use ($validated, $startsAt, $endsAt) {
            // Lock the single business_settings row first, before any other
            // business read/write — see docs/planejamento-barbearia-mvp.md
            // section 5. No per-professional lock is added.
            BusinessSettings::query()->lockForUpdate()->first();

            if ($validated['scope'] === 'shop') {
                $professionalIds = Professional::query()->pluck('id');
            } else {
                $professional = Professional::query()->find($validated['professional_id']);

                if (! $professional) {
                    throw ValidationException::withMessages([
                        'professional_id' => ['Profissional informado não existe.'],
                    ]);
                }

                $professionalIds = collect([$professional->id]);
            }

            return $professionalIds->map(fn (int $professionalId) => ScheduleBlock::create([
                'professional_id' => $professionalId,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'reason' => $validated['reason'] ?? null,
            ])->id);
        });

        $blocks = ScheduleBlock::query()
            ->with('professional')
            ->whereIn('id', $createdIds)
            ->orderBy('professional_id')
            ->get();

        return response()->json(['data' => $blocks->map($this->present(...))->all()], 201);
    }

    public function destroy(ScheduleBlock $scheduleBlock): JsonResponse
    {
        DB::transaction(function () use ($scheduleBlock) {
            BusinessSettings::query()->lockForUpdate()->first();

            $scheduleBlock->delete();
        });

        return response()->json(null, 204);
    }

    /**
     * @return array{id: int, professional: array{id: int, name: string}, starts_at: string, ends_at: string, reason: ?string}
     */
    private function present(ScheduleBlock $block): array
    {
        return [
            'id' => $block->id,
            'professional' => [
                'id' => $block->professional->id,
                'name' => $block->professional->name,
            ],
            'starts_at' => $block->starts_at->toIso8601String(),
            'ends_at' => $block->ends_at->toIso8601String(),
            'reason' => $block->reason,
        ];
    }
}
