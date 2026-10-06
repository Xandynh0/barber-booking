<?php

namespace Tests\Feature\Admin;

use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfessionalTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_an_authenticated_session(): void
    {
        $this->getJson('/api/v1/admin/professionals')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_store_creates_a_professional_without_any_services(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/admin/professionals', [
            'name' => 'Lucas',
        ]);

        $response->assertCreated()->assertJson([
            'data' => [
                'name' => 'Lucas',
                'is_active' => true,
                'services' => [],
            ],
        ]);
    }

    public function test_store_creates_a_professional_with_linked_services(): void
    {
        $this->actingAs(User::factory()->create());
        $serviceA = Service::factory()->create();
        $serviceB = Service::factory()->create();

        $response = $this->postJson('/api/v1/admin/professionals', [
            'name' => 'Lucas',
            'service_ids' => [$serviceA->id, $serviceB->id],
        ]);

        $response->assertCreated();
        $ids = collect($response->json('data.services'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$serviceA->id, $serviceB->id], $ids);
    }

    public function test_store_rejects_duplicate_service_ids(): void
    {
        $this->actingAs(User::factory()->create());
        $service = Service::factory()->create();

        $response = $this->postJson('/api/v1/admin/professionals', [
            'name' => 'Lucas',
            'service_ids' => [$service->id, $service->id],
        ]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertDatabaseMissing('professionals', ['name' => 'Lucas']);
    }

    public function test_store_rejects_a_nonexistent_service_id(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/admin/professionals', [
            'name' => 'Lucas',
            'service_ids' => [999999],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.fields.service_ids.0', 'Um ou mais serviços informados não existem.');
        $this->assertDatabaseMissing('professionals', ['name' => 'Lucas']);
    }

    public function test_update_omitting_service_ids_preserves_existing_links(): void
    {
        $this->actingAs(User::factory()->create());
        $service = Service::factory()->create();
        $professional = Professional::factory()->create();
        $professional->services()->attach($service);

        $response = $this->patchJson("/api/v1/admin/professionals/{$professional->id}", [
            'description' => 'Especialista em barba',
        ]);

        $response->assertOk()->assertJsonPath('data.description', 'Especialista em barba');
        $this->assertDatabaseHas('professional_service', [
            'professional_id' => $professional->id,
            'service_id' => $service->id,
        ]);
    }

    public function test_update_with_empty_service_ids_clears_all_links(): void
    {
        $this->actingAs(User::factory()->create());
        $service = Service::factory()->create();
        $professional = Professional::factory()->create();
        $professional->services()->attach($service);

        $response = $this->patchJson("/api/v1/admin/professionals/{$professional->id}", [
            'service_ids' => [],
        ]);

        $response->assertOk()->assertJsonPath('data.services', []);
        $this->assertDatabaseMissing('professional_service', [
            'professional_id' => $professional->id,
        ]);
    }

    public function test_update_preserves_a_link_to_a_service_that_becomes_inactive_and_flags_it(): void
    {
        $this->actingAs(User::factory()->create());
        $service = Service::factory()->create();
        $professional = Professional::factory()->create();
        $professional->services()->attach($service);

        $this->patchJson("/api/v1/admin/services/{$service->id}", ['is_active' => false])->assertOk();

        $response = $this->getJson('/api/v1/admin/professionals');

        $response->assertOk()
            ->assertJsonPath('data.0.services.0.id', $service->id)
            ->assertJsonPath('data.0.services.0.is_active', false);
    }

    public function test_an_invalid_service_ids_update_does_not_partially_apply_other_field_changes(): void
    {
        $this->actingAs(User::factory()->create());
        $professional = Professional::factory()->create(['name' => 'Original']);

        $response = $this->patchJson("/api/v1/admin/professionals/{$professional->id}", [
            'name' => 'Deveria Falhar',
            'service_ids' => [999999],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('professionals', [
            'id' => $professional->id,
            'name' => 'Original',
        ]);
        $this->assertDatabaseMissing('professionals', ['name' => 'Deveria Falhar']);
    }

    public function test_update_returns_not_found_for_a_missing_professional(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->patchJson('/api/v1/admin/professionals/999999', ['is_active' => false]);

        $response->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }
}
