<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ForcePublicAttemptRealm
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $flag = is_object($route) ? ($route->defaults['public_realm'] ?? null) : null;
        $hasExplicitTenantOrgSignal = $this->hasExplicitTenantOrgSignal($request);

        if (($flag === true || $flag === 1 || $flag === '1') && ! $hasExplicitTenantOrgSignal) {
            $request->attributes->set('force_public_attempt_realm', true);
        }

        return $next($request);
    }

    private function hasExplicitTenantOrgSignal(Request $request): bool
    {
        $candidates = [
            $request->header('X-FM-Org-Id'),
            $request->header('X-Org-Id'),
            $request->query('org_id'),
            $request->route('org_id'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_int($candidate) && ! is_string($candidate) && ! is_numeric($candidate)) {
                continue;
            }

            $raw = trim((string) $candidate);
            if ($raw !== '' && preg_match('/^\d+$/', $raw) === 1 && (int) $raw > 0) {
                return true;
            }
        }

        return false;
    }
}
