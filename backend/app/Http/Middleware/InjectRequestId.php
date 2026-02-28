<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class InjectRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveRequestId($request);

        $request->attributes->set('request_id', $requestId);
        $request->headers->set('X-Request-Id', $requestId);

        Log::withContext($this->buildLogContext($request, $requestId));

        try {
            $response = $next($request);
        } catch (HttpResponseException $e) {
            $response = $e->getResponse();
            $response->headers->set('X-Request-Id', $requestId);

            throw $e;
        } finally {
            Log::withoutContext();
        }

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    private function resolveRequestId(Request $request): string
    {
        foreach ([
            (string) ($request->attributes->get('request_id') ?? ''),
            (string) $request->header('X-Request-Id', ''),
            (string) $request->header('X-Request-ID', ''),
            (string) $request->input('request_id', ''),
        ] as $candidate) {
            $value = trim($candidate);
            if ($value !== '') {
                return substr($value, 0, 128);
            }
        }

        return (string) Str::uuid();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLogContext(Request $request, string $requestId): array
    {
        $context = [
            'request_id' => $requestId,
            'http_method' => strtoupper($request->method()),
            'http_path' => (string) $request->path(),
            'ip' => (string) ($request->ip() ?? ''),
        ];

        $orgId = $request->attributes->get('org_id', $request->attributes->get('fm_org_id'));
        if (is_numeric($orgId) && (int) $orgId > 0) {
            $context['org_id'] = (int) $orgId;
        }

        $userId = $request->attributes->get('fm_user_id', $request->attributes->get('user_id'));
        if (is_string($userId) || is_numeric($userId)) {
            $uid = trim((string) $userId);
            if ($uid !== '') {
                $context['user_id'] = $uid;
            }
        }

        return $context;
    }
}
