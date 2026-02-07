<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureUuidRouteParams
{
    public function handle(Request $request, Closure $next, string ...$paramNames): Response
    {
        foreach ($paramNames as $paramName) {
            $raw = $request->route($paramName);
            $value = is_string($raw) || is_numeric($raw) ? trim((string) $raw) : '';

            if ($value === '' || !Str::isUuid($value)) {
                return response()->json([
                    'ok' => false,
                    'error' => 'NOT_FOUND',
                    'message' => 'Not Found',
                ], 404);
            }
        }

        return $next($request);
    }
}
