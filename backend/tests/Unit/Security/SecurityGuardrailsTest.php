<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

final class SecurityGuardrailsTest extends TestCase
{
    /** @var array<int, string> */
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** @var array<int, string> */
    private const EXPLICIT_PUBLIC_MUTATING_ROUTES = [
        '/api/v0.2/{any?}',
        '/api/v0.3/webhooks/payment/{provider}',
        '/api/v0.3/auth/guest',
        '/api/v0.3/auth/wx_phone',
        '/api/v0.3/auth/phone/send_code',
        '/api/v0.3/auth/phone/verify',
        '/api/v0.3/results/lookup-by-email',
        '/api/v0.3/attempts/start',
        '/api/v0.3/attempts/{attempt_id}/progress',
        '/api/v0.3/orders/checkout',
        '/api/v0.3/orders/lookup',
        '/api/v0.3/orders/{order_no}/resend',
        '/api/v0.3/orders/stub',
        '/api/v0.3/claim/report',
        '/api/v0.3/email/capture',
        '/api/v0.3/email/preferences',
        '/api/v0.3/email/unsubscribe',
        '/api/v0.3/analytics/mbti-attribution-events',
        '/api/v0.3/shares/{shareId}/click',
        '/api/v0.3/shares/{shareId}/compare-invites',
        '/api/v0.5/career/attribution/events',
        '/api/v0.5/career/recommendations/mbti/{type}/feedback',
        '/api/v0.5/career/shortlist',
        '/api/v0.5/internal/content-pages/{slug}',
        '/api/v0.5/internal/landing-surfaces/{surfaceKey}',
        '/api/v0.5/internal/media-assets/{assetKey}',
        '/api/v0.5/internal/media-assets/{assetKey}/upload',
    ];

    /** @var array<int, string> */
    private const AUTH_MIDDLEWARE_NEEDLES = [
        'auth:',
        'FmTokenAuth',
        'AdminAuth',
        'IntegrationsIngestAuth',
        'PartnerApiKeyAuth',
    ];

    /** @var array<int, string> */
    private const OWNERSHIP_404_PATHS = [
        'app/Http/Controllers/API/V0_2/LegacyReportController.php',
        'app/Http/Controllers/API/V0_3/AttemptReadController.php',
        'app/Http/Controllers/API/V0_3/AttemptProgressController.php',
        'app/Http/Controllers/LookupController.php',
        'app/Services/Legacy/LegacyReportService.php',
        'app/Services/Legacy/LegacyShareService.php',
        'app/Services/Report/ReportGatekeeper.php',
    ];

    /** @var array<int, string> */
    private const SECURITY_SCAN_ROOTS = [
        'routes',
        'app/Http',
        'app/Services',
    ];

    /** @var array<int, string> */
    private const FM_TOKEN_MIDDLEWARE_PATHS = [
        'app/Http/Middleware/FmTokenAuth.php',
        'app/Http/Middleware/FmTokenOptional.php',
        'app/Http/Middleware/FmTokenOptionalAuth.php',
    ];

