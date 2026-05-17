<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Drift;

final class CrawlerLogLineParser
{
    public function __construct(
        private readonly CrawlerUserAgentClassifier $classifier,
    ) {}

    /**
     * @return array{
     *     bot_family: string,
     *     path: string|null,
     *     status_code: int|null,
     *     response_time_ms: int|null,
     *     method: string|null,
     *     timestamp: string|null,
     *     user_agent_hash: string|null,
     *     exposes_raw_ip: false,
     *     exposes_cookies: false
     * }
     */
    public function parse(string $line): array
    {
        $request = $this->firstMatch('/"([A-Z]+\s+[^"\s]+\s+HTTP\/[0-9.]+)" /', $line);
        $method = null;
        $path = null;

        if ($request !== null && preg_match('/^([A-Z]+)\s+([^\s]+)\s+HTTP\/[0-9.]+$/', $request, $requestParts) === 1) {
            $method = $requestParts[1];
            $path = parse_url($requestParts[2], PHP_URL_PATH) ?: null;
        }

        $userAgent = $this->lastQuotedSegment($line);

        return [
            'bot_family' => $this->classifier->classify($userAgent ?? ''),
            'path' => $path,
            'status_code' => $this->statusCode($line),
            'response_time_ms' => $this->responseTimeMs($line),
            'method' => $method,
            'timestamp' => $this->firstMatch('/\[([^\]]+)\]/', $line),
            'user_agent_hash' => $userAgent === null ? null : hash('sha256', $userAgent),
            'exposes_raw_ip' => false,
            'exposes_cookies' => false,
        ];
    }

    private function statusCode(string $line): ?int
    {
        if (preg_match('/"\s+([1-5][0-9]{2})\s+/', $line, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function responseTimeMs(string $line): ?int
    {
        if (preg_match('/(?:request_time=|rt=)([0-9.]+)/', $line, $matches) !== 1) {
            return null;
        }

        return (int) round(((float) $matches[1]) * 1000);
    }

    private function firstMatch(string $pattern, string $subject): ?string
    {
        if (preg_match($pattern, $subject, $matches) !== 1) {
            return null;
        }

        $value = trim((string) $matches[1]);

        return $value === '' ? null : $value;
    }

    private function lastQuotedSegment(string $line): ?string
    {
        if (preg_match_all('/"([^"]*)"/', $line, $matches) < 1) {
            return null;
        }

        $segments = array_values(array_filter(
            $matches[1] ?? [],
            static fn (string $segment): bool => $segment !== ''
        ));

        if ($segments === []) {
            return null;
        }

        return end($segments) ?: null;
    }
}
