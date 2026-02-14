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
        '/api/v0.2/attempts',
        '/api/v0.2/attempts/start',
        '/api/v0.2/attempts/{id}/start',
        '/api/v0.2/shares/{shareId}/click',
        '/api/v0.2/auth/wx_phone',
        '/api/v0.2/auth/phone/send_code',
        '/api/v0.2/auth/phone/verify',
        '/api/v0.2/auth/provider',
        '/api/v0.2/events',
        '/api/v0.2/webhooks/{provider}',
        '/api/v0.3/webhooks/payment/{provider}',
        '/api/v0.3/attempts/start',
        '/api/v0.3/attempts/{attempt_id}/progress',
        '/api/v0.3/orders/stub',
    ];

    /** @var array<int, string> */
    private const AUTH_MIDDLEWARE_NEEDLES = [
        'auth:',
        'FmTokenAuth',
        'AdminAuth',
        'IntegrationsIngestAuth',
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

    public function test_state_changing_routes_require_auth_or_explicit_allowlist(): void
    {
        $violations = [];

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $methods = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));
            if (! $this->hasMutatingMethod($methods)) {
                continue;
            }

            $uri = '/'.ltrim((string) $route->uri(), '/');
            if (!str_starts_with($uri, '/api/')) {
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

    public function test_no_mass_assignment_from_request_all_in_controllers_or_services(): void
    {
        $scanRoots = [
            app_path('Http/Controllers'),
            app_path('Services'),
        ];

        $patterns = [
            'direct_create_request_all' => '/(?:->|::)\s*create\s*\(\s*\$request->all\(\)\s*\)/',
            'direct_update_request_all' => '/(?:->|::)\s*update\s*\(\s*\$request->all\(\)\s*\)/',
            'direct_fill_request_all' => '/(?:->|::)\s*fill\s*\(\s*\$request->all\(\)\s*\)/',
            'request_helper_all' => '/(?:->|::)\s*(?:create|update|fill)\s*\(\s*request\(\)->all\(\)\s*\)/',
            'assigned_request_all_then_sink' => '/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*\$request->all\(\)\s*;[\s\S]{0,240}?(?:->|::)\s*(?:create|update|fill)\s*\(\s*\$\1\s*\)/m',
        ];

        $violations = [];

        foreach ($scanRoots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $path = $file->getPathname();
                $source = file_get_contents($path);
                if (!is_string($source)) {
                    continue;
                }

                foreach ($patterns as $label => $regex) {
                    if (preg_match($regex, $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
                        continue;
                    }

                    $line = 1 + substr_count(substr($source, 0, (int) $match[0][1]), "\n");
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
            if (!is_file($absolute)) {
                continue;
            }

            $source = file_get_contents($absolute);
            if (!is_string($source)) {
                continue;
            }

            foreach ($patterns as $label => $regex) {
                if (preg_match($regex, $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }

                $line = 1 + substr_count(substr($source, 0, (int) $match[0][1]), "\n");
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

    /**
     * @param array<int, string> $methods
     */
    private function hasMutatingMethod(array $methods): bool
    {
        return count(array_intersect(self::MUTATING_METHODS, $methods)) > 0;
    }

    /**
     * @param array<int, string> $middlewares
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
}
