<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Http\Middleware\SetOpsLocale;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SetOpsLocaleMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', SetOpsLocale::class])
            ->get('/ops/test-locale', function () {
                return response()->json([
                    'locale' => app()->getLocale(),
                    'session' => (string) session(SetOpsLocale::SESSION_KEY, ''),
                ]);
            });
    }

    public function test_locale_query_parameter_is_applied(): void
    {
        $this->get('/ops/test-locale?locale=en')
            ->assertOk()
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('session', 'en');
    }

    public function test_invalid_lang_falls_back_to_default_locale(): void
    {
        $this->get('/ops/test-locale?lang=xxx')
            ->assertOk()
            ->assertJsonPath('locale', SetOpsLocale::DEFAULT_LOCALE)
            ->assertJsonPath('session', SetOpsLocale::DEFAULT_LOCALE);
    }

    public function test_cookie_locale_is_used_when_query_missing(): void
    {
        $this->withCookie(SetOpsLocale::COOKIE_KEY, 'en')
            ->get('/ops/test-locale')
            ->assertOk()
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('session', 'en');
    }
}

