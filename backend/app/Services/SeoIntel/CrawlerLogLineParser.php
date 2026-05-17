<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final class CrawlerLogLineParser
{
    public function __construct(
        private readonly ChineseCrawlerUserAgentClassifier $classifier,
    ) {}

    /**
     * @return array{
     *     timestamp: string|null,
     *     method: string|null,
     *     path: string|null,
     *     status_code: int|null,
     *     response_time_ms: int|null,
     *     user_agent_hash: string|null,
     *     bot_family: string,
     *     source_engine: string
     * }
     */
    public function parse(string $line): array
    {
        $request = $this->firstMatch('/"([A-Z]+\s+[^"\s]+\s+HTTP\/[0-9.]+)" /', $line);
        $method = null;
        $path = null;

        if ($request !== null && preg_match('/^([A-Z]+)\s+([^\s]+)\s+HTTP\/[0-9.]+$/', $request, $requestParts) === 1) {
            $method = $requestParts[1];
            $path = $requestParts[2];
        }

        $method ??= $this->firstMatch('/(?:method=)([A-Z]+)/', $line);
        $path ??= $this->firstMatch('/(?:path=)([^\s]+)/', $line);
        $path = $this->pathWithoutQuery($path);

        $userAgent = $this->lastQuotedSegment($line);
        $botFamily = $this->classifier->classify($userAgent ?? '');

        return [
            'timestamp' => $this->firstMatch('/\[([^\]]+)\]/', $line) ?? $this->firstMatch('/(?:time=|timestamp=)([^\s]+)/', $line),
            'method' => $method,
            'path' => $path,
            'status_code' => $this->statusCode($line),
            'response_time_ms' => $this->responseTimeMs($line),
            'user_agent_hash' => $userAgent === null ? null : hash('sha256', $userAgent),
            'bot_family' => $botFamily,
            'source_engine' => $this->classifier->sourceEngineFor($botFamily),
        ];
    }

    private function statusCode(string $line): ?int
    {
        if (preg_match('/"\s+([1-5][0-9]{2})\s+/', $line, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/(?:status=|status_code=)([1-5][0-9]{2})/', $line, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function pathWithoutQuery(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $normalized = parse_url($path, PHP_URL_PATH);

        if (! is_string($normalized) || $normalized === '') {
            return null;
        }

        return $normalized;
    }

    private function responseTimeMs(string $line): ?int
    {
        if (preg_match('/(?:request_time=|rt=)([0-9.]+)/', $line, $matches) === 1) {
            return (int) round(((float) $matches[1]) * 1000);
        }

        if (preg_match('/(?:response_time_ms=|duration_ms=)([0-9]+)/', $line, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
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
