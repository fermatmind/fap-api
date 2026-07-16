<?php

declare(strict_types=1);

namespace App\Services\Analytics\ProviderFreshness;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

final class ProviderHttpClient
{
    /** @param Closure(): Response $operation */
    public function send(Closure $operation): Response
    {
        $maxAttempts = min(4, max(1, (int) config('analytics.provider_freshness.max_attempts', 3)));

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $operation();
            } catch (ConnectionException) {
                if ($attempt === $maxAttempts) {
                    throw new ProviderRequestException('connection_failed');
                }

                $this->pause($attempt);

                continue;
            }

            if ($response->successful()) {
                return $response;
            }

            $status = $response->status();
            $retryable = $status === 429 || $status >= 500;
            if (! $retryable || $attempt === $maxAttempts) {
                throw new ProviderRequestException(match (true) {
                    $status === 401 => 'authentication_failed',
                    $status === 403 => 'authorization_failed',
                    $status === 429 => 'rate_limited',
                    $status >= 500 => 'provider_unavailable',
                    default => 'request_rejected',
                });
            }

            $this->pause($attempt);
        }

        throw new ProviderRequestException('request_failed');
    }

    private function pause(int $attempt): void
    {
        $base = max(0, (int) config('analytics.provider_freshness.retry_base_delay_ms', 150));
        $jitter = max(0, (int) config('analytics.provider_freshness.retry_jitter_ms', 100));
        $delay = ($base * $attempt) + ($jitter > 0 ? random_int(0, $jitter) : 0);

        if ($delay > 0) {
            usleep($delay * 1000);
        }
    }
}
