<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreScheduleBlockRequest;
use App\Models\BusinessSettings;
use App\Models\Professional;
use App\Models\ScheduleBlock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ScheduleBlockController extends Controller
{
    public function index(): JsonResponse
    {
        $blocks = ScheduleBlock::query()->with('professional')->orderBy('starts_at')->get();

        return response()->json(['data' => $this->presentAll($blocks)]);
    }

    /**
     * A "shop" scope block represents closing the barbershop entirely for
     * an interval — no professional attends during it. It still fans out
     * into one schedule_blocks row per professional existing at creation
     * time, created atomically in a single transaction, exactly as
     * docs/planejamento-barbearia-mvp.md section 4 prescribes. What's new
     * in this entrega is `group_id`: a ULID stamped on every row created by
     * the same "shop" request, used only to recognize and remove the whole
     * closure as one operation — see docs/desenvolvimento.md for the full
     * rationale and the documented limitation (professionals added after
     * the closure was created are not retroactively included).
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

        $result = DB::transaction(function () use ($validated, $startsAt, $endsAt) {
            // Lock the single business_settings row first, before any other
            // business read/write — see docs/planejamento-barbearia-mvp.md
            // section 5. No per-professional lock is added.
            BusinessSettings::query()->lockForUpdate()->first();

            if ($validated['scope'] === 'shop') {
                $groupId = (string) Str::ulid();
                $professionalIds = Professional::query()->pluck('id');

                foreach ($professionalIds as $professionalId) {
                    ScheduleBlock::create([
                        'professional_id' => $professionalId,
                        'group_id' => $groupId,
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                        'reason' => $validated['reason'] ?? null,
                    ]);
                }

                return ['group_id' => $groupId];
            }

            $professional = Professional::query()->find($validated['professional_id']);

            if (! $professional) {
                throw ValidationException::withMessages([
                    'professional_id' => [__('errors.professional_not_found')],
                ]);
            }

            $block = ScheduleBlock::create([
                'professional_id' => $professional->id,
                'group_id' => null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'reason' => $validated['reason'] ?? null,
            ]);

            return ['id' => $block->id];
        });

        $item = isset($result['group_id'])
            ? $this->presentGroup($result['group_id'], $this->rowsForGroup($result['group_id']))
            : $this->presentIndividual(ScheduleBlock::query()->with('professional')->findOrFail($result['id']));

        return response()->json(['data' => $item], 201);
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
     * Removes every row of a shop-wide closure as a single atomic
     * operation — all professionals it covered are released from it;
     * other, unrelated blocks that happen to overlap the same interval are
     * untouched.
     */
    public function destroyGroup(string $groupId): JsonResponse
    {
        DB::transaction(function () use ($groupId) {
            BusinessSettings::query()->lockForUpdate()->first();

            $rows = ScheduleBlock::query()->where('group_id', $groupId)->get();

            if ($rows->isEmpty()) {
                abort(404);
            }

            ScheduleBlock::query()->where('group_id', $groupId)->delete();
        });

        return response()->json(null, 204);
    }

    /**
     * @param  Collection<int, ScheduleBlock>  $blocks
     * @return array<int, array<string, mixed>>
     */
    private function presentAll(Collection $blocks): array
    {
        $grouped = $blocks->whereNotNull('group_id')->groupBy('group_id');
        $individual = $blocks->whereNull('group_id');

        $items = $individual->map($this->presentIndividual(...))->all();

        foreach ($grouped as $groupId => $rows) {
            $items[] = $this->presentGroup($groupId, $rows);
        }

        usort($items, fn (array $a, array $b) => $a['starts_at'] <=> $b['starts_at']);

        return $items;
    }

    /**
     * @return Collection<int, ScheduleBlock>
     */
    private function rowsForGroup(string $groupId): Collection
    {
        return ScheduleBlock::query()->with('professional')->where('group_id', $groupId)->get();
    }

    /**
     * @return array{kind: 'professional', id: int, professional: array{id: int, name: string}, starts_at: string, ends_at: string, reason: ?string}
     */
    private function presentIndividual(ScheduleBlock $block): array
    {
        return [
            'kind' => 'professional',
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

    /**
     * @param  Collection<int, ScheduleBlock>  $rows
     * @return array{kind: 'shop', group_id: string, professionals: array<int, array{id: int, name: string}>, starts_at: string, ends_at: string, reason: ?string}
     */
    private function presentGroup(string $groupId, Collection $rows): array
    {
        // Rows in a group are always created together, in the same request,
        // with identical starts_at/ends_at/reason — there is no edit
        // endpoint that could make them drift apart, so any row's values
        // represent the whole group.
        $first = $rows->first();

        return [
            'kind' => 'shop',
            'group_id' => $groupId,
            'professionals' => $rows
                ->map(fn (ScheduleBlock $block) => ['id' => $block->professional->id, 'name' => $block->professional->name])
                ->sortBy('id')
                ->values()
                ->all(),
            'starts_at' => $first->starts_at->toIso8601String(),
            'ends_at' => $first->ends_at->toIso8601String(),
            'reason' => $first->reason,
        ];
    }
}
