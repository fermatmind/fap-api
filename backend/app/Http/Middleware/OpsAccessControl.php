<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Ops\OpsAlertService;
use App\Services\Ops\OpsAuditLogger;
use App\Services\Ops\OpsDistributedLimiter;
use App\Services\Ops\OpsIpBlacklist;
use App\Services\Ops\OpsRiskEngine;
use App\Support\Ops\OpsSecurityEvent;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class OpsAccessControl
{
    public function handle(Request $request, Closure $next): Response
    {
        $routeName = (string) optional($request->route())->getName();
        if ($routeName === '' || ! str_starts_with($routeName, 'filament.ops.')) {
            return $next($request);
        }

        $config = $this->accessControlConfig();

        if (! $config['enabled'] || $config['emergency_disable']) {
            return $next($request);
        }

        try {
            $routeKey = $routeName !== '' ? $routeName : $request->path();
            $admin = $this->adminUser();

            OpsAuditLogger::log('ACCESS', [
                'user_id' => $admin?->getAuthIdentifier(),
                'route' => $routeName,
                'path' => $request->path(),
                'ip' => $request->ip(),
                'host' => $request->getHost(),
            ]);

            OpsSecurityEvent::emit('ACCESS', [
                'route' => $routeName,
                'path' => $request->path(),
                'ip' => $request->ip(),
                'host' => $request->getHost(),
                'user_id' => $admin?->getAuthIdentifier(),
            ]);

            $networkBlock = $this->networkBoundaryBlock($request, $routeName, $config);
            if ($networkBlock !== null) {
                return $networkBlock;
            }

            if (str_starts_with($routeName, 'filament.ops.auth.')) {
                if (
                    $routeName === 'filament.ops.auth.login'
                    && $request->isMethod('post')
                    && ! app()->environment(['local', 'testing', 'ci'])
                ) {
                    if (OpsIpBlacklist::isBlocked((string) $request->ip())) {
                        OpsSecurityEvent::emit('IP_BLACKLIST_BLOCKED', [
                            'ip' => $request->ip(),
                            'route' => $routeName,
                            'host' => $request->getHost(),
                        ]);

                        return response()->json([
                            'ok' => false,
                            'error_code' => 'RATE_LIMITED',
                            'message' => 'Too many attempts',
                        ], 429);
                    }

                    $loginIpKey = 'ops:login:ip:'.($request->ip() ?? 'unknown');
                    $identifier = $this->loginIdentifier($request);
                    $loginUserKey = 'ops:login:user:'.$identifier;
                    $maxAttempts = max(1, (int) ($config['rate_limit']['login'] ?? $config['admin_login_max_attempts']));

                    $decaySeconds = max(60, (int) config('ops.security.admin_login_decay_seconds', 300));
                    $ipCount = OpsDistributedLimiter::hit($loginIpKey, $decaySeconds);
                    $userCount = OpsDistributedLimiter::hit($loginUserKey, $decaySeconds);

                    if (
                        $ipCount > $maxAttempts
                        || $userCount > $maxAttempts
                        || OpsDistributedLimiter::tooMany($loginIpKey, $maxAttempts)
                        || OpsDistributedLimiter::tooMany($loginUserKey, $maxAttempts)
                    ) {
                        OpsSecurityEvent::emit('LOGIN_RATE_LIMIT', [
                            'ip' => $request->ip(),
                            'route' => $routeName,
                            'identifier' => $identifier,
                            'count_ip' => $ipCount,
                            'count_user' => $userCount,
                        ]);

                        OpsAlertService::send('🚨 Ops login attack: '.((string) $request->ip()));
                        OpsIpBlacklist::block((string) $request->ip());

                        return response()->json([
                            'ok' => false,
                            'error_code' => 'RATE_LIMITED',
                            'message' => 'Too many attempts',
                        ], 429);
                    }

                    if ((bool) ($config['risk']['enabled'] ?? true)) {
                        $risk = OpsRiskEngine::evaluate([
                            'ip_reputation' => $this->isTrustedIp((string) $request->ip(), $config) ? 'trusted' : 'untrusted',
                            'failed_login_count' => OpsDistributedLimiter::attempts($loginIpKey),
                            'external_risk_score' => 0,
                        ]);

                        if ($risk['level'] === 'HIGH') {
                            OpsSecurityEvent::emit('HIGH_RISK_ACCESS', [
                                'route' => $routeName,
                                'ip' => $request->ip(),
                                'score' => $risk['score'],
                                'level' => $risk['level'],
                            ]);
                        }
                    }
                }

                OpsSecurityEvent::emit('PASS', [
                    'route' => $routeName,
                    'ip' => $request->ip(),
                    'host' => $request->getHost(),
                ]);

                return $next($request);
            }

            if ($this->isSafeRoute($routeName)) {
                OpsSecurityEvent::emit('PASS', [
                    'route' => $routeName,
                    'ip' => $request->ip(),
                    'host' => $request->getHost(),
                    'user_id' => $admin?->getAuthIdentifier(),
                ]);

                return $next($request);
            }

            if (! app()->environment(['local', 'testing', 'ci'])) {
                if (OpsIpBlacklist::isBlocked((string) $request->ip())) {
                    OpsSecurityEvent::emit('IP_BLACKLIST_BLOCKED', [
                        'ip' => $request->ip(),
                        'route' => $routeName,
                        'host' => $request->getHost(),
                    ]);

                    return response('Blocked IP', 403);
                }

                $globalLimit = max(1, (int) ($config['rate_limit']['global'] ?? 100));
                $globalIpKey = 'ops:global:ip:'.$routeKey.':'.($request->ip() ?? 'unknown');
                $globalIpCount = OpsDistributedLimiter::hit($globalIpKey, 60);
                if ($globalIpCount > $globalLimit || OpsDistributedLimiter::tooMany($globalIpKey, $globalLimit)) {
                    OpsSecurityEvent::emit('GLOBAL_RATE_LIMIT', [
                        'route' => $routeName,
                        'ip' => $request->ip(),
                        'count' => $globalIpCount,
                    ]);

                    return response()->json([
                        'ok' => false,
                        'error_code' => 'RATE_LIMITED',
                        'message' => 'Too many requests',
                    ], 429);
                }

                if ($admin !== null) {
                    $globalUserKey = 'ops:global:user:'.$routeKey.':'.$admin->getAuthIdentifier();
                    $globalUserCount = OpsDistributedLimiter::hit($globalUserKey, 60);
                    if ($globalUserCount > $globalLimit || OpsDistributedLimiter::tooMany($globalUserKey, $globalLimit)) {
                        OpsSecurityEvent::emit('GLOBAL_RATE_LIMIT', [
                            'route' => $routeName,
                            'ip' => $request->ip(),
                            'user_id' => $admin->getAuthIdentifier(),
                            'count' => $globalUserCount,
                        ]);

                        return response()->json([
                            'ok' => false,
                            'error_code' => 'RATE_LIMITED',
                            'message' => 'Too many requests',
                        ], 429);
                    }
                }
            }

            if ($this->isSensitiveRoute($routeName, $request->path())) {
                OpsAuditLogger::log('SENSITIVE_ACTION', [
                    'user_id' => $admin?->getAuthIdentifier(),
                    'route' => $routeName,
                    'path' => $request->path(),
                    'ip' => $request->ip(),
                ]);

                if (! $this->hasSensitiveActionPermission($admin)) {
                    OpsSecurityEvent::emit('UNAUTHORIZED_ACTION', [
                        'user_id' => $admin?->getAuthIdentifier(),
                        'route' => $routeName,
                        'path' => $request->path(),
                        'ip' => $request->ip(),
                    ]);

                    return response('Forbidden', 403);
                }
            }

            if ((bool) ($config['risk']['enabled'] ?? true)) {
                $risk = OpsRiskEngine::evaluate([
                    'ip_reputation' => $this->isTrustedIp((string) $request->ip(), $config) ? 'trusted' : 'untrusted',
                    'failed_login_count' => 0,
                    'external_risk_score' => 0,
                ]);

                if ($risk['level'] === 'HIGH') {
                    OpsSecurityEvent::emit('HIGH_RISK_ACCESS', [
                        'route' => $routeName,
                        'ip' => $request->ip(),
                        'score' => $risk['score'],
                        'level' => $risk['level'],
                        'user_id' => $admin?->getAuthIdentifier(),
                    ]);
                }
            }

            OpsSecurityEvent::emit('PASS', [
                'route' => $routeName,
                'ip' => $request->ip(),
                'host' => $request->getHost(),
                'user_id' => $admin?->getAuthIdentifier(),
            ]);

            return $next($request);
        } catch (Throwable $e) {
            OpsSecurityEvent::emit('FAIL_OPEN', [
                'route' => $routeName,
                'host' => $request->getHost(),
                'ip' => $request->ip(),
                'error' => $e->getMessage(),
            ]);

            if ($config['fail_open']) {
                return $next($request);
            }

            throw $e;
        }
    }

    /**
     * @return array{
     *     enabled: bool,
     *     fail_open: bool,
     *     emergency_disable: bool,
     *     allowed_host: string,
     *     ip_allowlist: array<int, string>,
     *     admin_login_max_attempts: int,
     *     audit_log: bool,
     *     rate_limit: array{login: int, global: int},
     *     risk: array{enabled: bool}
     * }
     */
    private function accessControlConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('ops.access_control', []);

        return [
            'enabled' => (bool) ($config['enabled'] ?? true),
            'fail_open' => (bool) ($config['fail_open'] ?? false),
            'emergency_disable' => (bool) ($config['emergency_disable'] ?? false),
            'allowed_host' => trim((string) ($config['allowed_host'] ?? '')),
            'ip_allowlist' => array_values(array_filter(array_map(
                static fn ($value): string => trim((string) $value),
                (array) ($config['ip_allowlist'] ?? [])
            ))),
            'admin_login_max_attempts' => max(1, (int) ($config['admin_login_max_attempts'] ?? 5)),
            'audit_log' => (bool) ($config['audit_log'] ?? true),
            'rate_limit' => [
                'login' => max(1, (int) data_get($config, 'rate_limit.login', $config['admin_login_max_attempts'] ?? 5)),
                'global' => max(1, (int) data_get($config, 'rate_limit.global', 100)),
            ],
            'risk' => [
                'enabled' => (bool) data_get($config, 'risk.enabled', true),
            ],
        ];
    }

    /**
     * @param  array{
     *     ip_allowlist: array<int, string>
     * }  $config
     */
    private function isTrustedIp(string $ip, array $config): bool
    {
        $allowlist = array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            (array) ($config['ip_allowlist'] ?? [])
        )));

        return $allowlist !== [] && in_array($ip, $allowlist, true);
    }

    private function loginIdentifier(Request $request): string
    {
        $identifier = mb_strtolower(trim((string) ($request->input('email') ?? $request->input('username') ?? '')));

        return $identifier !== ''
            ? $identifier
            : 'anonymous:'.($request->ip() ?? 'unknown');
    }

    /**
     * @param  array{
     *     allowed_host: string,
     *     ip_allowlist: array<int, string>
     * }  $config
     */
    private function networkBoundaryBlock(Request $request, string $routeName, array $config): ?Response
    {
        $allowedHost = mb_strtolower(trim((string) $config['allowed_host']));
        if ($allowedHost !== '') {
            $host = mb_strtolower(trim((string) $request->getHost()));

            if ($host !== $allowedHost) {
                OpsSecurityEvent::emit('HOST_BLOCKED', [
                    'host' => $host,
                    'allowed' => $allowedHost,
                    'route' => $routeName,
                    'ip' => $request->ip(),
                ]);

                return response('Ops console is not available on this host.', 403);
            }
        }

        $allowlist = array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            (array) $config['ip_allowlist']
        )));

        if ($allowlist !== [] && ! in_array((string) $request->ip(), $allowlist, true)) {
            OpsSecurityEvent::emit('IP_BLOCKED', [
                'ip' => $request->ip(),
                'route' => $routeName,
                'host' => $request->getHost(),
            ]);

            return response('Ops console IP is not allowlisted.', 403);
        }

        return null;
    }

    private function isSensitiveRoute(string $routeName, string $path): bool
    {
        $haystack = strtolower($routeName.' '.$path);
        foreach (['delivery-tools', 'secure-link', 'refund', 'grant', 'reprocess'] as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function isSafeRoute(string $routeName): bool
    {
        return in_array($routeName, [
            'filament.ops.auth.login',
            'filament.ops.auth.logout',
            'filament.ops.pages.select-org',
        ], true);
    }

    private function hasSensitiveActionPermission(?Authenticatable $user): bool
    {
        return $user !== null
            && method_exists($user, 'hasPermission')
            && ($user->hasPermission('admin.ops.write') || $user->hasPermission('admin.owner'));
    }

    private function adminUser(): ?Authenticatable
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = Auth::guard($guard)->user();

        return $user instanceof Authenticatable ? $user : null;
    }
}
