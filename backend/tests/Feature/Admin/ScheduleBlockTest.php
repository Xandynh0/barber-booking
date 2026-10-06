<?php

namespace Tests\Feature\Admin;

use App\Models\Professional;
use App\Models\ScheduleBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_an_authenticated_session(): void
    {
        $this->getJson('/api/v1/admin/schedule-blocks')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_store_requires_an_authenticated_session(): void
    {
        $this->postJson('/api/v1/admin/schedule-blocks', ['scope' => 'shop'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_destroy_requires_an_authenticated_session(): void
    {
        $block = ScheduleBlock::factory()->create();

        $this->deleteJson("/api/v1/admin/schedule-blocks/{$block->id}")
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_destroy_group_requires_an_authenticated_session(): void
    {
        $this->deleteJson('/api/v1/admin/schedule-blocks/groups/01ARZ3NDEKTSV4RRFFQ69G5FAV')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_store_creates_a_block_for_a_single_professional(): void
    {
        $this->actingAs(User::factory()->create());
        $professional = Professional::factory()->create();

        $response = $this->postJson('/api/v1/admin/schedule-blocks', [
            'scope' => 'professional',
            'professional_id' => $professional->id,
            'starts_at' => '2026-12-24T18:00:00-03:00',
            'ends_at' => '2026-12-26T08:00:00-03:00',
            'reason' => 'Feriado prolongado',
        ]);

        $response->assertCreated();
        $data = $response->json('data');
        $this->assertSame('professional', $data['kind']);
        $this->assertSame($professional->id, $data['professional']['id']);
        $this->assertSame('Feriado prolongado', $data['reason']);
        $this->assertDatabaseCount('schedule_blocks', 1);
        $this->assertDatabaseHas('schedule_blocks', ['id' => $data['id'], 'group_id' => null]);
    }

    public function test_a_block_can_span_across_days(): void
    {
        $this->actingAs(User::factory()->create());
        $professional = Professional::factory()->create();

        $response = $this->postJson('/api/v1/admin/schedule-blocks', [
            'scope' => 'professional',
            'professional_id' => $professional->id,
            'starts_at' => '2026-12-24T18:00:00-03:00',
            'ends_at' => '2026-12-26T08:00:00-03:00',
        ]);

        $response->assertCreated();
        $this->assertSame('2026-12-24T21:00:00+00:00', $response->json('data.starts_at'));
        $this->assertSame('2026-12-26T11:00:00+00:00', $response->json('data.ends_at'));
    }

    public function test_store_rejects_professional_id_when_scope_is_shop(): void
    {
        $this->actingAs(User::factory()->create());
        $professional = Professional::factory()->create();

        $response = $this->postJson('/api/v1/admin/schedule-blocks', [
            'scope' => 'shop',
            'professional_id' => $professional->id,
            'starts_at' => '2026-12-25T00:00:00-03:00',
            'ends_at' => '2026-12-25T23:59:00-03:00',
        ]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertDatabaseCount('schedule_blocks', 0);
    }

    public function test_store_rejects_a_nonexistent_professional_id(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/admin/schedule-blocks', [
            'scope' => 'professional',
            'professional_id' => 999999,
            'starts_at' => '2026-12-25T00:00:00-03:00',
            'ends_at' => '2026-12-25T23:59:00-03:00',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.fields.professional_id.0', 'Profissional informado não existe.');
        $this->assertDatabaseCount('schedule_blocks', 0);
    }

    public function test_store_rejects_an_end_before_start(): void
    {
        $this->actingAs(User::factory()->create());
        $professional = Professional::factory()->create();

        $response = $this->postJson('/api/v1/admin/schedule-blocks', [
            'scope' => 'professional',
            'professional_id' => $professional->id,
            'starts_at' => '2026-12-25T12:00:00-03:00',
            'ends_at' => '2026-12-25T10:00:00-03:00',
        ]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertDatabaseCount('schedule_blocks', 0);
    }

    public function test_overlapping_blocks_for_the_same_professional_are_both_allowed(): void
    {
        $this->actingAs(User::factory()->create());
        $professional = Professional::factory()->create();

        $this->postJson('/api/v1/admin/schedule-blocks', [
            'scope' => 'professional',
            'professional_id' => $professional->id,
            'starts_at' => '2026-12-25T09:00:00-03:00',
            'ends_at' => '2026-12-25T12:00:00-03:00',
        ])->assertCreated();

        $response = $this->postJson('/api/v1/admin/schedule-blocks', [
            'scope' => 'professional',
            'professional_id' => $professional->id,
            'starts_at' => '2026-12-25T10:00:00-03:00',
            'ends_at' => '2026-12-25T14:00:00-03:00',
        ]);

        $response->assertCreated();
        $this->assertDatabaseCount('schedule_blocks', 2);
    }

    public function test_index_lists_blocks_ordered_by_start_with_professional_embedded(): void
    {
        $this->actingAs(User::factory()->create());
        $professional = Professional::factory()->create(['name' => 'Lucas']);
        ScheduleBlock::factory()->for($professional)->create([
            'starts_at' => '2026-12-25 00:00:00',
            'ends_at' => '2026-12-25 23:59:00',
        ]);

        $response = $this->getJson('/api/v1/admin/schedule-blocks');

        $response->assertOk();
        $this->assertSame('professional', $response->json('data.0.kind'));
        $this->assertSame('Lucas', $response->json('data.0.professional.name'));
    }

    public function test_destroy_removes_a_single_block(): void
    {
        $this->actingAs(User::factory()->create());
        $block = ScheduleBlock::factory()->create();

        $this->deleteJson("/api/v1/admin/schedule-blocks/{$block->id}")->assertNoContent();

        $this->assertDatabaseMissing('schedule_blocks', ['id' => $block->id]);
    }

    public function test_destroy_returns_not_found_for_a_missing_block(): void
    {
        $this->actingAs(User::factory()->create());

        $this->deleteJson('/api/v1/admin/schedule-blocks/999999')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    // --- Fechamento da barbearia (scope "shop", agrupado por group_id) ---

    public function test_shop_closure_for_a_whole_day_converts_midnight_to_midnight_in_the_barbershop_timezone(): void
    {
        $this->actingAs(User::factory()->create());
        Professional::factory()->create();

        // 2026-12-25 00:00 to 2026-12-26 00:00, America/Sao_Paulo (-03:00) —
        // what the frontend's "Dia inteiro" option computes and sends.
        $response = $this->postJson('/api/v1/admin/schedule-blocks', [
            'scope' => 'shop',
            'starts_at' => '2026-12-25T00:00:00-03:00',
            'ends_at' => '2026-12-26T00:00:00-03:00',
            'reason' => 'Natal',
        ]);

        $response->assertCreated();
        $this->assertSame('2026-12-25T03:00:00+00:00', $response->json('data.starts_at'));
        $this->assertSame('2026-12-26T03:00:00+00:00', $response->json('data.ends_at'));
    }

    public function test_shop_closure_appears_as_a_single_item_covering_every_professional(): void
    {
        $this->actingAs(User::factory()->create());
        $first = Professional::factory()->create();
        $second = Professional::factory()->create();

        $response = $this->postJson('/api/v1/admin/schedule-blocks', [
            'scope' => 'shop',
            'starts_at' => '2026-12-25T00:00:00-03:00',
            'ends_at' => '2026-12-25T23:59:00-03:00',
            'reason' => 'Natal',
        ]);

        $response->assertCreated();
        $data = $response->json('data');
        $this->assertSame('shop', $data['kind']);
        $this->assertNotEmpty($data['group_id']);
        $ids = collect($data['professionals'])->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $ids);
        $this->assertDatabaseCount('schedule_blocks', 2);

        // The listing shows the same closure as one item too, not two rows.
        $index = $this->getJson('/api/v1/admin/schedule-blocks');
        $index->assertOk();
        $this->assertCount(1, $index->json('data'));
        $this->assertSame('shop', $index->json('data.0.kind'));
    }

    public function test_removing_a_shop_closure_deletes_all_and_only_its_rows(): void
    {
        $this->actingAs(User::factory()->create());
        Professional::factory()->count(3)->create();

        $created = $this->postJson('/api/v1/admin/schedule-blocks', [
            'scope' => 'shop',
            'starts_at' => '2026-12-25T00:00:00-03:00',
            'ends_at' => '2026-12-25T23:59:00-03:00',
        ])->json('data');

        // An unrelated individual block and an unrelated second closure,
        // both overlapping the same interval, must survive.
        $otherProfessional = Professional::factory()->create();
        $individual = $this->postJson('/api/v1/admin/schedule-blocks', [
            'scope' => 'professional',
            'professional_id' => $otherProfessional->id,
            'starts_at' => '2026-12-25T10:00:00-03:00',
            'ends_at' => '2026-12-25T12:00:00-03:00',
        ])->json('data');

        $this->assertDatabaseCount('schedule_blocks', 4);

        $this->deleteJson("/api/v1/admin/schedule-blocks/groups/{$created['group_id']}")->assertNoContent();

        $this->assertDatabaseCount('schedule_blocks', 1);
        $this->assertDatabaseHas('schedule_blocks', ['id' => $individual['id']]);
        $this->assertDatabaseMissing('schedule_blocks', ['group_id' => $created['group_id']]);
    }

    public function test_destroy_group_returns_not_found_for_an_unknown_group(): void
    {
        $this->actingAs(User::factory()->create());

        $this->deleteJson('/api/v1/admin/schedule-blocks/groups/01ARZ3NDEKTSV4RRFFQ69G5FAV')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_preexisting_rows_without_a_group_id_remain_individual_in_the_listing(): void
    {
        $this->actingAs(User::factory()->create());
        $a = Professional::factory()->create(['name' => 'Ana']);
        $b = Professional::factory()->create(['name' => 'Beto']);

        // Simulates data created before this entrega's group_id column —
        // same starts_at/ends_at/reason, but no group_id, created one at a
        // time rather than via the "shop" scope.
        ScheduleBlock::factory()->for($a)->create([
            'starts_at' => '2026-12-25 00:00:00',
            'ends_at' => '2026-12-25 23:59:00',
            'reason' => 'Natal',
            'group_id' => null,
        ]);
        ScheduleBlock::factory()->for($b)->create([
            'starts_at' => '2026-12-25 00:00:00',
            'ends_at' => '2026-12-25 23:59:00',
            'reason' => 'Natal',
            'group_id' => null,
        ]);

        $response = $this->getJson('/api/v1/admin/schedule-blocks');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertSame(['professional', 'professional'], collect($data)->pluck('kind')->all());
    }

    public function test_shop_closure_creation_does_not_disturb_existing_individual_blocks_or_data(): void
    {
        $this->actingAs(User::factory()->create());
        $professional = Professional::factory()->create();
        $existing = ScheduleBlock::factory()->for($professional)->create();

        $this->postJson('/api/v1/admin/schedule-blocks', [
            'scope' => 'shop',
            'starts_at' => '2026-12-25T00:00:00-03:00',
            'ends_at' => '2026-12-25T23:59:00-03:00',
        ])->assertCreated();

        $this->assertDatabaseHas('schedule_blocks', [
            'id' => $existing->id,
            'professional_id' => $professional->id,
            'group_id' => null,
        ]);
    }
}