    public function test_state_changing_routes_require_auth_or_explicit_allowlist(): void
    {
        $violations = [];

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $methods = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));
            if (! $this->hasMutatingMethod($methods)) {
                continue;
            }

            $uri = '/'.ltrim((string) $route->uri(), '/');
            if (! str_starts_with($uri, '/api/')) {
                continue;
            }

            if ($this->isExplicitPublicMutatingRoute($uri)) {
                continue;
            }

            $middlewares = array_values(array_map(
                static fn (mixed $mw): string => (string) $mw,
                $route->gatherMiddleware()
            ));

            if ($this->hasAuthMiddleware($middlewares)) {
                continue;
            }

            $violations[] = sprintf(
                '%s %s [mw=%s]',
                implode('|', $methods),
                $uri,
                implode(',', $middlewares)
            );
        }

        sort($violations);

        $this->assertSame(
            [],
            $violations,
            "Mutating API routes must require auth middleware unless explicitly allowlisted.\n".implode("\n", $violations)
        );
    }

    public function test_v0_3_attempt_submit_keeps_token_auth_and_submit_throttle(): void
    {
        $route = app('router')->getRoutes()->match(
            request()->create('/api/v0.3/attempts/submit', 'POST')
        );

        $middlewares = array_values(array_map(
            static fn (mixed $mw): string => (string) $mw,
            $route->gatherMiddleware()
        ));

        $joined = implode("\n", $middlewares);

        $this->assertStringContainsString('FmTokenAuth', $joined);
        $this->assertContains('throttle:api_attempt_submit', $middlewares);
    }

    public function test_no_mass_assignment_from_request_all_in_controllers_or_services(): void
    {
        $scanRoots = array_map(
            static fn (string $root): string => base_path($root),
            self::SECURITY_SCAN_ROOTS
        );

        $patterns = [
            'direct_create_request_all' => '/(?:->|::)\s*create\s*\(\s*\$request->all\(\)\s*\)/',
            'direct_update_request_all' => '/(?:->|::)\s*update\s*\(\s*\$request->all\(\)\s*\)/',
            'direct_fill_request_all' => '/(?:->|::)\s*fill\s*\(\s*\$request->all\(\)\s*\)/',
            'request_helper_all' => '/(?:->|::)\s*(?:create|update|fill)\s*\(\s*request\(\)->all\(\)\s*\)/',
            'assigned_request_all_then_sink' => '/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*\$request->all\(\)\s*;[\s\S]{0,240}?(?:->|::)\s*(?:create|update|fill)\s*\(\s*\$\1\s*\)/m',
        ];

        $violations = [];

        foreach ($scanRoots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $path = $file->getPathname();
                $source = file_get_contents($path);
                if (! is_string($source)) {
                    continue;
                }

                foreach ($patterns as $label => $regex) {
                    if (preg_match($regex, $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
                        continue;
                    }

                    $line = 1 + substr_count(substr($source, 0, (int) $match[0][1]), "\n");
                    if ($this->isSecurityGateIgnored($source, $line, $label)) {
                        continue;
                    }

                    $relative = ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR);
                    $violations[] = sprintf('%s:%d => %s', $relative, $line, $label);
                }
            }
        }

        sort($violations);

        $this->assertSame(
            [],
            $violations,
            "Do not pipe request->all() into create/update/fill in controllers/services.\n".implode("\n", $violations)
        );
    }

    public function test_ownership_paths_do_not_emit_forbidden_403_contracts(): void
    {
        $patterns = [
            'abort_403' => '/abort\s*\(\s*403\b/',
            'response_json_403' => '/response\s*\(\s*\)\s*->\s*json\s*\([^;]*,\s*403\s*\)/s',
            'status_403' => '/[\'"]status[\'"]\s*=>\s*403\b/',
            'error_code_forbidden' => '/[\'"]error_code[\'"]\s*=>\s*[\'"]FORBIDDEN[\'"]/',
        ];

        $violations = [];

        foreach (self::OWNERSHIP_404_PATHS as $relativePath) {
            $absolute = base_path($relativePath);
            if (! is_file($absolute)) {
                continue;
            }

            $source = file_get_contents($absolute);
            if (! is_string($source)) {
                continue;
            }

            foreach ($patterns as $label => $regex) {
                if (preg_match($regex, $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }

                $line = 1 + substr_count(substr($source, 0, (int) $match[0][1]), "\n");
                if ($this->isSecurityGateIgnored($source, $line, $label)) {
                    continue;
                }

                $violations[] = sprintf('%s:%d => %s', $relativePath, $line, $label);
            }
        }

        sort($violations);

        $this->assertSame(
            [],
            $violations,
            "Ownership-sensitive paths should stay on unified 404 contract, not 403.\n".implode("\n", $violations)
        );
    }

    public function test_v03_attempt_ownership_resolver_does_not_trust_anon_headers(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/API/V0_3/Concerns/ResolvesAttemptOwnership.php'));
        $this->assertIsString($source);

        $this->assertDoesNotMatchRegularExpression('/header\s*\(\s*[\'"]X-Anon-Id[\'"]/', $source);
        $this->assertDoesNotMatchRegularExpression('/header\s*\(\s*[\'"]X-Fm-Anon-Id[\'"]/', $source);
        $this->assertMatchesRegularExpression('/attributes->get\([\'"]anon_id[\'"]\)/', $source);
        $this->assertMatchesRegularExpression('/attributes->get\([\'"]fm_anon_id[\'"]\)/', $source);
    }

    public function test_org_context_can_resolve_anon_id_from_token_payload(): void
    {
        $source = file_get_contents(base_path('app/Http/Middleware/ResolveOrgContext.php'));
        $this->assertIsString($source);

        $this->assertMatchesRegularExpression('/resolveAnonIdFromToken/', $source);
        $this->assertMatchesRegularExpression('/fm_anon_id/', $source);
        $this->assertMatchesRegularExpression('/anon_id/', $source);
    }

    public function test_webhook_signature_verifier_has_no_environment_fail_open(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/Webhooks/HandleProviderWebhook.php'));
        $this->assertIsString($source);

        $this->assertDoesNotMatchRegularExpression('/app\(\)->environment\s*\(\s*\[[^\]]*testing[^\]]*\]\s*\)/', $source);
        $this->assertMatchesRegularExpression('/allow_unsigned_without_secret/', $source);
    }

    public function test_ci_deploy_supply_chain_uses_pinned_trust_material(): void
    {
        $repoRoot = dirname(base_path());
        $deploy = file_get_contents($repoRoot.'/.github/workflows/deploy.yml');
        $this->assertIsString($deploy);

        $this->assertDoesNotMatchRegularExpression('/ssh-keyscan/', $deploy);
        $this->assertMatchesRegularExpression('/SSH_KNOWN_HOSTS/', $deploy);
        $this->assertMatchesRegularExpression('/DEPLOYER_SHA256/', $deploy);
        $this->assertMatchesRegularExpression('/sha256sum -c -/', $deploy);
        $this->assertLessThan(
            strpos($deploy, 'webfactory/ssh-agent'),
            strpos($deploy, 'Install Deployer (pinned)')
        );
    }

    public function test_code_scanning_uses_pinned_semgrep_install(): void
    {
        $repoRoot = dirname(base_path());
        $workflow = file_get_contents($repoRoot.'/.github/workflows/codeql.yml');
        $this->assertIsString($workflow);

        $this->assertMatchesRegularExpression('/SEMGREP_VERSION:\s*"[0-9]+\.[0-9]+\.[0-9]+"/', $workflow);
        $this->assertStringContainsString('"semgrep==${SEMGREP_VERSION}"', $workflow);
        $this->assertStringContainsString('semgrep --version | grep -Fx "${SEMGREP_VERSION}"', $workflow);
        $this->assertDoesNotMatchRegularExpression('/pip install[^\n]*--upgrade[^\n]*semgrep/', $workflow);
        $this->assertDoesNotMatchRegularExpression('/pip install[^\n]*\bsemgrep\b(?!==)/', $workflow);
    }

    public function test_compose_defaults_do_not_publish_open_unauthenticated_datastores(): void
    {
        $repoRoot = dirname(base_path());
        $compose = file_get_contents($repoRoot.'/backend/docker-compose.yml');
        $this->assertIsString($compose);

        $this->assertDoesNotMatchRegularExpression('/MYSQL_ROOT_PASSWORD:\s*root/', $compose);
        $this->assertDoesNotMatchRegularExpression('/MYSQL_PASSWORD:\s*fap/', $compose);
        $this->assertDoesNotMatchRegularExpression('/"\d+:\d+"/', $compose);
        $this->assertMatchesRegularExpression('/127\.0\.0\.1:\$\{FAP_DOCKER_MYSQL_PORT:-3306\}:3306/', $compose);
        $this->assertMatchesRegularExpression('/127\.0\.0\.1:\$\{FAP_DOCKER_REDIS_PORT:-6379\}:6379/', $compose);
        $this->assertMatchesRegularExpression('/--requirepass/', $compose);
    }

    public function test_ci_and_staging_do_not_expose_auth_bypass_surfaces(): void
    {
        $routes = file_get_contents(base_path('routes/api.php'));
        $otp = file_get_contents(base_path('app/Services/Auth/PhoneOtpService.php'));
        $wx = file_get_contents(base_path('app/Http/Controllers/API/V0_3/AuthWxPhoneController.php'));
        $services = file_get_contents(base_path('config/services.php'));

        $this->assertIsString($routes);
        $this->assertIsString($otp);
        $this->assertIsString($wx);
        $this->assertIsString($services);

        $this->assertDoesNotMatchRegularExpression('/environment\s*\(\s*\[[^\]]*[\'"]ci[\'"][^\]]*\]\s*\)/', $routes);
        $this->assertDoesNotMatchRegularExpression('/environment\s*\(\s*\[[^\]]*[\'"]ci[\'"][^\]]*\]\s*\)/', $otp);
        $this->assertDoesNotMatchRegularExpression('/environment\s*\(\s*\[[^\]]*[\'"]ci[\'"][^\]]*\]\s*\)/', $wx);
        $this->assertStringContainsString("env('BILLING_WEBHOOK_SECRET_OPTIONAL_ENVS', 'local,testing')", $services);
    }

    public function test_release_pack_keeps_hygiene_and_artifact_clean_gates(): void
    {
        $repoRoot = dirname(base_path());
        $releasePack = file_get_contents($repoRoot.'/backend/scripts/release_pack.sh');
        $releaseHygiene = file_get_contents($repoRoot.'/scripts/release_hygiene_gate.sh');
        $this->assertIsString($releasePack);
        $this->assertIsString($releaseHygiene);

        $this->assertStringContainsString('scripts/security/assert_artifact_clean.sh', $releasePack);
        $this->assertStringContainsString('scripts/release_hygiene_gate.sh', $releasePack);
        $this->assertStringContainsString('storage/framework/cache', $releaseHygiene);
        $this->assertStringContainsString('storage/app/private/artifacts', $releaseHygiene);
        $this->assertStringContainsString('storage/app/private/packs_v2_materialized', $releaseHygiene);
    }

    public function test_fm_token_middlewares_check_revoked_and_expired_tokens(): void
    {
        $missing = [];

        foreach (self::FM_TOKEN_MIDDLEWARE_PATHS as $relativePath) {
            $source = file_get_contents(base_path($relativePath));
            if (! is_string($source)) {
                $missing[] = "{$relativePath}:unreadable";

                continue;
            }

            if (! preg_match('/revoked_at/', $source)) {
                $missing[] = "{$relativePath}:revoked_at_check";
            }
            if (! preg_match('/expires_at/', $source)) {
                $missing[] = "{$relativePath}:expires_at_check";
            }
        }

        $this->assertSame(
            [],
            $missing,
            "fm token middlewares must enforce revoked/expired token checks.\n".implode("\n", $missing)
        );
    }

    public function test_content_pack_error_contract_does_not_leak_internal_reason(): void
    {
        $source = file_get_contents(base_path('app/Support/ApiExceptionRenderer.php'));
        $this->assertIsString($source);

        $this->assertMatchesRegularExpression('/CONTENT_PACK_ERROR/', $source);
        $this->assertDoesNotMatchRegularExpression('/getPrevious\s*\(\)\s*->\s*getMessage/', $source);
        $this->assertDoesNotMatchRegularExpression('/[\'"]reason[\'"]\s*=>/', $source);
    }

    public function test_security_docs_do_not_disclose_exact_production_infrastructure_details(): void
    {
        $repoRoot = dirname(base_path());
        $paths = [
            'README_DEPLOY.md',
            'docs/04-ops/backend-deploy-target-aliyun-01.md',
            'backend/docs/seo/crawler-log-production-canary-preflight.md',
            'backend/docs/seo/crawler-log-production-canary-report.md',
            'backend/docs/seo/crawler-log-production-canary-runtime.md',
            'backend/docs/seo/search-channel-live-02-preflight.md',
            'backend/docs/seo/search-channel-live-mbti-01-preflight.md',
            'backend/docs/seo/generated/crawler-log-production-canary-preflight.v1.json',
            'backend/docs/seo/generated/crawler-log-production-canary-report.v1.json',
            'backend/docs/seo/generated/crawler-log-production-canary-runtime.v1.json',
            'backend/docs/seo/generated/search-channel-live-02-preflight.v1.json',
        ];

        $patterns = [
            'production_ip' => '/\b(?:139\.224\.130\.204|122\.152\.221\.126)\b/',
            'production_webhook_url' => '/http:\/\/122\.152\.221\.126:9000\/hooks\/deploy-fap-api/',
            'production_log_path' => '/\/var\/log\/nginx\/access\.log/',
            'production_release_shell' => '/\/var\/www\/fap-api\/current\/backend/',
            'indexnow_key_location_url' => '/https:\/\/fermatmind\.com\/[a-f0-9]{32}\.txt/',
            'indexnow_key_hash' => '/indexnow_key_(?:location_public_)?sha256"\s*:\s*"[a-f0-9]{64}"/',
            'rds_endpoint' => '/rm-uf6u2498t7nk2faya\.rwlb\.rds\.aliyuncs\.com/',
        ];

        $violations = [];

        foreach ($paths as $relativePath) {
            $source = file_get_contents($repoRoot.'/'.$relativePath);
            $this->assertIsString($source, $relativePath);

            foreach ($patterns as $label => $pattern) {
                if (preg_match($pattern, $source) !== 1) {
                    continue;
                }

                $violations[] = "{$relativePath}:{$label}";
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Security docs must use placeholders for exact production infrastructure details.\n".implode("\n", $violations)
        );
    }

    public function test_v03_auth_guest_route_wiring_and_middleware_contract(): void
    {
        $source = file_get_contents(base_path('routes/api.php'));
        $this->assertIsString($source);

        $this->assertMatchesRegularExpression(
            '/Route::middleware\(\s*[\'"]throttle:api_auth[\'"]\s*\)\s*->\s*group\s*\(\s*function\s*\(\s*\)\s*\{/s',
            $source
        );
        $this->assertMatchesRegularExpression(
            '/Route::post\(\s*[\'"]\/auth\/guest[\'"]\s*,\s*AuthGuestV03Controller::class\s*\)\s*->\s*middleware\(\s*\\\\App\\\\Http\\\\Middleware\\\\ResolveAnonId::class\s*\)\s*;/s',
            $source
        );
    }

    public function test_v02_routes_use_deprecated_410_contract(): void
    {
        $source = file_get_contents(base_path('routes/api.php'));
        $this->assertIsString($source);

        $this->assertMatchesRegularExpression('/Route::prefix\(\s*[\'"]v0\.2[\'"]\s*\)/', $source);
        $this->assertMatchesRegularExpression('/[\'"]error_code[\'"]\s*=>\s*[\'"]API_VERSION_DEPRECATED[\'"]/', $source);
        $this->assertMatchesRegularExpression('/\],\s*410\s*\)/', $source);
    }

    /**
     * @param  array<int, string>  $methods
     */
    private function hasMutatingMethod(array $methods): bool
    {
        return count(array_intersect(self::MUTATING_METHODS, $methods)) > 0;
    }

    /**
     * @param  array<int, string>  $middlewares
     */
    private function hasAuthMiddleware(array $middlewares): bool
    {
        foreach ($middlewares as $middleware) {
            foreach (self::AUTH_MIDDLEWARE_NEEDLES as $needle) {
                if (str_contains($middleware, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isExplicitPublicMutatingRoute(string $uri): bool
    {
        return in_array($uri, self::EXPLICIT_PUBLIC_MUTATING_ROUTES, true);
    }

    private function isSecurityGateIgnored(string $source, int $line, string $label): bool
    {
        $lines = preg_split('/\R/', $source) ?: [];
        $start = max(1, $line - 2);
        $label = strtolower($label);

        for ($i = $start; $i <= $line; $i++) {
            $text = $lines[$i - 1] ?? '';
            if ($this->lineHasSecurityGateIgnore($text, $label)) {
                return true;
            }
        }

        return false;
    }

    private function lineHasSecurityGateIgnore(string $line, string $label): bool
    {
        if (preg_match('/security-gate:ignore(?:\s+([A-Za-z0-9_,| -]+))?/i', $line, $match) !== 1) {
            return false;
        }

        $scopesRaw = trim((string) ($match[1] ?? ''));
        if ($scopesRaw === '') {
            return true;
        }

        $scopes = preg_split('/[\s,|]+/', strtolower($scopesRaw)) ?: [];
        $scopes = array_values(array_filter($scopes, static fn (string $value): bool => $value !== ''));

        return in_array('all', $scopes, true) || in_array($label, $scopes, true);
    }
}
