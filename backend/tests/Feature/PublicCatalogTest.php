<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BusinessSettings;
use App\Models\Professional;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/v1/public/business, /services and /services/{id}/professionals:
 * the minimal catalog behind the homepage and the booking journey.
 */
class PublicCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_returns_only_public_information(): void
    {
        BusinessSettings::factory()->create([
            'name' => 'Barbearia Teste', 'address' => 'Rua das Navalhas, 10', 'phone' => '(11) 3333-4444',
            'timezone' => 'America/Sao_Paulo', 'booking_horizon_days' => 30,
            'min_notice_minutes' => 90, 'cancel_min_notice_minutes' => 15, 'max_active_per_contact' => 3,
        ]);

        $this->getJson('/api/v1/public/business')
            ->assertOk()
            ->assertExactJson(['data' => [
                'name' => 'Barbearia Teste',
                'address' => 'Rua das Navalhas, 10',
                'phone' => '(11) 3333-4444',
                'timezone' => 'America/Sao_Paulo',
                'booking_horizon_days' => 30,
            ]]);
    }

    public function test_services_lists_only_active_services_offered_by_an_active_professional(): void
    {
        $active = Professional::factory()->create();
        $inactive = Professional::factory()->inactive()->create();

        $offered = Service::factory()->create(['name' => 'Corte clássico', 'description' => 'Tesoura.', 'duration_minutes' => 30, 'price' => '40.00']);
        $inactiveService = Service::factory()->inactive()->create(['name' => 'Corte retrô']);
        $onlyInactivePro = Service::factory()->create(['name' => 'Relaxamento']);
        $nobody = Service::factory()->create(['name' => 'Sem profissional']);
        $active->services()->attach([$offered->id, $inactiveService->id]);
        $inactive->services()->attach($onlyInactivePro);

        $this->getJson('/api/v1/public/services')
            ->assertOk()
            ->assertExactJson(['data' => [[
                'id' => $offered->id,
                'name' => 'Corte clássico',
                'description' => 'Tesoura.',
                'duration_minutes' => 30,
                'price' => '40.00',
            ]]]);
    }

    public function test_professionals_lists_only_active_professionals_of_an_active_service(): void
    {
        $service = Service::factory()->create();
        $rafael = Professional::factory()->create(['name' => 'Rafael Almeida', 'description' => 'Degradê.']);
        $gustavo = Professional::factory()->inactive()->create(['name' => 'Gustavo Ribeiro']);
        $other = Professional::factory()->create(['name' => 'Outro']);
        $service->professionals()->attach([$rafael->id, $gustavo->id]);

        $this->getJson("/api/v1/public/services/{$service->id}/professionals")
            ->assertOk()
            ->assertExactJson(['data' => [['id' => $rafael->id, 'name' => 'Rafael Almeida', 'description' => 'Degradê.']]]);
    }

    public function test_professionals_of_an_unknown_or_inactive_service_are_not_found(): void
    {
        $inactive = Service::factory()->inactive()->create();
        $inactive->professionals()->attach(Professional::factory()->create());

        $this->getJson("/api/v1/public/services/{$inactive->id}/professionals")->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->getJson('/api/v1/public/services/999999/professionals')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->getJson('/api/v1/public/services/abc/professionals')->assertNotFound();
    }

    public function test_the_catalog_never_exposes_customer_data(): void
    {
        BusinessSettings::factory()->create();
        $service = Service::factory()->create();
        $professional = Professional::factory()->create();
        $service->professionals()->attach($professional);
        Appointment::factory()->create([
            'service_id' => $service->id,
            'professional_id' => $professional->id,
            'customer_name' => 'Cliente Sigiloso',
            'customer_email' => 'sigilo@example.com',
        ]);

        foreach (['/api/v1/public/business', '/api/v1/public/services', "/api/v1/public/services/{$service->id}/professionals"] as $url) {
            $body = $this->getJson($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('Cliente Sigiloso', $body);
            $this->assertStringNotContainsString('sigilo@example.com', $body);
            $this->assertStringNotContainsString('is_active', $body);
        }
    }

    public function test_the_catalog_is_rate_limited_per_ip(): void
    {
        BusinessSettings::factory()->create();

        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/public/business')->assertOk();
        }

        $this->getJson('/api/v1/public/business')->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    }
}
