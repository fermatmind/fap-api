<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final class CrawlerLogPrivacySanitizer
{
    public function normalizePath(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $withoutQuery = parse_url($path, PHP_URL_PATH);

        if (! is_string($withoutQuery) || $withoutQuery === '') {
            return null;
        }

        return str_starts_with($withoutQuery, '/') ? $withoutQuery : '/'.$withoutQuery;
    }

    public function pathHash(?string $path): ?string
    {
        $normalized = $this->normalizePath($path);

        return $normalized === null ? null : hash('sha256', $normalized);
    }

    public function pathDisplayMasked(?string $path): ?string
    {
        $normalized = $this->normalizePath($path);

        if ($normalized === null) {
            return null;
        }

        return preg_replace('/\/[A-Za-z0-9_-]{18,}(?=\/|$)/', '/:masked', $normalized) ?: $normalized;
    }

    public function userAgentHash(?string $userAgent): ?string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return null;
        }

        return hash('sha256', $userAgent);
    }

    public function responseTimeBucket(?int $responseTimeMs): ?string
    {
        if ($responseTimeMs === null) {
            return null;
        }

        return match (true) {
            $responseTimeMs < 100 => 'lt_100ms',
            $responseTimeMs < 500 => '100_499ms',
            $responseTimeMs < 1000 => '500_999ms',
            $responseTimeMs < 3000 => '1_2999ms',
            default => 'gte_3000ms',
        };
    }

    public function isPrivateFlowPath(?string $path): bool
    {
        $normalized = strtolower((string) $this->normalizePath($path));

        if ($normalized === '') {
            return false;
        }

        foreach (['/take', '/result', '/orders', '/share', '/pay', '/checkout', '/report'] as $privatePrefix) {
            if ($normalized === $privatePrefix || str_contains($normalized, $privatePrefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
