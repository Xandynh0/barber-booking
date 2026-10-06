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
        $this->assertCount(1, $data);
        $this->assertSame($professional->id, $data[0]['professional']['id']);
        $this->assertSame('Feriado prolongado', $data[0]['reason']);
        $this->assertDatabaseCount('schedule_blocks', 1);
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
        $this->assertSame('2026-12-24T21:00:00+00:00', $response->json('data.0.starts_at'));
        $this->assertSame('2026-12-26T11:00:00+00:00', $response->json('data.0.ends_at'));
    }

    public function test_store_with_shop_scope_creates_one_block_per_professional_atomically(): void
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
        $ids = collect($response->json('data'))->pluck('professional.id')->all();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $ids);
        $this->assertDatabaseCount('schedule_blocks', 2);
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
}
