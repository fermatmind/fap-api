<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class NormalizeApiErrorContract
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$request->is('api/*') || !($response instanceof JsonResponse)) {
            return $response;
        }

        $status = $response->getStatusCode();
        if ($status < 400) {
            return $response;
        }

        $payload = $response->getData(true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $normalized = [
            'ok' => false,
            'error_code' => $this->resolveErrorCode($payload),
            'message' => $this->resolveMessage($payload),
            'details' => $this->resolveDetails($payload),
            'request_id' => $this->resolveRequestId($request, $payload),
        ];

        $response->setData($normalized);

        return $response;
    }

    private function resolveErrorCode(array $payload): string
    {
        $raw = $this->firstNonEmptyString([
            $payload['error_code'] ?? null,
            is_string($payload['error'] ?? null) ? $payload['error'] : null,
            is_array($payload['error'] ?? null) ? ($payload['error']['code'] ?? null) : null,
            $payload['message'] ?? null,
        ]);
        $normalized = $this->normalizeCode($raw);
        if ($normalized !== '') {
            return $normalized;
        }

        return 'HTTP_ERROR';
    }

    private function resolveMessage(array $payload): string
    {
        $message = $this->firstNonEmptyString([
            $payload['message'] ?? null,
            is_string($payload['error'] ?? null) ? $payload['error'] : null,
            is_array($payload['error'] ?? null) ? ($payload['error']['message'] ?? null) : null,
        ]);

        return $message !== '' ? $message : 'request failed';
    }

    private function resolveDetails(array $payload): mixed
    {
        if (array_key_exists('details', $payload)) {
            return $this->normalizeDetailsValue($payload['details']);
        }

        if (array_key_exists('errors', $payload)) {
            return $this->normalizeDetailsValue($payload['errors']);
        }

        return null;
    }

    private function resolveRequestId(Request $request, array $payload): string
    {
        $bodyRequestId = trim((string) ($payload['request_id'] ?? ''));
        if ($bodyRequestId !== '') {
            return $bodyRequestId;
        }

        $requestId = trim((string) ($request->attributes->get('request_id') ?? ''));
        if ($requestId !== '') {
            return $requestId;
        }

        $requestId = trim((string) $request->header('X-Request-Id', ''));
        if ($requestId !== '') {
            return $requestId;
        }

        $requestId = trim((string) $request->header('X-Request-ID', ''));
        if ($requestId !== '') {
            return $requestId;
        }

        return (string) Str::uuid();
    }

    private function normalizeDetailsValue(mixed $details): mixed
    {
        if (is_array($details) && $details === []) {
            return null;
        }

        if (is_object($details) && count((array) $details) === 0) {
            return null;
        }

        return $details;
    }

    private function normalizeCode(string $raw): string
    {
        $code = trim($raw);
        if ($code === '') {
            return '';
        }

        $code = str_replace(['-', ' '], '_', $code);
        $code = (string) preg_replace('/[^A-Za-z0-9_]+/', '_', $code);
        $code = trim($code, '_');

        return strtoupper($code);
    }

    /**
     * @param array<int, mixed> $candidates
     */
    private function firstNonEmptyString(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) && !is_numeric($candidate)) {
                continue;
            }

            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
