<?php

namespace Tests\Feature;

use App\Exceptions\BusinessConflictException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Error answers and error reporting that matter once the app runs in
 * production (docs/desenvolvimento.md, "Produção com Docker").
 */
class ErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_route_without_a_json_accept_header_answers_401_not_500(): void
    {
        // A browser tab or a scanner hitting the API: there is no Laravel
        // login page (the admin signs in through the SPA), so the guard must
        // not try to redirect to one.
        $this->get('/api/v1/admin/me', ['Accept' => 'text/html'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_business_conflicts_are_answered_but_not_reported_as_errors(): void
    {
        // Expected outcomes (slot taken, contact limit...), not failures: they
        // must not fill the production log with stack traces.
        Exceptions::fake();
        Route::get('/api/test-probe/conflict', fn () => throw BusinessConflictException::contactLimitReached());

        $this->getJson('/api/test-probe/conflict')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONTACT_LIMIT_REACHED');

        Exceptions::assertNotReported(BusinessConflictException::class);
    }
}
