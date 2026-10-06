<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\BusinessSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_requires_an_authenticated_session(): void
    {
        $this->getJson('/api/v1/admin/business-settings')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_show_returns_the_seeded_singleton(): void
    {
        (new BusinessSettingsSeeder)->run();
        $this->actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/admin/business-settings');

        $response->assertOk()->assertJsonPath('data.timezone', 'America/Sao_Paulo');
    }
}
