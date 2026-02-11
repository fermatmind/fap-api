<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DisableLegacyV02Report
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isProduction()) {
            abort(404);
        }

        if (config('features.enable_v0_2_report') !== true) {
            abort(404);
        }

        return $next($request);
    }
}
