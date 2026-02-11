<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $isError = $response->getStatusCode() >= 400 || (($payload['ok'] ?? null) === false);
        if (!$isError) {
            return $response;
        }

        $response->setData([
            'ok' => false,
            'error_code' => $this->resolveErrorCode($payload),
            'message' => $this->resolveMessage($payload),
            'details' => $this->resolveDetails($payload),
        ]);

        return $response;
    }

    private function resolveErrorCode(array $payload): string
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
        return $normalized !== '' ? $normalized : 'GENERIC_ERROR';
    }

    private function resolveMessage(array $payload): string
    {
        $message = $payload['message'] ?? null;
        if (is_string($message) && trim($message) !== '') {
            return trim($message);
        }

        return 'request failed.';
    }

    private function resolveDetails(array $payload): array|object
    {
        if (isset($payload['details']) && is_array($payload['details'])) {
            return $this->normalizeDetails($payload['details']);
        }

        if (isset($payload['errors']) && is_array($payload['errors'])) {
            return $this->normalizeDetails($payload['errors']);
        }

        return (object) [];
    }

    private function normalizeDetails(array $details): array|object
    {
        return $details === [] ? (object) [] : $details;
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
