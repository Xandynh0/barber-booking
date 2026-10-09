<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Exercises the real SPA auth flow (session cookie + Sanctum's stateful CSRF
 * pipeline) end to end — no Sanctum::actingAs() shortcuts and no middleware
 * disabled — so a pass here means CSRF is genuinely enforced, not assumed.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const STATEFUL_ORIGIN = 'http://localhost:8080';

    /**
     * Hits GET /sanctum/csrf-cookie and stores the session + XSRF cookies it
     * returns for subsequent requests in this test, returning the (still
     * encrypted, as a browser would hold it) XSRF cookie value to send back
     * as the X-XSRF-TOKEN header.
     */
    private function primeCsrf(): string
    {
        $response = $this->get('/sanctum/csrf-cookie');
        $response->assertNoContent();

        return $this->storeCsrfCookies($response);
    }

    /**
     * Re-reads the session + XSRF cookies from any stateful response (not
     * just /sanctum/csrf-cookie) — needed because session()->regenerate()
     * rotates the CSRF token too, so the cookies from priming go stale the
     * moment login succeeds.
     */
    private function storeCsrfCookies($response): string
    {
        $cookies = Collection::make($response->headers->getCookies());

        $xsrf = $cookies->first(fn ($cookie) => $cookie->getName() === 'XSRF-TOKEN');
        $session = $cookies->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

        $this->assertNotNull($xsrf, 'csrf-cookie response did not set XSRF-TOKEN.');
        $this->assertNotNull($session, 'csrf-cookie response did not set the session cookie.');

        $this->withCookie($xsrf->getName(), $xsrf->getValue());
        $this->withCookie($session->getName(), $session->getValue());

        return $xsrf->getValue();
    }

    /**
     * AuthManager caches resolved guard instances (and SessionGuard caches
     * its resolved user) for the lifetime of the container. Sequential
     * simulated requests in one test share that container, so without this,
     * a guard resolved as "logged in" during one call would keep reporting
     * logged in on every later call in the same test regardless of what the
     * current request's session actually says — real independent HTTP
     * requests don't have this problem, only this in-process test client.
     * Call this between any two calls where the auth state may have
     * changed (login/logout) and the next call's result depends on it.
     */
    private function forgetAuthGuards(): void
    {
        Auth::forgetGuards();
    }

    private function statefulHeaders(string $xsrf): array
    {
        return [
            'Referer' => self::STATEFUL_ORIGIN,
            'X-XSRF-TOKEN' => $xsrf,
        ];
    }

    /**
     * The session cookie's ciphertext differs on every response regardless of
     * whether the session ID actually changed (random IV per encryption), so
     * comparing raw cookie values can't prove regeneration. Decrypt it to
     * get the real session ID underneath, the same way the cookie-encryption
     * middleware does when reading an incoming request.
     */
    private function sessionIdFrom($response): ?string
    {
        $cookie = Collection::make($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

        return $cookie ? Crypt::decryptString($cookie->getValue()) : null;
    }

    public function test_valid_login_returns_identity_and_regenerates_the_session(): void
    {
        $user = User::factory()->create([
            'email' => 'Admin@BarberBooking.test',
            'password' => 'correct-horse-battery-staple',
        ]);

        $primeResponse = $this->get('/sanctum/csrf-cookie');
        $xsrf = $this->storeCsrfCookies($primeResponse);
        $sessionIdBefore = $this->sessionIdFrom($primeResponse);

        $response = $this->withHeaders($this->statefulHeaders($xsrf))
            ->postJson('/api/v1/admin/login', [
                'email' => 'admin@barberbooking.test',
                'password' => 'correct-horse-battery-staple',
            ]);

        $response->assertOk()->assertExactJson([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => 'admin@barberbooking.test',
            ],
        ]);

        $sessionIdAfter = $this->sessionIdFrom($response);
        $this->assertNotNull($sessionIdAfter);
        $this->assertNotSame($sessionIdBefore, $sessionIdAfter, 'Session ID must change (regenerate) on login.');
    }

    public function test_login_normalizes_email_case_and_whitespace(): void
    {
        User::factory()->create([
            'email' => 'admin@barberbooking.test',
            'password' => 'correct-horse-battery-staple',
        ]);

        $xsrf = $this->primeCsrf();

        $response = $this->withHeaders($this->statefulHeaders($xsrf))
            ->postJson('/api/v1/admin/login', [
                'email' => '  ADMIN@BarberBooking.TEST  ',
                'password' => 'correct-horse-battery-staple',
            ]);

        $response->assertOk();
    }

    public function test_wrong_password_returns_generic_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'admin@barberbooking.test',
            'password' => 'correct-horse-battery-staple',
        ]);

        $xsrf = $this->primeCsrf();

        $response = $this->withHeaders($this->statefulHeaders($xsrf))
            ->postJson('/api/v1/admin/login', [
                'email' => 'admin@barberbooking.test',
                'password' => 'wrong-password',
            ]);

        $response->assertStatus(401)->assertExactJson([
            'error' => [
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'E-mail ou senha inválidos.',
            ],
        ]);
    }

    public function test_nonexistent_email_returns_the_same_generic_message_as_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'admin@barberbooking.test',
            'password' => 'correct-horse-battery-staple',
        ]);

        $xsrf = $this->primeCsrf();

        $response = $this->withHeaders($this->statefulHeaders($xsrf))
            ->postJson('/api/v1/admin/login', [
                'email' => 'nobody-here@barberbooking.test',
                'password' => 'whatever',
            ]);

        // Same status and body as a wrong password for an existing e-mail —
        // nothing here should let a caller distinguish the two cases.
        $response->assertStatus(401)->assertExactJson([
            'error' => [
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'E-mail ou senha inválidos.',
            ],
        ]);
    }

    public function test_login_validates_required_fields(): void
    {
        $xsrf = $this->primeCsrf();

        $response = $this->withHeaders($this->statefulHeaders($xsrf))
            ->postJson('/api/v1/admin/login', []);

        // Our error contract nests validation errors under error.fields,
        // not Laravel's default top-level "errors" key, so assert that
        // shape directly rather than assertJsonValidationErrors().
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['fields' => ['email', 'password']]]);
    }

    public function test_me_without_a_session_is_unauthenticated_json(): void
    {
        $response = $this->withHeaders(['Referer' => self::STATEFUL_ORIGIN])
            ->getJson('/api/v1/admin/me');

        $response->assertStatus(401)->assertExactJson([
            'error' => [
                'code' => 'UNAUTHENTICATED',
                'message' => 'Autenticação necessária.',
            ],
        ]);
    }

    public function test_me_after_login_returns_the_authenticated_admin(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@barberbooking.test',
            'password' => 'correct-horse-battery-staple',
        ]);

        $xsrf = $this->primeCsrf();

        $this->withHeaders($this->statefulHeaders($xsrf))->postJson('/api/v1/admin/login', [
            'email' => 'admin@barberbooking.test',
            'password' => 'correct-horse-battery-staple',
        ])->assertOk();
        $this->forgetAuthGuards();

        $response = $this->withHeaders(['Referer' => self::STATEFUL_ORIGIN])->getJson('/api/v1/admin/me');

        $response->assertOk()->assertExactJson([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => 'admin@barberbooking.test',
            ],
        ]);
    }

    public function test_logout_invalidates_the_session_so_the_previous_cookie_is_rejected(): void
    {
        User::factory()->create([
            'email' => 'admin@barberbooking.test',
            'password' => 'correct-horse-battery-staple',
        ]);

        $xsrf = $this->primeCsrf();

        $login = $this->withHeaders($this->statefulHeaders($xsrf))->postJson('/api/v1/admin/login', [
            'email' => 'admin@barberbooking.test',
            'password' => 'correct-horse-battery-staple',
        ]);
        $login->assertOk();
        $this->forgetAuthGuards();

        // session()->regenerate() (called on login) also rotates the CSRF
        // token, so the XSRF cookie from priming is now stale — refresh it
        // from the login response, the same way a real SPA would pick up
        // the new Set-Cookie before its next request.
        $xsrf = $this->storeCsrfCookies($login);

        $this->withHeaders(['Referer' => self::STATEFUL_ORIGIN])
            ->getJson('/api/v1/admin/me')
            ->assertOk();
        $this->forgetAuthGuards();

        $logout = $this->withHeaders($this->statefulHeaders($xsrf))
            ->postJson('/api/v1/admin/logout');

        $logout->assertNoContent();
        $this->forgetAuthGuards();

        // Same cookie jar as before (withCookie() values persist on $this) —
        // the old session must no longer authenticate anything.
        $this->withHeaders(['Referer' => self::STATEFUL_ORIGIN])
            ->getJson('/api/v1/admin/me')
            ->assertStatus(401);
    }

    public function test_csrf_protection_rejects_a_missing_token_and_accepts_a_valid_one(): void
    {
        User::factory()->create([
            'email' => 'admin@barberbooking.test',
            'password' => 'correct-horse-battery-staple',
        ]);

        $xsrf = $this->primeCsrf();

        // Laravel's own CSRF middleware (PreventRequestForgery::handle())
        // skips verification entirely whenever runningUnitTests() is true —
        // i.e. whenever APP_ENV=testing, which phpunit.xml forces for this
        // whole suite (including in Docker — see the comment there). Without
        // this override, both assertions below would pass for the wrong
        // reason (CSRF never even runs) instead of proving the middleware
        // actually rejects/accepts based on the token. This override is
        // scoped to this one test; every other test in the suite still runs
        // under the normal APP_ENV=testing behavior.
        $this->app->instance('env', 'production');

        // 1) Valid session cookie present, but no X-XSRF-TOKEN header — the
        // stateful CSRF middleware (Sanctum's EnsureFrontendRequestsAreStateful
        // + ValidateCsrfToken) must reject this before it ever reaches the
        // controller, proving the middleware is active, not bypassed.
        $rejected = $this->withHeaders(['Referer' => self::STATEFUL_ORIGIN])
            ->postJson('/api/v1/admin/login', [
                'email' => 'admin@barberbooking.test',
                'password' => 'correct-horse-battery-staple',
            ]);

        $rejected->assertStatus(419)
            ->assertJsonPath('error.code', 'SESSION_EXPIRED')
            ->assertJsonMissingPath('trace');

        // 2) Same session, this time with the valid X-XSRF-TOKEN header the
        // priming request issued — must be accepted. Proves the middleware
        // discriminates on the token itself, not just rejecting everything.
        $accepted = $this->withHeaders($this->statefulHeaders($xsrf))
            ->postJson('/api/v1/admin/login', [
                'email' => 'admin@barberbooking.test',
                'password' => 'correct-horse-battery-staple',
            ]);

        $accepted->assertOk();
    }

    public function test_login_throttles_by_ip_and_normalized_email(): void
    {
        $limit = (int) config('admin_auth.login_throttle.per_email');
        $this->assertGreaterThan(0, $limit, 'Test requires a positive, forced per-email limit from phpunit.xml.');

        User::factory()->create([
            'email' => 'admin@barberbooking.test',
            'password' => 'correct-horse-battery-staple',
        ]);

        $xsrf = $this->primeCsrf();

        for ($i = 0; $i < $limit; $i++) {
            $this->withHeaders($this->statefulHeaders($xsrf))
                ->postJson('/api/v1/admin/login', [
                    'email' => 'admin@barberbooking.test',
                    'password' => 'wrong-password',
                ])
                ->assertStatus(401);
        }

        $blocked = $this->withHeaders($this->statefulHeaders($xsrf))
            ->postJson('/api/v1/admin/login', [
                'email' => 'admin@barberbooking.test',
                'password' => 'wrong-password',
            ]);

        $blocked->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertHeader('Retry-After');
    }

    public function test_login_throttles_by_ip_alone_across_different_emails(): void
    {
        $perIp = (int) config('admin_auth.login_throttle.per_ip');
        $this->assertGreaterThan(0, $perIp, 'Test requires a positive, forced per-IP limit from phpunit.xml.');

        $xsrf = $this->primeCsrf();

        // Each e-mail is distinct and only tried once, so no individual
        // per-email counter trips — only the shared per-IP counter can.
        for ($i = 0; $i < $perIp; $i++) {
            $this->withHeaders($this->statefulHeaders($xsrf))
                ->postJson('/api/v1/admin/login', [
                    'email' => "nobody-{$i}@barberbooking.test",
                    'password' => 'whatever',
                ])
                ->assertStatus(401);
        }

        $blocked = $this->withHeaders($this->statefulHeaders($xsrf))
            ->postJson('/api/v1/admin/login', [
                'email' => 'one-more-new-address@barberbooking.test',
                'password' => 'whatever',
            ]);

        $blocked->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertHeader('Retry-After');
    }
}
