<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureAdminLoginRateLimiting();
        $this->configurePublicAvailabilityRateLimiting();
    }

    /**
     * Two independent limits on POST /api/v1/admin/login, both counting
     * failed attempts: tight per IP+e-mail, looser per IP alone.
     */
    private function configureAdminLoginRateLimiting(): void
    {
        RateLimiter::for('admin-login-email', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email')));

            return Limit::perMinute((int) config('admin_auth.login_throttle.per_email'))
                ->by($request->ip().'|'.$email)
                ->response(fn (Request $request, array $headers) => $this->rateLimitedResponse($headers));
        });

        RateLimiter::for('admin-login-ip', function (Request $request) {
            return Limit::perMinute((int) config('admin_auth.login_throttle.per_ip'))
                ->by($request->ip())
                ->response(fn (Request $request, array $headers) => $this->rateLimitedResponse($headers));
        });
    }

    /**
     * The public routes are unauthenticated, so they get per-IP ceilings.
     * GET /api/v1/public/availability is unauthenticated, so it gets a
     * per-IP ceiling: generous for a person browsing dates, low enough to
     * stop scraping the whole horizon in a loop.
     */
    private function configurePublicAvailabilityRateLimiting(): void
    {
        RateLimiter::for('public-availability', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->ip())
                ->response(fn (Request $request, array $headers) => $this->rateLimitedResponse($headers));
        });

        // Creating a reservation is the expensive, abuse-prone path (it takes
        // the global lock): far tighter than browsing availability. Replays
        // of the same Idempotency-Key count too — a client retrying more
        // than this per minute is misbehaving anyway.
        RateLimiter::for('public-appointments', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->ip())
                ->response(fn (Request $request, array $headers) => $this->rateLimitedResponse($headers));
        });

        // The signed cancellation pages are HTML, not JSON: no custom
        // response here — bootstrap/app.php renders the 429 as a page.
        RateLimiter::for('public-cancellation', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });
    }

    private function rateLimitedResponse(array $headers): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'RATE_LIMITED',
                'message' => __('errors.rate_limited'),
            ],
        ], 429, $headers);
    }
}
