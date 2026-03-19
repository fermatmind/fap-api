<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\OrgContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireTenantContext
{
    public function __construct(private readonly OrgContext $orgContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->orgContext->isTenantContext()) {
            return response()->json([
                'ok' => false,
                'error_code' => 'ORG_NOT_FOUND',
                'message' => 'org not found.',
            ], 404);
        }

        $routeOrgId = $request->route('org_id');
        if (is_numeric($routeOrgId) && (int) $routeOrgId !== $this->orgContext->requirePositiveOrgId()) {
            return response()->json([
                'ok' => false,
                'error_code' => 'ORG_NOT_FOUND',
                'message' => 'org not found.',
            ], 404);
        }

        return $next($request);
    }
}
