<?php

namespace Tests\Feature\Admin;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_an_authenticated_session(): void
    {
        $this->getJson('/api/v1/admin/services')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_store_requires_an_authenticated_session(): void
    {
        $this->postJson('/api/v1/admin/services', ['name' => 'x'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_index_lists_active_and_inactive_services_in_a_stable_order(): void
    {
        $this->actingAs(User::factory()->create());

        $first = Service::factory()->create(['name' => 'Corte']);
        $second = Service::factory()->inactive()->create(['name' => 'Sobrancelha']);

        $response = $this->getJson('/api/v1/admin/services');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$first->id, $second->id], $ids);
        $this->assertFalse($response->json('data.1.is_active'));
    }

    public function test_store_creates_a_service_with_decimal_price_as_string(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/admin/services', [
            'name' => 'Corte masculino',
            'description' => 'Corte na tesoura ou máquina',
            'duration_minutes' => 30,
            'price' => '45.90',
        ]);

        $response->assertCreated()->assertJson([
            'data' => [
                'name' => 'Corte masculino',
                'description' => 'Corte na tesoura ou máquina',
                'duration_minutes' => 30,
                'price' => '45.90',
                'is_active' => true,
            ],
        ]);

        $this->assertIsString($response->json('data.price'));
        $this->assertDatabaseHas('services', ['name' => 'Corte masculino']);
    }

    public function test_store_allows_a_zero_price(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/admin/services', [
            'name' => 'Cortesia',
            'duration_minutes' => 10,
            'price' => '0.00',
        ]);

        $response->assertCreated()->assertJsonPath('data.price', '0.00');
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/admin/services', []);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['fields' => ['name', 'duration_minutes', 'price']]]);
    }

    public static function invalidDurationProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-10],
            'non_integer' => [12.5],
            'too_large' => [10000],
        ];
    }

    #[DataProvider('invalidDurationProvider')]
    public function test_store_rejects_invalid_durations(mixed $duration): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/admin/services', [
            'name' => 'Corte',
            'duration_minutes' => $duration,
            'price' => '10.00',
        ]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_store_rejects_a_negative_price(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/admin/services', [
            'name' => 'Corte',
            'duration_minutes' => 30,
            'price' => '-5.00',
        ]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_update_edits_fields_partially(): void
    {
        $this->actingAs(User::factory()->create());

        $service = Service::factory()->create([
            'name' => 'Corte',
            'duration_minutes' => 30,
            'price' => '40.00',
        ]);

        $response = $this->patchJson("/api/v1/admin/services/{$service->id}", [
            'price' => '50.00',
        ]);

        $response->assertOk()->assertJson([
            'data' => [
                'name' => 'Corte',
                'duration_minutes' => 30,
                'price' => '50.00',
            ],
        ]);
    }

    public function test_update_can_deactivate_and_reactivate(): void
    {
        $this->actingAs(User::factory()->create());

        $service = Service::factory()->create();

        $this->patchJson("/api/v1/admin/services/{$service->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->patchJson("/api/v1/admin/services/{$service->id}", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_update_returns_not_found_for_a_missing_service(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->patchJson('/api/v1/admin/services/999999', ['is_active' => false]);

        $response->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }
}
