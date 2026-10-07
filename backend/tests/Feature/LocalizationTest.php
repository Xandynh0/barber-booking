<?php

namespace Tests\Feature;

use App\Models\Professional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end proof that a real request's Accept-Language header selects the
 * message language, while `error.code` — the contract the frontend actually
 * switches on — never changes. Locale selection itself (header parsing,
 * fallback) is unit-tested in tests/Unit/SetLocaleFromAcceptLanguageTest.php;
 * this file proves it reaches real responses: a framework-default
 * validation message, a custom withMessages() one, and an exception-handler
 * one, in both directions.
 */
class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_exception_handler_message_is_translated_to_english(): void
    {
        $this->withHeaders(['Accept-Language' => 'en'])
            ->getJson('/api/v1/admin/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('error.message', 'Authentication required.');
    }

    public function test_an_exception_handler_message_defaults_to_portuguese(): void
    {
        $this->withHeaders(['Accept-Language' => 'pt-BR'])
            ->getJson('/api/v1/admin/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('error.message', 'Autenticação necessária.');
    }

    public function test_an_unsupported_language_falls_back_to_portuguese(): void
    {
        $this->withHeaders(['Accept-Language' => 'fr-FR'])
            ->getJson('/api/v1/admin/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('error.message', 'Autenticação necessária.');
    }

    public function test_a_framework_default_validation_message_is_translated_in_both_languages(): void
    {
        $this->actingAs(User::factory()->create());

        $pt = $this->withHeaders(['Accept-Language' => 'pt-BR'])
            ->postJson('/api/v1/admin/services', []);
        $pt->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertSame('O campo nome é obrigatório.', $pt->json('error.fields.name.0'));

        $en = $this->withHeaders(['Accept-Language' => 'en'])
            ->postJson('/api/v1/admin/services', []);
        $en->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertSame('The name field is required.', $en->json('error.fields.name.0'));
    }

    public function test_a_custom_business_validation_message_is_translated_in_both_languages(): void
    {
        $this->actingAs(User::factory()->create());

        $pt = $this->withHeaders(['Accept-Language' => 'pt-BR'])->postJson('/api/v1/admin/professionals', [
            'name' => 'Lucas',
            'service_ids' => [999999],
        ]);
        $this->assertSame(
            'Um ou mais serviços informados não existem.',
            $pt->json('error.fields.service_ids.0')
        );

        $en = $this->withHeaders(['Accept-Language' => 'en'])->postJson('/api/v1/admin/professionals', [
            'name' => 'Lucas',
            'service_ids' => [999999],
        ]);
        $this->assertSame(
            'One or more of the given services do not exist.',
            $en->json('error.fields.service_ids.0')
        );
    }

    public function test_error_code_stays_the_same_regardless_of_language(): void
    {
        $professional = Professional::factory()->create();

        $pt = $this->withHeaders(['Accept-Language' => 'pt-BR'])
            ->getJson("/api/v1/admin/professionals/{$professional->id}/working-hours");
        $en = $this->withHeaders(['Accept-Language' => 'en'])
            ->getJson("/api/v1/admin/professionals/{$professional->id}/working-hours");

        $pt->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $en->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->assertNotSame($pt->json('error.message'), $en->json('error.message'));
    }
}
