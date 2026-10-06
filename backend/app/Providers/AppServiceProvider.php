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

    private function rateLimitedResponse(array $headers): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'RATE_LIMITED',
                'message' => 'Muitas tentativas. Tente novamente em instantes.',
            ],
        ], 429, $headers);
    }
}
