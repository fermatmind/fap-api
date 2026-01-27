<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AttachRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) ($request->attributes->get('request_id') ?? '');
        if ($requestId === '') {
            $requestId = (string) $request->header('X-Request-Id', '');
        }
        if ($requestId === '') {
            $requestId = (string) $request->header('X-Request-ID', '');
        }
        if ($requestId === '') {
            $requestId = (string) $request->input('request_id', '');
        }
        if ($requestId === '') {
            $requestId = (string) Str::uuid();
        }

        $request->attributes->set('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
