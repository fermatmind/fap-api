<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireFapFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $feature = strtolower(trim($feature));
        if ($feature === '') {
            abort(404);
        }

        if ((bool) config("fap.features.{$feature}", false) !== true) {
            abort(404);
        }

        return $next($request);
    }
}

