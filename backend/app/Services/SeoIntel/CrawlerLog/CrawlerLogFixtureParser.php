<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\CrawlerLog;

use DateTimeImmutable;
use DateTimeInterface;

final class CrawlerLogFixtureParser
{
    public const PRIVACY_TRANSFORM_VERSION = 'crawler_log_privacy_transform_v1';

    /**
     * @return array{
     *     dry_run: true,
     *     writes_attempted: false,
     *     writes_committed: false,
     *     production_log_read_attempted: false,
     *     external_calls_attempted: false,
     *     search_submission_attempted: false,
     *     raw_persistence: false,
     *     parsed_line_count: int,
     *     sanitized_row_count: int,
     *     blocked_private_path_count: int,
     *     unknown_bot_count: int,
     *     bot_family_breakdown: array<string, int>,
     *     status_code_breakdown: array<string, int>,
     *     route_family_breakdown: array<string, int>,
     *     privacy_transform_version: string,
     *     sanitized_rows: list<array<string, mixed>>
     * }
     */
    public function parseLines(array $lines, string $sourceLogFamily = 'nginx_openresty_access_log'): array
    {
        $rows = [];

        foreach ($lines as $line) {
            if (! is_string($line) || trim($line) === '') {
                continue;
            }

            $rows[] = $this->parseLine($line, $sourceLogFamily);
        }

        return [
            'dry_run' => true,
            'writes_attempted' => false,
            'writes_committed' => false,
            'production_log_read_attempted' => false,
            'external_calls_attempted' => false,
            'search_submission_attempted' => false,
            'raw_persistence' => false,
            'parsed_line_count' => count($rows),
            'sanitized_row_count' => count($rows),
            'blocked_private_path_count' => count(array_filter(
                $rows,
                static fn (array $row): bool => (bool) ($row['private_path_blocked'] ?? false),
            )),
            'unknown_bot_count' => count(array_filter(
                $rows,
                static fn (array $row): bool => ($row['bot_family'] ?? null) === 'unknown_bot',
            )),
            'bot_family_breakdown' => $this->countBy($rows, 'bot_family'),
            'status_code_breakdown' => $this->countBy($rows, 'http_status'),
            'route_family_breakdown' => $this->countBy($rows, 'route_family'),
            'privacy_transform_version' => self::PRIVACY_TRANSFORM_VERSION,
            'sanitized_rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseLine(string $line, string $sourceLogFamily): array
    {
        $request = $this->request($line);
        $target = $request['target'] ?? null;
        $path = $this->normalizePath(is_string($target) ? (string) parse_url($target, PHP_URL_PATH) : null);
        $query = is_string($target) ? (string) parse_url($target, PHP_URL_QUERY) : '';
        $host = $this->host($line, $target);
        $bot = $this->bot($this->userAgent($line));
        $privatePathBlocked = $this->isPrivatePath($path);
        $staticAsset = $this->isStaticAsset($path);
        $routeFamily = $this->routeFamily($host, $path, $privatePathBlocked, $staticAsset);
        $canonical = $this->canonicalMapping($path);
        $canonicalPath = $this->canonicalPath($canonical, $routeFamily, $privatePathBlocked);

        return [
            'log_date' => $this->logDate($line),
            'host' => $host,
            'surface_family' => $this->surfaceFamily($host, $routeFamily, $privatePathBlocked, $staticAsset),
            'bot_family' => $bot['family'],
            'bot_variant' => $bot['variant'],
            'bot_verification_state' => 'ua_claim_only',
            'route_family' => $routeFamily,
            'page_entity_type' => $canonicalPath === null ? null : $canonical['page_entity_type'],
            'canonical_path' => $canonicalPath,
            'path_hash' => $canonicalPath === null ? $this->pathHash($path) : null,
            'http_status' => $this->statusCode($line),
            'method_bucket' => $this->methodBucket((string) ($request['method'] ?? '')),
            'query_present' => $query !== '',
            'query_risk_state' => $this->queryRiskState($query),
            'private_path_blocked' => $privatePathBlocked,
            'seo_candidate' => $canonicalPath !== null && $routeFamily !== 'static_asset',
            'hit_count' => 1,
            'first_seen_at' => $this->seenAt($line),
            'last_seen_at' => $this->seenAt($line),
            'source_log_family' => $sourceLogFamily,
            'privacy_transform_version' => self::PRIVACY_TRANSFORM_VERSION,
        ];
    }

    /**
     * @return array{method?: string, target?: string}
     */
    private function request(string $line): array
    {
        if (preg_match('/"(?<method>[A-Z]+)\s+(?<target>[^"\s]+)\s+HTTP\/[0-9.]+" /', $line, $matches) !== 1) {
            return [];
        }

        return [
            'method' => (string) $matches['method'],
            'target' => (string) $matches['target'],
        ];
    }

    private function host(string $line, ?string $target): string
    {
        $host = null;

        if (preg_match('/(?:^|\s)host=(?<host>[A-Za-z0-9.-]+)/', $line, $matches) === 1) {
            $host = strtolower((string) $matches['host']);
        }

        if ($host === null && is_string($target)) {
            $targetHost = parse_url($target, PHP_URL_HOST);
            $host = is_string($targetHost) ? strtolower($targetHost) : null;
        }

        return in_array($host, [
            'fermatmind.com',
            'www.fermatmind.com',
            'api.fermatmind.com',
            'ops.fermatmind.com',
        ], true) ? $host : 'unknown_host';
    }

    private function normalizePath(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        return str_starts_with($path, '/') ? $path : '/'.$path;
    }

    /**
     * @return array{family: string, variant: string}
     */
    private function bot(?string $userAgent): array
    {
        $normalized = strtolower(trim((string) $userAgent));

        if ($normalized === '') {
            return ['family' => 'unknown_user_agent', 'variant' => 'unknown'];
        }

        return match (true) {
            str_contains($normalized, 'googlebot-image') => ['family' => 'googlebot', 'variant' => 'image'],
            str_contains($normalized, 'googlebot-news'),
            str_contains($normalized, 'googlebot-video') => ['family' => 'googlebot', 'variant' => 'media'],
            str_contains($normalized, 'adsbot-google') => ['family' => 'googlebot', 'variant' => 'ads'],
            str_contains($normalized, 'mediapartners-google') => ['family' => 'googlebot', 'variant' => 'media'],
            str_contains($normalized, 'googlebot') && (str_contains($normalized, 'mobile') || str_contains($normalized, 'smartphone')) => ['family' => 'googlebot', 'variant' => 'mobile'],
            str_contains($normalized, 'googlebot') => ['family' => 'googlebot', 'variant' => 'web'],
            str_contains($normalized, 'bingbot'),
            str_contains($normalized, 'msnbot'),
            str_contains($normalized, 'bingpreview') => ['family' => 'bingbot', 'variant' => 'web'],
            str_contains($normalized, 'baiduspider-image') => ['family' => 'baiduspider', 'variant' => 'image'],
            str_contains($normalized, 'baiduspider') => ['family' => 'baiduspider', 'variant' => 'web'],
            str_contains($normalized, '360spider'),
            str_contains($normalized, 'haosouspider') => ['family' => 'so360', 'variant' => 'web'],
            str_contains($normalized, 'sogou pic') => ['family' => 'sogou', 'variant' => 'image'],
            str_contains($normalized, 'sogou') => ['family' => 'sogou', 'variant' => 'web'],
            str_contains($normalized, 'yisouspider'),
            str_contains($normalized, 'shenmaspider') => ['family' => 'shenma', 'variant' => 'web'],
            str_contains($normalized, 'yandexbot') => ['family' => 'yandex', 'variant' => 'web'],
            str_contains($normalized, 'duckduckbot') => ['family' => 'duckduckbot', 'variant' => 'web'],
            str_contains($normalized, 'applebot') => ['family' => 'applebot', 'variant' => 'web'],
            str_contains($normalized, 'bytespider') => ['family' => 'bytespider', 'variant' => 'web'],
            str_contains($normalized, 'petalbot') => ['family' => 'petalbot', 'variant' => 'web'],
            str_contains($normalized, 'facebookexternalhit') => ['family' => 'facebook_external_hit', 'variant' => 'web'],
            str_contains($normalized, 'twitterbot') => ['family' => 'twitterbot', 'variant' => 'web'],
            str_contains($normalized, 'linkedinbot') => ['family' => 'linkedinbot', 'variant' => 'web'],
            str_contains($normalized, 'bot'),
            str_contains($normalized, 'spider'),
            str_contains($normalized, 'crawler'),
            str_contains($normalized, 'slurp') => ['family' => 'unknown_bot', 'variant' => 'unknown'],
            default => ['family' => 'non_bot', 'variant' => 'unknown'],
        };
    }

    private function userAgent(string $line): ?string
    {
        if (preg_match_all('/"([^"]*)"/', $line, $matches) < 3) {
            return null;
        }

        return (string) ($matches[1][2] ?? '');
    }

    private function logDate(string $line): ?string
    {
        $seenAt = $this->dateTime($line);

        return $seenAt?->format('Y-m-d');
    }

    private function seenAt(string $line): ?string
    {
        $seenAt = $this->dateTime($line);

        return $seenAt?->format(DateTimeInterface::ATOM);
    }

    private function dateTime(string $line): ?DateTimeImmutable
    {
        if (preg_match('/\[(?<time>[^\]]+)\]/', $line, $matches) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', (string) $matches['time']);

        return $date === false ? null : $date;
    }

    private function statusCode(string $line): ?int
    {
        if (preg_match('/"\s+(?<status>[1-5][0-9]{2})\s+/', $line, $matches) !== 1) {
            return null;
        }

        return (int) $matches['status'];
    }

    private function methodBucket(string $method): string
    {
        return match ($method) {
            'GET' => 'GET',
            'HEAD' => 'HEAD',
            default => 'OTHER',
        };
    }

    private function queryRiskState(string $query): string
    {
        if ($query === '') {
            return 'none';
        }

        $keys = array_filter(array_map(
            static fn (string $part): string => strtolower((string) strtok($part, '=')),
            explode('&', $query),
        ));

        $sensitive = ['token', 'session', 'sid', 'api_key', 'apikey', 'key', 'email', 'order_no', 'attempt_id', 'payment_id', 'password'];

        if (array_intersect($keys, $sensitive) !== []) {
            return 'sensitive_key_present';
        }

        $tracking = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'fbclid', 'msclkid', 'ref'];

        return array_diff($keys, $tracking) === [] ? 'tracking_only' : 'unknown_query_present';
    }

    private function isPrivatePath(?string $path): bool
    {
        $withoutLocale = $this->pathWithoutLocale($path);

        if ($withoutLocale === null) {
            return false;
        }

        foreach ([
            '/take',
            '/result',
            '/results',
            '/order',
            '/orders',
            '/checkout',
            '/pay',
            '/payment',
            '/share',
            '/report-private',
            '/report_private',
            '/me',
            '/account',
            '/admin',
            '/ops',
            '/api',
        ] as $prefix) {
            if ($withoutLocale === $prefix || str_starts_with($withoutLocale, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    private function pathWithoutLocale(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $normalized = strtolower($path);

        foreach (['/zh-cn', '/zh', '/en'] as $localePrefix) {
            if ($normalized === $localePrefix) {
                return '/';
            }

            if (str_starts_with($normalized, $localePrefix.'/')) {
                return substr($normalized, strlen($localePrefix)) ?: '/';
            }
        }

        return $normalized;
    }

    private function routeFamily(string $host, ?string $path, bool $privatePathBlocked, bool $staticAsset): string
    {
        if ($host === 'api.fermatmind.com') {
            return 'api';
        }

        if ($host === 'ops.fermatmind.com') {
            return 'ops';
        }

        if ($staticAsset) {
            return 'static_asset';
        }

        if ($privatePathBlocked) {
            return 'blocked_private_path';
        }

        $canonical = $this->canonicalMapping($path);

        if ($canonical !== null) {
            return $canonical['route_family'];
        }

        return 'unknown_public_path';
    }

    private function surfaceFamily(string $host, string $routeFamily, bool $privatePathBlocked, bool $staticAsset): string
    {
        if ($host === 'api.fermatmind.com') {
            return 'api';
        }

        if ($host === 'ops.fermatmind.com') {
            return 'ops';
        }

        if ($staticAsset || $routeFamily === 'static_asset') {
            return 'static_asset';
        }

        if ($privatePathBlocked) {
            return 'blocked_private';
        }

        if (in_array($host, ['fermatmind.com', 'www.fermatmind.com'], true)) {
            return 'public_web';
        }

        return 'unknown';
    }

    /**
     * @return array{canonical_path: string, route_family: string, page_entity_type: string}|null
     */
    private function canonicalMapping(?string $path): ?array
    {
        $map = [
            '/en' => ['canonical_path' => '/en', 'route_family' => 'home', 'page_entity_type' => 'home'],
            '/zh' => ['canonical_path' => '/zh', 'route_family' => 'home', 'page_entity_type' => 'home'],
            '/en/tests' => ['canonical_path' => '/en/tests', 'route_family' => 'test_hub', 'page_entity_type' => 'test_hub'],
            '/zh/tests' => ['canonical_path' => '/zh/tests', 'route_family' => 'test_hub', 'page_entity_type' => 'test_hub'],
            '/en/research/mbti-personality-types-salary-turnover-report' => ['canonical_path' => '/en/research/mbti-personality-types-salary-turnover-report', 'route_family' => 'research', 'page_entity_type' => 'research_report'],
            '/zh/tests/mbti-personality-test-16-personality-types' => ['canonical_path' => '/zh/tests/mbti-personality-test-16-personality-types', 'route_family' => 'test_detail', 'page_entity_type' => 'test_detail'],
        ];

        return $path === null ? null : ($map[$path] ?? null);
    }

    /**
     * @param  array{canonical_path: string, route_family: string, page_entity_type: string}|null  $canonical
     */
    private function canonicalPath(?array $canonical, string $routeFamily, bool $privatePathBlocked): ?string
    {
        if ($canonical === null || $privatePathBlocked) {
            return null;
        }

        if (in_array($routeFamily, ['api', 'ops', 'static_asset', 'unknown_public_path', 'blocked_private_path'], true)) {
            return null;
        }

        return $canonical['canonical_path'];
    }

    private function isStaticAsset(?string $path): bool
    {
        if ($path === null) {
            return false;
        }

        return preg_match('/\.(?:css|js|map|png|jpe?g|gif|svg|webp|ico|txt)$/i', $path) === 1;
    }

    private function pathHash(?string $path): ?string
    {
        return $path === null ? null : hash('sha256', $path);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function countBy(array $rows, string $key): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $value = (string) ($row[$key] ?? 'unknown');
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }
}
