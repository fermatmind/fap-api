<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\OpsLogin;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

final class OpsLoginPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_ops_login_page_disables_browser_validation_and_uses_browser_friendly_autocomplete(): void
    {
        config()->set('admin.panel_enabled', true);

        $this->get('/ops/login')
            ->assertOk()
            ->assertSee('wire:submit="authenticate"', false)
            ->assertSee('novalidate', false)
            ->assertSee('autocomplete="username"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertSee("\$wire.set('data.email', emailInput.value)", false)
            ->assertSee("\$wire.set('data.password', passwordInput.value)", false)
            ->assertSee('window.__opsLivewirePageExpiredRecoveryHookInstalled', false)
            ->assertSee("const autoRefreshStorageKeyPrefix = 'ops-livewire-page-expired-at:'", false)
            ->assertSee("window.Livewire?.hook('request'", false)
            ->assertSee("if (status !== 419 || ! pathname.startsWith('/ops'))", false)
            ->assertSee('window.location.reload()', false);
    }

    public function test_ops_login_livewire_rate_limit_key_is_scoped_by_identifier(): void
    {
        $request = Request::create('/livewire/update', 'POST', [], [], [], [
            'REMOTE_ADDR' => '8.8.8.8',
            'HTTP_HOST' => 'ops.example.test',
        ]);
        $this->app->instance('request', $request);

        $aliceLogin = $this->newOpsLoginProbe();
        $aliceLogin->data = ['email' => 'alice@example.test'];

        $bobLogin = $this->newOpsLoginProbe();
        $bobLogin->data = ['email' => 'bob@example.test'];

        $this->assertNotSame($aliceLogin->exposeRateLimitKey(), $bobLogin->exposeRateLimitKey());
    }

    public function test_ops_login_livewire_source_rate_limit_key_is_scoped_to_ip_not_identifier(): void
    {
        $this->bindLivewireLoginRequest('8.8.8.8');

        $aliceLogin = $this->newOpsLoginProbe();
        $aliceLogin->data = ['email' => 'alice@example.test'];

        $bobLogin = $this->newOpsLoginProbe();
        $bobLogin->data = ['email' => 'bob@example.test'];

        $sourceKey = $aliceLogin->exposeRateLimitKey('authenticateSource');

        $this->assertSame(
            $sourceKey,
            $bobLogin->exposeRateLimitKey('authenticateSource'),
        );

        $this->bindLivewireLoginRequest('9.9.9.9');

        $otherSourceLogin = $this->newOpsLoginProbe();
        $otherSourceLogin->data = ['email' => 'alice@example.test'];

        $this->assertNotSame(
            $sourceKey,
            $otherSourceLogin->exposeRateLimitKey('authenticateSource'),
        );
    }

    public function test_ops_login_livewire_blocks_rotating_identifiers_on_same_source_bucket(): void
    {
        $this->bindLivewireLoginRequest('8.8.8.8');

        $login = $this->newOpsLoginProbe();
        $sourceKey = null;

        for ($i = 0; $i < 5; $i++) {
            $login->data = ['email' => 'rotated-'.$i.'@example.test'];
            $sourceKey ??= $login->exposeRateLimitKey('authenticateSource');

            $this->assertSame($sourceKey, $login->exposeRateLimitKey('authenticateSource'));
            $login->hitRateLimit('authenticateSource');
        }

        $blocked = false;
        $login->data = ['email' => 'fresh@example.test'];

        try {
            $login->hitRateLimit('authenticateSource');
        } catch (TooManyRequestsException) {
            $blocked = true;
        }

        $this->assertTrue($blocked);

        if ($sourceKey !== null) {
            RateLimiter::clear($sourceKey);
        }
    }

    public function test_ops_login_authenticate_stops_rotated_identifier_when_source_bucket_is_exhausted(): void
    {
        $this->bindLivewireLoginRequest('8.8.8.8');

        $login = $this->newOpsLoginProbe();
        $login->data = ['email' => 'first@example.test'];
        $sourceKey = $login->exposeRateLimitKey('authenticateSource');

        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit($sourceKey, 60);
        }

        $login->data = ['email' => 'fresh@example.test'];

        $this->assertNull($login->authenticate());
        $this->assertFalse($login->traceStarted);

        RateLimiter::clear($sourceKey);
    }

    public function test_ops_login_livewire_source_bucket_does_not_globally_lock_other_sources(): void
    {
        $this->bindLivewireLoginRequest('8.8.8.8');

        $blockedSourceLogin = $this->newOpsLoginProbe();
        $blockedSourceLogin->data = ['email' => 'target@example.test'];
        $blockedSourceKey = $blockedSourceLogin->exposeRateLimitKey('authenticateSource');

        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit($blockedSourceKey, 60);
        }

        $this->bindLivewireLoginRequest('9.9.9.9');

        $otherSourceLogin = $this->newOpsLoginProbe();
        $otherSourceLogin->data = ['email' => 'owner@example.test'];
        $otherSourceKey = $otherSourceLogin->exposeRateLimitKey('authenticateSource');

        $this->assertNotSame($blockedSourceKey, $otherSourceKey);
        $otherSourceLogin->hitRateLimit('authenticateSource');
        $this->assertSame(1, RateLimiter::attempts($otherSourceKey));

        RateLimiter::clear($blockedSourceKey);
        RateLimiter::clear($otherSourceKey);
    }

    public function test_ops_login_livewire_valid_attempt_path_can_hit_source_and_identifier_buckets(): void
    {
        $this->bindLivewireLoginRequest('8.8.8.8');

        $login = $this->newOpsLoginProbe();
        $login->data = ['email' => 'ops@example.test'];

        $sourceKey = $login->exposeRateLimitKey('authenticateSource');
        $identifierKey = $login->exposeRateLimitKey('authenticate');

        $login->hitRateLimit('authenticateSource');
        $login->hitRateLimit('authenticate');

        $this->assertSame(1, RateLimiter::attempts($sourceKey));
        $this->assertSame(1, RateLimiter::attempts($identifierKey));

        RateLimiter::clear($sourceKey);
        RateLimiter::clear($identifierKey);
    }

    private function bindLivewireLoginRequest(string $ip): void
    {
        $request = Request::create('/livewire/update', 'POST', [], [], [], [
            'REMOTE_ADDR' => $ip,
            'HTTP_HOST' => 'ops.example.test',
        ]);

        $this->app->instance('request', $request);
    }

    private function newOpsLoginProbe()
    {
        return new class extends OpsLogin
        {
            public bool $traceStarted = false;

            public function exposeRateLimitKey(?string $method = 'authenticate'): string
            {
                return $this->getRateLimitKey($method);
            }

            public function hitRateLimit(?string $method = 'authenticate'): void
            {
                $this->rateLimit(5, 60, $method);
            }

            protected function getRateLimitedNotification(TooManyRequestsException $exception): ?Notification
            {
                return null;
            }

            protected function startTrace(array $trace): void
            {
                $this->traceStarted = true;
            }
        };
    }
}
