<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers for the signed cancellation pages (docs/planejamento-barbearia-
 * mvp.md, seção 2): the URL carries a signature, so it must not leak through
 * Referer, caches or search engines, and the page runs no third-party (or
 * any) script. Registered first in the route group so it also covers the
 * error pages rendered for an invalid/expired signature.
 */
class CancellationPageHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
        );

        return $response;
    }
}
