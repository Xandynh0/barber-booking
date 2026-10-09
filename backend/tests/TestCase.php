<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Symfony's HttpFoundation `Request::create()` — what Laravel's test
     * HTTP helpers (getJson/postJson/...) use under the hood — defaults
     * the `Accept-Language` header to `"en-us,en;q=0.5"` when the test
     * doesn't set one itself. Left alone, every test would silently
     * exercise the English locale instead of this app's own default
     * (`pt_BR`, see SetLocaleFromAcceptLanguage), which is backwards from
     * what real traffic looks like (browsers always send a real header).
     * Tests that specifically need another language override this with
     * `->withHeaders(['Accept-Language' => '...'])`.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders(['Accept-Language' => 'pt-BR']);
    }
}
