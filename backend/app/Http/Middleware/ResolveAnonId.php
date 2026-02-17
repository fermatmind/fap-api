<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveAnonId
{
    public function handle(Request $request, Closure $next): Response
    {
        $headerAnonId = $this->normalize($request->header('X-Anon-Id'));
        $cookieAnonId = $this->normalize($request->cookie('fap_anonymous_id_v1'));

        $resolved = $headerAnonId ?? $cookieAnonId;
        if ($resolved !== null) {
            // Keep transport-level anon identity isolated from auth-bound anon_id.
            $request->attributes->set('client_anon_id', $resolved);
        }

        return $next($request);
    }

    private function normalize(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '' || strlen($trimmed) > 128) {
            return null;
        }

        $lower = mb_strtolower($trimmed, 'UTF-8');
        foreach (['todo', 'placeholder', 'fixme', 'tbd', '填这里'] as $bad) {
            if (mb_strpos($lower, $bad) !== false) {
                return null;
            }
        }

        return $trimmed;
    }
}
