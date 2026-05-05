<?php

namespace App\Providers;

use App\Contracts\Cms\ArticleMachineTranslationProvider;
use App\Contracts\Cms\CmsMachineTranslationProvider;
use App\Contracts\Security\PiiEnvelopeAdapter;
use App\Livewire\Filament\Ops\Livewire\CurrentOrgSwitcher;
use App\Livewire\Filament\Ops\Livewire\LocaleSwitcher;
use App\Models\AdminApproval;
use App\Models\Attempt;
use App\Models\BenefitGrant;
use App\Models\Order;
use App\Models\PaymentEvent;
use App\Models\ReportSnapshot;
use App\Models\ScaleRegistry;
use App\Models\ScaleSlug;
use App\Models\Share;
use App\Policies\AdminApprovalPolicy;
use App\Policies\AttemptPolicy;
use App\Policies\BenefitGrantPolicy;
use App\Policies\OrderPolicy;
use App\Policies\PaymentEventPolicy;
use App\Policies\ReportSnapshotPolicy;
use App\Policies\ScaleRegistryPolicy;
use App\Policies\ScaleSlugPolicy;
use App\Policies\SharePolicy;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2CompatibilityTransformer;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2TransformerContract;
use App\Services\Cms\ArticleCmsMachineTranslationProvider;
use App\Services\Cms\CmsMachineTranslationProviderRegistry;
use App\Services\Cms\DisabledArticleMachineTranslationProvider;
use App\Services\Cms\DisabledCmsMachineTranslationProvider;
use App\Services\Cms\OpenAiArticleMachineTranslationProvider;
use App\Services\Cms\OpenAiCmsMachineTranslationProvider;
use App\Services\Content\ContentPack;
use App\Services\Content\ContentPacksIndex;
use App\Services\Content\ContentStore;
use App\Services\ContentPackResolver;
use App\Services\Ops\OpsDistributedLimiter;
use App\Support\Logging\RedactProcessor;
use App\Support\OrgContext;
use App\Support\Security\ExternalKmsPiiEnvelopeAdapter;
use App\Support\Security\LocalPiiEnvelopeAdapter;
use Filament\Support\Assets\Theme;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    private static bool $redactProcessorRegistered = false;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(OrgContext::class, static fn (): OrgContext => new OrgContext);

        $this->app->singleton(
            ArticleMachineTranslationProvider::class,
            static function ($app): ArticleMachineTranslationProvider {
                $provider = strtolower(trim((string) config('services.article_translation.provider', 'disabled')));

                return match ($provider) {
                    'openai' => $app->make(OpenAiArticleMachineTranslationProvider::class),
                    default => $app->make(DisabledArticleMachineTranslationProvider::class),
                };
            },
        );

        $this->app->singleton(DisabledCmsMachineTranslationProvider::class);
        $this->app->singleton(ArticleCmsMachineTranslationProvider::class);
        $this->app->singleton(OpenAiCmsMachineTranslationProvider::class);
        $this->app->singleton(OpenAiArticleMachineTranslationProvider::class);
        $this->app->singleton(CmsMachineTranslationProviderRegistry::class);
        $this->app->bind(CmsMachineTranslationProvider::class, static fn ($app): CmsMachineTranslationProvider => $app->make(DisabledCmsMachineTranslationProvider::class));
        $this->app->bind(BigFiveResultPageV2TransformerContract::class, BigFiveResultPageV2CompatibilityTransformer::class);

        $this->app->singleton(PiiEnvelopeAdapter::class, function ($app) {
            $adapterRaw = strtolower(trim((string) config('services.pii.adapter', 'local')));
            $adapter = match ($adapterRaw) {
                'external-kms', 'kms' => 'external_kms',
                default => $adapterRaw,
            };

            return match ($adapter) {
                'local' => $app->make(LocalPiiEnvelopeAdapter::class),
                'external_kms' => $app->make(ExternalKmsPiiEnvelopeAdapter::class),
                default => throw new RuntimeException(
                    "Unsupported services.pii.adapter [{$adapter}] (allowed: local, external_kms)"
                ),
            };
        });

        // Bind ContentPackResolver so app(ContentPackResolver::class) works everywhere.
        $this->app->singleton(ContentPackResolver::class, function () {
            return new ContentPackResolver;
        });

        // Bind ContentStore so app(ContentStore::class) works without hardcoded default scale.
        $this->app->singleton(ContentStore::class, function ($app) {
            /** @var ContentPackResolver $resolver */
            $resolver = $app->make(ContentPackResolver::class);
            /** @var ContentPacksIndex $packsIndex */
            $packsIndex = $app->make(ContentPacksIndex::class);

            $request = $app->bound('request') ? $app->make('request') : null;

            $attempt = null;
            $attemptId = '';
            $packId = '';
            $dirVersion = '';
            $contentPackageVersion = '';
            $scaleCode = '';
            $region = '';
            $locale = '';
            $orgId = 0;

            if ($request instanceof Request) {
                $attemptId = (string) (
                    $request->route('attempt_id')
                    ?? $request->route('id')
                    ?? $request->input('attempt_id')
                    ?? $request->header('X-Attempt-Id')
                    ?? ''
                );

                $packId = (string) (
                    $request->route('pack_id')
                    ?? $request->query('pack_id')
                    ?? $request->input('pack_id')
                    ?? $request->header('X-Pack-Id')
                    ?? ''
                );

                $dirVersion = (string) (
                    $request->route('dir_version')
                    ?? $request->query('dir_version')
                    ?? $request->input('dir_version')
                    ?? $request->header('X-Dir-Version')
                    ?? ''
                );

                $scaleCode = (string) (
                    $request->query('scale_code')
                    ?? $request->input('scale_code')
                    ?? $request->header('X-Scale-Code')
                    ?? ''
                );

                $region = (string) (
                    $request->query('region')
                    ?? $request->input('region')
                    ?? $request->header('X-Region')
                    ?? ''
                );

                $locale = (string) (
                    $request->query('locale')
                    ?? $request->input('locale')
                    ?? $request->header('X-Locale')
                    ?? ''
                );

                $orgId = (int) ($request->attributes->get(
                    'org_id',
                    $request->attributes->get('fm_org_id', 0)
                ) ?? 0);
            }

            if ($orgId <= 0) {
                $orgId = (int) $app->make(OrgContext::class)->orgId();
            }
            $orgId = max(0, $orgId);

            if ($attemptId !== '') {
                $attempt = Attempt::withoutGlobalScopes()
                    ->where('id', $attemptId)
                    ->where('org_id', $orgId)
                    ->first();
            }

            if ($attempt) {
                $packId = (string) ($attempt->pack_id ?? $packId);
                $dirVersion = (string) ($attempt->dir_version ?? $dirVersion);
                $contentPackageVersion = (string) ($attempt->content_package_version ?? '');
                $scaleCode = (string) ($attempt->scale_code ?? $scaleCode);
                $region = (string) ($attempt->region ?? $region);
                $locale = (string) ($attempt->locale ?? $locale);
            }

            if ($packId === '') {
                $packId = (string) config('content_packs.default_pack_id', '');
            }
            if ($dirVersion === '') {
                $dirVersion = (string) config('content_packs.default_dir_version', '');
            }

            if ($packId !== '' && $dirVersion !== '') {
                $found = $packsIndex->find($packId, $dirVersion);
                if ($found['ok'] ?? false) {
                    $item = $found['item'] ?? [];
                    if ($contentPackageVersion === '') {
                        $contentPackageVersion = (string) ($item['content_package_version'] ?? '');
                    }
                    if ($scaleCode === '') {
                        $scaleCode = (string) ($item['scale_code'] ?? '');
                    }
                    if ($region === '') {
                        $region = (string) ($item['region'] ?? '');
                    }
                    if ($locale === '') {
                        $locale = (string) ($item['locale'] ?? '');
                    }
                }
            }

            $extractVersion = function (string $raw): string {
                $raw = trim($raw);
                if ($raw === '') {
                    return '';
                }

                if (substr_count($raw, '.') >= 3) {
                    $parts = explode('.', $raw);

                    return (string) implode('.', array_slice($parts, 3));
                }

                $pos = strripos($raw, '-v');
                if ($pos !== false) {
                    return substr($raw, $pos + 1);
                }

                if (str_starts_with($raw, 'v')) {
                    return $raw;
                }

                return '';
            };

            if ($contentPackageVersion === '') {
                $contentPackageVersion = $extractVersion($dirVersion);
            }
            if ($contentPackageVersion === '') {
                $contentPackageVersion = $extractVersion($packId);
            }

            if ($region === '') {
                $region = (string) config('content_packs.default_region', 'GLOBAL');
            }
            if ($locale === '') {
                $locale = (string) config('content_packs.default_locale', 'en');
            }
            if ($scaleCode === '' && $packId !== '') {
                $scaleCode = (string) strtok($packId, '.');
            }
            if ($scaleCode === '') {
                $scaleCode = 'MBTI';
            }

            $resolved = $resolver->resolve(
                $scaleCode,
                $region,
                $locale,
                (string) $contentPackageVersion,
                $dirVersion
            );

            $makePack = function (array $manifest, string $baseDir): ContentPack {
                return new ContentPack(
                    packId: (string) ($manifest['pack_id'] ?? ''),
                    scaleCode: (string) ($manifest['scale_code'] ?? ''),
                    region: (string) ($manifest['region'] ?? ''),
                    locale: (string) ($manifest['locale'] ?? ''),
                    version: (string) ($manifest['content_package_version'] ?? ''),
                    basePath: $baseDir,
                    manifest: $manifest,
                );
            };

            $chain = [];
            $chain[] = $makePack($resolved->manifest ?? [], (string) ($resolved->baseDir ?? ''));

            $fallbacks = is_array($resolved->fallbackChain ?? null) ? $resolved->fallbackChain : [];
            foreach ($fallbacks as $fb) {
                if (! is_array($fb)) {
                    continue;
                }
                $manifest = is_array($fb['manifest'] ?? null) ? $fb['manifest'] : [];
                $baseDir = (string) ($fb['base_dir'] ?? '');
                if ($manifest && $baseDir !== '') {
                    $chain[] = $makePack($manifest, $baseDir);
                }
            }

            $legacyDir = $dirVersion !== '' ? $dirVersion : basename((string) ($resolved->baseDir ?? ''));

            return new ContentStore($chain, [], $legacyDir);
        });

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        FilamentAsset::register([
            Theme::make('ops-theme', resource_path('css/filament/ops/theme.compiled.css')),
        ]);

        Blade::anonymousComponentPath(resource_path('views/filament/ops/components'), 'filament-ops');

        Gate::policy(Attempt::class, AttemptPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(PaymentEvent::class, PaymentEventPolicy::class);
        Gate::policy(BenefitGrant::class, BenefitGrantPolicy::class);
        Gate::policy(AdminApproval::class, AdminApprovalPolicy::class);
        Gate::policy(ReportSnapshot::class, ReportSnapshotPolicy::class);
        Gate::policy(Share::class, SharePolicy::class);
        Gate::policy(ScaleRegistry::class, ScaleRegistryPolicy::class);
        Gate::policy(ScaleSlug::class, ScaleSlugPolicy::class);
        Livewire::component('filament.ops.livewire.current-org-switcher', CurrentOrgSwitcher::class);
        Livewire::component('filament.ops.livewire.locale-switcher', LocaleSwitcher::class);

        if ($this->app->runningInConsole() && $this->app->environment('production')) {
            $argv = $_SERVER['argv'] ?? [];
            $command = is_array($argv) ? trim((string) ($argv[1] ?? '')) : '';
            $blockedCommands = ['migrate:rollback', 'migrate:reset', 'migrate:refresh'];
            if (in_array($command, $blockedCommands, true)) {
                throw new RuntimeException('rollback disabled in production: '.$command);
            }
        }

        if (! self::$redactProcessorRegistered) {
            $processor = new RedactProcessor;
            Log::getLogger()->pushProcessor(static fn (array|\Monolog\LogRecord $record): array|\Monolog\LogRecord => $processor($record));

            self::$redactProcessorRegistered = true;
        }

        $isDebugEnabledByEnv = (bool) config('app.debug', false);

        if ($this->app->environment('production') && $isDebugEnabledByEnv) {
            Log::emergency('CRITICAL: PRODUCTION_APP_DEBUG_TRUE');
        }

        $this->registerSlowQueryTelemetry();

        $resolveRequestId = function (Request $request): string {
            $requestId = trim((string) ($request->attributes->get('request_id') ?? ''));
            if ($requestId !== '') {
                return $requestId;
            }

            $requestId = trim((string) $request->header('X-Request-Id', ''));
            if ($requestId !== '') {
                return $requestId;
            }

            $requestId = trim((string) $request->header('X-Request-ID', ''));
            if ($requestId !== '') {
                return $requestId;
            }

            return (string) \Illuminate\Support\Str::uuid();
        };

        $scopedRateKey = function (Request $request, string $scope): string {
            $ip = (string) ($request->ip() ?? 'unknown');
            $orgId = (int) ($request->attributes->get('org_id', $request->attributes->get('fm_org_id', 0)) ?? 0);
            $route = (string) (($request->route()?->uri()) ?? $request->path());

            return implode('|', [
                $scope,
                'ip:'.$ip,
                'org:'.max(0, $orgId),
                'route:'.$route,
            ]);
        };

        $response = function (string $code, string $message) use ($resolveRequestId) {
            return function (Request $request, array $headers) use ($code, $message, $resolveRequestId) {
                return response()->json([
                    'ok' => false,
                    'error_code' => $code,
                    'message' => $message,
                    'details' => null,
                    'request_id' => $resolveRequestId($request),
                ], 429)->withHeaders($headers);
            };
        };

        // Disable throttles in test-like environments by default to prevent cross-test 429 flakiness.
        // Dedicated rate-limit tests can opt in by setting fap.rate_limits.bypass_in_test_env=false.
        $shouldBypassRateLimits = function (): bool {
            if ($this->app->runningUnitTests()) {
                return (bool) config('fap.rate_limits.bypass_in_test_env', true);
            }

            if (! $this->app->environment(['testing', 'ci'])) {
                return false;
            }

            return (bool) config('fap.rate_limits.bypass_in_test_env', true);
        };

        RateLimiter::for('api_public', function (Request $request) use ($response, $shouldBypassRateLimits) {
            if ($shouldBypassRateLimits()) {
                return Limit::none();
            }

            $limit = (int) config('fap.rate_limits.api_public_per_minute', 120);
            $limit = max(1, $limit);

            return Limit::perMinute($limit)
                ->by('ip:'.$request->ip())
                ->response($response('RATE_LIMIT_PUBLIC', 'Too many requests. Please retry later.'));
        });

        RateLimiter::for('api_auth', function (Request $request) use ($response, $shouldBypassRateLimits, $scopedRateKey) {
            if ($shouldBypassRateLimits()) {
                return Limit::none();
            }

            $limit = (int) config('fap.rate_limits.api_auth_per_minute', 30);
            $limit = max(1, $limit);

            return Limit::perMinute($limit)
                ->by($scopedRateKey($request, 'api_auth'))
                ->response($response('RATE_LIMIT_AUTH', 'Too many auth requests. Please retry later.'));
        });

        RateLimiter::for('api_order_lookup', function (Request $request) use ($response, $shouldBypassRateLimits, $scopedRateKey) {
            if ($shouldBypassRateLimits()) {
                return Limit::none();
            }

            $limit = (int) config('fap.rate_limits.api_order_lookup_per_minute', 20);
            $limit = max(1, $limit);

            return Limit::perMinute($limit)
                ->by($scopedRateKey($request, 'api_order_lookup'))
                ->response($response('RATE_LIMIT_ORDER_LOOKUP', 'Too many order lookup requests. Please retry later.'));
        });

        RateLimiter::for('api_result_lookup', function (Request $request) use ($response, $shouldBypassRateLimits, $scopedRateKey) {
            if ($shouldBypassRateLimits()) {
                return Limit::none();
            }

            $limit = (int) config('fap.rate_limits.api_result_lookup_per_minute', 20);
            $limit = max(1, $limit);

            return Limit::perMinute($limit)
                ->by($scopedRateKey($request, 'api_result_lookup'))
                ->response($response('RATE_LIMIT_RESULT_LOOKUP', 'Too many result lookup requests. Please retry later.'));
        });

        RateLimiter::for('api_track', function (Request $request) use ($response, $shouldBypassRateLimits, $scopedRateKey) {
            if ($shouldBypassRateLimits()) {
                return Limit::none();
            }

            $limit = (int) config('fap.rate_limits.api_track_per_minute', 60);
            $limit = max(1, $limit);

            return Limit::perMinute($limit)
                ->by($scopedRateKey($request, 'api_track'))
                ->response($response('RATE_LIMIT_TRACK', 'Too many tracking requests. Please retry later.'));
        });

        $attemptRateLimitKey = function (Request $request): string {
            $userId = (string) ($request->attributes->get('fm_user_id') ?? '');
            if ($userId === '' && $request->user()) {
                $userId = (string) $request->user()->getAuthIdentifier();
            }

            return $userId !== '' ? ('user:'.$userId) : ('ip:'.$request->ip());
        };

        RateLimiter::for('api_attempt_start', function (Request $request) use ($response, $shouldBypassRateLimits, $attemptRateLimitKey) {
            if ($shouldBypassRateLimits()) {
                return Limit::none();
            }

            $limit = (int) config('fap.rate_limits.api_attempt_start_per_minute', 60);
            $limit = max(1, $limit);

            return Limit::perMinute($limit)
                ->by($attemptRateLimitKey($request))
                ->response($response('RATE_LIMIT_ATTEMPT_START', 'Too many attempt start requests. Please retry later.'));
        });

        RateLimiter::for('api_attempt_submit', function (Request $request) use ($response, $shouldBypassRateLimits, $attemptRateLimitKey) {
            if ($shouldBypassRateLimits()) {
                return Limit::none();
            }

            $limit = (int) config('fap.rate_limits.api_attempt_submit_per_minute', 20);
            $limit = max(1, $limit);

            return Limit::perMinute($limit)
                ->by($attemptRateLimitKey($request))
                ->response($response('RATE_LIMIT_ATTEMPT_SUBMIT', 'Too many attempt submissions. Please retry later.'));
        });

        RateLimiter::for('api_webhook', function (Request $request) use ($response, $shouldBypassRateLimits, $scopedRateKey) {
            if ($shouldBypassRateLimits()) {
                return Limit::none();
            }

            $limit = (int) config('fap.rate_limits.api_webhook_per_minute', 60);
            $limit = max(1, $limit);

            $provider = (string) $request->route('provider', '');
            if ($provider === '') {
                $provider = (string) $request->route('provider_code', '');
            }

            $scope = 'api_webhook:'.($provider !== '' ? $provider : 'unknown');
            $key = $scopedRateKey($request, $scope);

            return Limit::perMinute($limit)
                ->by($key)
                ->response($response('RATE_LIMIT_WEBHOOK', 'Too many webhook requests. Please retry later.'));
        });

        Event::listen(Failed::class, function (Failed $event): void {
            if ((string) $event->guard !== (string) config('admin.guard', 'admin')) {
                return;
            }

            $ip = request()?->ip() ?? 'unknown';
            $email = trim((string) (request()?->input('email') ?? ''));
            $key = 'ops:admin-login:'.$ip;
            $decay = max(60, (int) config('ops.security.admin_login_decay_seconds', 300));
            $maxAttempts = max(1, (int) config('ops.security.admin_login_max_attempts', 5));
            RateLimiter::hit($key, $decay);

            if (\App\Support\SchemaBaseline::hasTable('admin_users') && $email !== '') {
                $failedCount = ((int) DB::table('admin_users')->where('email', $email)->value('failed_login_count')) + 1;
                $updates = [
                    'failed_login_count' => $failedCount,
                    'updated_at' => now(),
                ];

                if ($failedCount >= $maxAttempts) {
                    $updates['locked_until'] = now()->addSeconds($decay);
                }

                DB::table('admin_users')
                    ->where('email', $email)
                    ->update($updates);
            }

            Log::warning('OPS_ADMIN_LOGIN_FAILED', [
                'ip' => $ip,
                'email' => $email !== '' ? $email : null,
                'attempts' => RateLimiter::attempts($key),
            ]);
        });

        Event::listen(Login::class, function (Login $event): void {
            if ((string) $event->guard !== (string) config('admin.guard', 'admin')) {
                return;
            }

            $ip = request()?->ip() ?? 'unknown';
            $key = 'ops:admin-login:'.$ip;
            RateLimiter::clear($key);

            $identifier = mb_strtolower(trim((string) (request()?->input('email') ?? request()?->input('username') ?? '')));
            if ($identifier === '') {
                $identifier = 'anonymous:'.$ip;
            }
            OpsDistributedLimiter::clear('ops:login:ip:'.$ip);
            OpsDistributedLimiter::clear('ops:login:user:'.$identifier);

            $user = $event->user;
            if (is_object($user) && method_exists($user, 'forceFill') && method_exists($user, 'save')) {
                $user->forceFill([
                    'last_login_at' => now(),
                    'failed_login_count' => 0,
                    'locked_until' => null,
                ])->save();
            }
        });
    }

    private function registerSlowQueryTelemetry(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            if (! (bool) config('fap.observability.slow_query_log_enabled', true)) {
                return;
            }

            $thresholdMs = max(0.0, (float) config('fap.observability.slow_query_ms', 500));
            $sqlMs = max(0.0, (float) ($query->time ?? 0.0));
            if ($sqlMs < $thresholdMs) {
                return;
            }

            $request = request();
            $route = $this->resolveSlowQueryRoute($request);
            $requestId = $this->resolveSlowQueryRequestId($request);
            $orgId = $this->resolveSlowQueryOrgId($request);

            $normalizedSql = preg_replace('/\s+/', ' ', trim((string) ($query->sql ?? '')));
            if (! is_string($normalizedSql)) {
                $normalizedSql = '';
            }
            if (strlen($normalizedSql) > 512) {
                $normalizedSql = substr($normalizedSql, 0, 512).'...';
            }

            Log::warning('SLOW_QUERY_DETECTED', [
                'org_id' => $orgId,
                'route' => $route,
                'sql_ms' => round($sqlMs, 3),
                'request_id' => $requestId,
                'connection' => (string) ($query->connectionName ?? ''),
                'bindings_count' => is_array($query->bindings ?? null) ? count($query->bindings) : 0,
                'sql' => $normalizedSql,
            ]);
        });
    }

    private function resolveSlowQueryRoute(mixed $request): string
    {
        if (! $request instanceof Request) {
            return 'console';
        }

        $route = '';
        try {
            $routeValue = $request->route();
            if ($routeValue instanceof IlluminateRoute) {
                $route = trim((string) $routeValue->uri());
            } elseif (is_string($routeValue)) {
                $route = trim($routeValue);
            }
        } catch (\Throwable) {
            $route = '';
        }

        if ($route === '') {
            $route = trim((string) $request->path());
        }

        return $route !== '' ? $route : '/';
    }

    private function resolveSlowQueryRequestId(mixed $request): ?string
    {
        if (! $request instanceof Request) {
            return null;
        }

        $requestId = trim((string) ($request->attributes->get('request_id') ?? ''));
        if ($requestId === '') {
            $requestId = trim((string) $request->header('X-Request-Id', $request->header('X-Request-ID', '')));
        }

        return $requestId !== '' ? $requestId : null;
    }

    private function resolveSlowQueryOrgId(mixed $request): int
    {
        $orgId = 0;

        if ($request instanceof Request) {
            $attrOrgId = $request->attributes->get('org_id');
            if (is_numeric($attrOrgId)) {
                $orgId = (int) $attrOrgId;
            }
            if ($orgId <= 0) {
                $attrFmOrgId = $request->attributes->get('fm_org_id');
                if (is_numeric($attrFmOrgId)) {
                    $orgId = (int) $attrFmOrgId;
                }
            }
        }

        if ($orgId <= 0) {
            try {
                $orgId = (int) app(OrgContext::class)->orgId();
            } catch (\Throwable) {
                $orgId = 0;
            }
        }

        return max(0, $orgId);
    }
}
