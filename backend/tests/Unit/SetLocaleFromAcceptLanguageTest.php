<?php

namespace Tests\Unit;

use App\Http\Middleware\SetLocaleFromAcceptLanguage;
use Illuminate\Http\Request;
use Tests\TestCase;

class SetLocaleFromAcceptLanguageTest extends TestCase
{
    public function test_falls_back_to_pt_br_when_no_header_is_present(): void
    {
        $this->handleWith(null);

        $this->assertSame('pt_BR', app()->getLocale());
    }

    public function test_falls_back_to_pt_br_for_an_unsupported_language(): void
    {
        $this->handleWith('fr-FR,fr;q=0.9');

        $this->assertSame('pt_BR', app()->getLocale());
    }

    public function test_accepts_en(): void
    {
        $this->handleWith('en');

        $this->assertSame('en', app()->getLocale());
    }

    public function test_accepts_pt_br_with_a_hyphen_case_insensitively(): void
    {
        $this->handleWith('PT-br');

        $this->assertSame('pt_BR', app()->getLocale());
    }

    public function test_picks_the_first_supported_tag_in_a_weighted_list(): void
    {
        $this->handleWith('fr;q=0.9,en;q=0.5');

        $this->assertSame('en', app()->getLocale());
    }

    private function handleWith(?string $acceptLanguage): void
    {
        $request = Request::create('/api/v1/admin/me', 'GET');

        if ($acceptLanguage !== null) {
            $request->headers->set('Accept-Language', $acceptLanguage);
        } else {
            // Request::create() itself defaults this header to
            // "en-us,en;q=0.5" (a Symfony HttpFoundation test convenience)
            // when none is given — remove it to truly simulate a request
            // with no Accept-Language at all.
            $request->headers->remove('Accept-Language');
        }

        (new SetLocaleFromAcceptLanguage)->handle($request, fn () => response()->json());
    }
}
