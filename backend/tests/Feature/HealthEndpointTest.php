<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_reports_ok_when_database_is_reachable(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)->assertJson(['status' => 'ok']);
    }
}
