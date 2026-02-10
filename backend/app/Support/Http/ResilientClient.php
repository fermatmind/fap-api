<?php

namespace App\Support\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class ResilientClient
{
    /**
     * Build a pre-configured HTTP client for resilient outbound requests.
     */
    public static function request(): PendingRequest
    {
        return Http::connectTimeout(3)
            ->timeout(10)
            ->retry(
                [200, 400, 800],
                200,
                function (\Throwable $exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if ($exception instanceof RequestException) {
                        $status = $exception->response?->status();
                        if ($status === null) {
                            return true;
                        }

                        return $status >= 500 || in_array($status, [408, 429], true);
                    }

                    return true;
                },
                false
            );
    }

    public static function get(string $url, array $query = []): Response
    {
        $startedAt = microtime(true);

        try {
            $response = self::request()->get($url, $query);
        } catch (\Throwable $e) {
            self::logFailure($url, null, $startedAt);
            throw $e;
        }

        if (!$response->successful()) {
            self::logFailure($url, $response->status(), $startedAt);
        }

        return $response;
    }

    public static function post(string $url, array $payload = []): Response
    {
        $startedAt = microtime(true);

        try {
            $response = self::request()->post($url, $payload);
        } catch (\Throwable $e) {
            self::logFailure($url, null, $startedAt);
            throw $e;
        }

        if (!$response->successful()) {
            self::logFailure($url, $response->status(), $startedAt);
        }

        return $response;
    }

    private static function logFailure(string $url, ?int $status, float $startedAt): void
    {
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        Log::warning('http_resilient_request_failed', [
            'url' => $url,
            'status' => $status,
            'elapsed_ms' => $elapsedMs,
        ]);
    }
}
