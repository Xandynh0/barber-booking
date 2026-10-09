<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Selects the locale for THIS request only, from the `Accept-Language`
 * header the frontend sends on every call (frontend/src/api/client.js) —
 * never persisted (no session/cookie/global state), so it can't leak
 * between requests or users. Only `pt_BR` and `en` are supported; anything
 * else (missing header, unsupported language, a full browser-style
 * "pt-BR,pt;q=0.9,en;q=0.8" list with no supported entry) falls back to
 * `pt_BR` — the project's default audience.
 */
class SetLocaleFromAcceptLanguage
{
    private const SUPPORTED = [
        'pt-br' => 'pt_BR',
        'pt' => 'pt_BR',
        'en' => 'en',
        'en-us' => 'en',
    ];

    private const DEFAULT_LOCALE = 'pt_BR';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolveLocale($request->header('Accept-Language')));

        return $next($request);
    }

    private function resolveLocale(?string $header): string
    {
        if ($header === null) {
            return self::DEFAULT_LOCALE;
        }

        // A header can list several weighted tags ("pt-BR,pt;q=0.9,en;q=0.8");
        // take them in order and use the first one this app supports.
        foreach (explode(',', $header) as $tag) {
            $normalized = strtolower(trim(explode(';', $tag)[0]));

            if (isset(self::SUPPORTED[$normalized])) {
                return self::SUPPORTED[$normalized];
            }
        }

        return self::DEFAULT_LOCALE;
    }
}
