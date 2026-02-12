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

        $payload = $response->getData(true);
        if (!is_array($payload)) {
            return $response;
        }

        $status = $response->getStatusCode();
        if (!$this->shouldNormalize($payload, $status)) {
            return $response;
        }

        $normalized = [
            'ok' => false,
            'error_code' => $this->resolveErrorCode($payload, $status),
            'message' => $this->resolveMessage($payload, $status),
            'details' => $this->resolveDetails($payload),
            'request_id' => $this->resolveRequestId($request, $payload),
        ];

        $response->setData($normalized);

        return $response;
    }

    private function resolveErrorCode(array $payload, int $status): string
    {
        $raw = '';

        if (isset($payload['error_code']) && is_string($payload['error_code'])) {
            $raw = $payload['error_code'];
        } elseif (isset($payload['error']) && is_string($payload['error'])) {
            $raw = $payload['error'];
        } elseif (
            isset($payload['error'])
            && is_array($payload['error'])
            && isset($payload['error']['code'])
            && is_string($payload['error']['code'])
        ) {
            $raw = $payload['error']['code'];
        }

        $normalized = $this->normalizeCode($raw);
        if ($normalized !== '') {
            return $normalized;
        }

        return $this->statusErrorCode($status);
    }

    private function shouldNormalize(array $payload, int $status): bool
    {
        if (($payload['ok'] ?? null) === false) {
            return true;
        }

        if ($this->hasLegacyError($payload)) {
            return true;
        }

        if ($status < 400) {
            return false;
        }

        return $this->hasErrorCode($payload) || $this->hasMessage($payload);
    }

    private function hasErrorCode(array $payload): bool
    {
        return isset($payload['error_code']) && is_string($payload['error_code']) && trim($payload['error_code']) !== '';
    }

    private function hasLegacyError(array $payload): bool
    {
        if (isset($payload['error']) && is_string($payload['error']) && trim($payload['error']) !== '') {
            return true;
        }

        return isset($payload['error']) && is_array($payload['error']);
    }

    private function hasMessage(array $payload): bool
    {
        return isset($payload['message']) && is_string($payload['message']) && trim($payload['message']) !== '';
    }

    private function resolveMessage(array $payload, int $status): string
    {
        $message = $payload['message'] ?? null;
        if (is_string($message) && trim($message) !== '') {
            return trim($message);
        }

        if (isset($payload['error']) && is_string($payload['error']) && trim($payload['error']) !== '') {
            return trim($payload['error']);
        }

        if (
            isset($payload['error'])
            && is_array($payload['error'])
            && isset($payload['error']['message'])
            && is_string($payload['error']['message'])
            && trim($payload['error']['message']) !== ''
        ) {
            return trim($payload['error']['message']);
        }

        if (isset($payload['error_code']) && is_string($payload['error_code']) && trim($payload['error_code']) !== '') {
            return trim($payload['error_code']);
        }

        return Response::$statusTexts[$status] ?? 'Request failed.';
    }

    private function resolveDetails(array $payload): array|object
    {
        if (isset($payload['details']) && is_array($payload['details'])) {
            return $this->normalizeDetails($payload['details']);
        }

        return (object) [];
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

    private function normalizeDetails(array $details): array|object
    {
        return $details === [] ? (object) [] : $details;
    }

    private function statusErrorCode(int $status): string
    {
        return match ($status) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            402 => 'PAYMENT_REQUIRED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            409 => 'CONFLICT',
            422 => 'VALIDATION_FAILED',
            429 => 'RATE_LIMITED',
            500 => 'INTERNAL_ERROR',
            default => 'HTTP_' . $status,
        };
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
}
