<?php

namespace App\Providers;

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
use App\Services\Content\ContentPack;
use App\Services\Content\ContentPacksIndex;
use App\Services\Content\ContentStore;
use App\Services\ContentPackResolver;
use App\Support\OrgContext;
use App\Support\SensitiveDataRedactor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Monolog\LogRecord;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    private static bool $redactProcessorRegistered = false;

    /**
     * Register any application services.
     */
    public function register(): void
    {
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
                $attempt = Attempt::query()
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
        Gate::policy(Attempt::class, AttemptPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(PaymentEvent::class, PaymentEventPolicy::class);
        Gate::policy(BenefitGrant::class, BenefitGrantPolicy::class);
        Gate::policy(AdminApproval::class, AdminApprovalPolicy::class);
        Gate::policy(ReportSnapshot::class, ReportSnapshotPolicy::class);
        Gate::policy(Share::class, SharePolicy::class);
        Gate::policy(ScaleRegistry::class, ScaleRegistryPolicy::class);
        Gate::policy(ScaleSlug::class, ScaleSlugPolicy::class);

        if ($this->app->runningInConsole() && $this->app->environment('production')) {
            $argv = $_SERVER['argv'] ?? [];
            $command = is_array($argv) ? trim((string) ($argv[1] ?? '')) : '';
            $blockedCommands = ['migrate:rollback', 'migrate:reset', 'migrate:refresh'];
            if (in_array($command, $blockedCommands, true)) {
                throw new RuntimeException('rollback disabled in production: '.$command);
            }
        }

        if (! self::$redactProcessorRegistered) {
            $redactor = new SensitiveDataRedactor;

            Log::getLogger()->pushProcessor(function (array|LogRecord $record) use ($redactor): array|LogRecord {
                if ($record instanceof LogRecord) {
                    $context = is_array($record->context) ? $redactor->redact($record->context) : $record->context;
                    $extra = is_array($record->extra) ? $redactor->redact($record->extra) : $record->extra;

                    return $record->with(context: $context, extra: $extra);
                }

                if (isset($record['context']) && is_array($record['context'])) {
                    $record['context'] = $redactor->redact($record['context']);
                }

                if (isset($record['extra']) && is_array($record['extra'])) {
                    $record['extra'] = $redactor->redact($record['extra']);
                }

                return $record;
            });

            self::$redactProcessorRegistered = true;
        }

        $isDebugEnabledByEnv = (bool) config('app.debug', false);

        if ($this->app->environment('production') && $isDebugEnabledByEnv) {
            Log::emergency('CRITICAL: PRODUCTION_APP_DEBUG_TRUE');
        }

        $response = function (string $code, string $message) {
            return function (Request $request, array $headers) use ($code, $message) {
                return response()->json([
                    'error' => [
                        'code' => $code,
                        'message' => $message,
                    ],
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

        RateLimiter::for('api_auth', function (Request $request) use ($response, $shouldBypassRateLimits) {
            if ($shouldBypassRateLimits()) {
                return Limit::none();
            }

            $limit = (int) config('fap.rate_limits.api_auth_per_minute', 30);
            $limit = max(1, $limit);

            return Limit::perMinute($limit)
                ->by('ip:'.$request->ip())
                ->response($response('RATE_LIMIT_AUTH', 'Too many auth requests. Please retry later.'));
        });

        RateLimiter::for('api_attempt_submit', function (Request $request) use ($response, $shouldBypassRateLimits) {
            if ($shouldBypassRateLimits()) {
                return Limit::none();
            }

            $limit = (int) config('fap.rate_limits.api_attempt_submit_per_minute', 20);
            $limit = max(1, $limit);

            $userId = (string) ($request->attributes->get('fm_user_id') ?? '');
            if ($userId === '' && $request->user()) {
                $userId = (string) $request->user()->getAuthIdentifier();
            }

            $key = $userId !== '' ? ('user:'.$userId) : ('ip:'.$request->ip());

            return Limit::perMinute($limit)
                ->by($key)
                ->response($response('RATE_LIMIT_ATTEMPT_SUBMIT', 'Too many attempt submissions. Please retry later.'));
        });

        RateLimiter::for('api_webhook', function (Request $request) use ($response, $shouldBypassRateLimits) {
            if ($shouldBypassRateLimits()) {
                return Limit::none();
            }

            $limit = (int) config('fap.rate_limits.api_webhook_per_minute', 60);
            $limit = max(1, $limit);

            $provider = (string) $request->route('provider', '');
            if ($provider === '') {
                $provider = (string) $request->route('provider_code', '');
            }

            $key = $provider !== '' ? ('provider:'.$provider) : ('ip:'.$request->ip());

            return Limit::perMinute($limit)
                ->by($key)
                ->response($response('RATE_LIMIT_WEBHOOK', 'Too many webhook requests. Please retry later.'));
        });
    }
}
