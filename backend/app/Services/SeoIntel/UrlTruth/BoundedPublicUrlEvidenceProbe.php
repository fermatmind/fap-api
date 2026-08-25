<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\UrlTruth;

use App\Services\SeoIntel\UrlTruthInventoryRecord;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class BoundedPublicUrlEvidenceProbe
{
    /**
     * @param  list<UrlTruthInventoryRecord>  $records
     * @return array{consumer_urls:array<string,list<string>|null>,live_http:array<string,mixed>}
     */
    public function collect(
        array $records,
        ?string $resumeCursor = null,
        int $limit = 50,
        int $concurrency = 4,
        int $timeoutSeconds = 10,
        int $maxRetries = 1,
    ): array {
        $limit = max(1, min($limit, 100));
        $concurrency = max(1, min($concurrency, 4));
        $timeoutSeconds = max(1, min($timeoutSeconds, 15));
        $maxRetries = max(0, min($maxRetries, 2));
        $base = rtrim((string) config('seo_intel.public_canonical_host', 'https://fermatmind.com'), '/');
        $apiBase = rtrim((string) config('app.public_api_url', 'https://api.fermatmind.com'), '/');

        $effective = [];
        $counterparts = [];
        $evaluator = new EffectivePublicUrlEvaluator;
        foreach ($records as $record) {
            if (($evaluator->evaluate($record)['effective_public'] ?? false) !== true) {
                continue;
            }
            $cursor = $record->canonicalUrlHash();
            $effective[$cursor] = $record;
            $identity = $this->entityIdentity($record);
            $counterparts[$identity][$record->locale] = $record->canonicalUrl;
        }
        ksort($effective);
        if ($resumeCursor !== null && preg_match('/^[a-f0-9]{64}$/', $resumeCursor) === 1) {
            $effective = array_filter($effective, static fn (string $key): bool => strcmp($key, $resumeCursor) > 0, ARRAY_FILTER_USE_KEY);
        }
        $batch = array_slice($effective, 0, $limit, true);

        $surfaceUrls = [
            'public_api' => $apiBase.'/api/v0.5/seo/sitemap-source',
            'sitemap' => $base.'/sitemap.xml',
            'llms' => $base.'/llms.txt',
            'llms_full' => $base.'/llms-full.txt',
        ];
        $surfaceResponses = $this->fetch($surfaceUrls, $concurrency, $timeoutSeconds, $maxRetries, 'application/json,text/plain,application/xml');
        $consumerUrls = [
            'public_api' => $this->publicApiUrls($surfaceResponses['public_api'] ?? null),
            'sitemap' => $this->documentUrls($surfaceResponses['sitemap'] ?? null),
            'llms' => $this->documentUrls($surfaceResponses['llms'] ?? null),
            'llms_full' => $this->documentUrls($surfaceResponses['llms_full'] ?? null),
        ];

        $pageTargets = [];
        foreach ($batch as $cursor => $record) {
            $pageTargets[$cursor] = $record->canonicalUrl;
        }
        $pageResponses = $this->fetch($pageTargets, $concurrency, $timeoutSeconds, $maxRetries, 'text/html');
        $issues = [];
        $healthy = 0;
        foreach ($batch as $cursor => $record) {
            $response = $pageResponses[$cursor] ?? null;
            $identity = $this->entityIdentity($record);
            $counterpartLocale = $record->locale === 'zh-CN' ? 'en' : 'zh-CN';
            $pageIssues = $this->pageIssues(
                $record,
                $response,
                $counterparts[$identity][$counterpartLocale] ?? null,
                $counterpartLocale,
            );
            foreach ($pageIssues as $issue) {
                $issues[$issue] = ($issues[$issue] ?? 0) + 1;
            }
            if ($pageIssues === []) {
                $healthy++;
            }
        }
        ksort($issues);
        $keys = array_keys($batch);
        $nextCursor = count($batch) === $limit && $keys !== [] ? end($keys) : null;

        return [
            'consumer_urls' => $consumerUrls,
            'live_http' => [
                'state' => $batch === [] ? 'available' : 'available',
                'bounded' => true,
                'requested_count' => count($batch),
                'healthy_count' => $healthy,
                'issue_count' => array_sum($issues),
                'issue_type_counts' => $issues,
                'concurrency' => $concurrency,
                'timeout_seconds' => $timeoutSeconds,
                'max_retries' => $maxRetries,
                'resume_cursor_supplied' => $resumeCursor !== null,
                'next_resume_cursor' => $nextCursor,
                'complete' => $nextCursor === null,
                'redirects_followed' => false,
                'raw_url_emitted' => false,
                'response_body_emitted' => false,
            ],
        ];
    }

    /**
     * @param  array<string,string>  $targets
     * @return array<string,Response|null>
     */
    private function fetch(array $targets, int $concurrency, int $timeoutSeconds, int $maxRetries, string $accept): array
    {
        $responses = array_fill_keys(array_keys($targets), null);
        $pending = $targets;
        for ($attempt = 0; $attempt <= $maxRetries && $pending !== []; $attempt++) {
            $next = [];
            foreach (array_chunk($pending, $concurrency, true) as $chunk) {
                $batch = Http::pool(function (Pool $pool) use ($chunk, $timeoutSeconds, $accept): void {
                    foreach ($chunk as $key => $url) {
                        $pool->as((string) $key)
                            ->accept($accept)
                            ->withUserAgent('FermatMind-SEO-URL-Truth-Reconcile/1.0')
                            ->connectTimeout(min(5, $timeoutSeconds))
                            ->timeout($timeoutSeconds)
                            ->withOptions(['allow_redirects' => false])
                            ->get($url);
                    }
                });
                foreach ($chunk as $key => $url) {
                    $response = $batch[$key] ?? null;
                    if ($response instanceof Response && $response->status() < 500 && $response->status() !== 429) {
                        $responses[$key] = $response;
                    } else {
                        $next[$key] = $url;
                    }
                }
            }
            $pending = $next;
        }

        return $responses;
    }

    /** @return list<string>|null */
    private function publicApiUrls(?Response $response): ?array
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return null;
        }
        $payload = $response->json();
        if (! is_array($payload) || ($payload['ok'] ?? false) !== true || ! is_array($payload['items'] ?? null)) {
            return null;
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_array($item) ? trim((string) ($item['loc'] ?? '')) : '',
            $payload['items'],
        )));
    }

    /** @return list<string>|null */
    private function documentUrls(?Response $response): ?array
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return null;
        }
        preg_match_all('#https://fermatmind\.com[^\s<"\']*#', $response->body(), $matches);

        return array_values(array_unique(array_map(
            static fn (string $url): string => rtrim(html_entity_decode($url), '.,'),
            $matches[0] ?? [],
        )));
    }

    /** @return list<string> */
    private function pageIssues(
        UrlTruthInventoryRecord $record,
        ?Response $response,
        ?string $counterpartUrl,
        string $counterpartLocale,
    ): array {
        if (! $response instanceof Response) {
            return ['transport_unavailable'];
        }
        if ($response->status() >= 300 && $response->status() < 400) {
            return ['redirect_only_or_unexpected_redirect'];
        }
        if ($response->status() !== 200) {
            return ['http_status_not_200'];
        }

        $body = $response->body();
        $issues = [];
        $canonical = $this->link($body, 'canonical');
        if ($canonical === null || ! hash_equals($this->normalizedUrl($record->canonicalUrl), $this->normalizedUrl($canonical))) {
            $issues[] = $canonical === null ? 'canonical_missing' : 'canonical_mismatch';
        }
        $robots = strtolower($this->meta($body, 'robots').' '.$response->header('X-Robots-Tag'));
        if ($robots === ' ' || str_contains($robots, 'noindex')) {
            $issues[] = $robots === ' ' ? 'robots_missing' : 'robots_noindex';
        }
        if (! $this->hasHreflang($body, $record->locale)) {
            $issues[] = 'hreflang_self_missing';
        }
        if ($counterpartUrl === null) {
            $issues[] = 'locale_counterpart_missing_authority';
        } elseif (! $this->hasHreflang($body, $counterpartLocale, $counterpartUrl)) {
            $issues[] = 'hreflang_counterpart_missing_or_drift';
        }

        return array_values(array_unique($issues));
    }

    private function link(string $body, string $rel): ?string
    {
        if (preg_match('/<link\b(?=[^>]*\brel=["\'][^"\']*'.preg_quote($rel, '/').'[^"\']*["\'])(?=[^>]*\bhref=["\']([^"\']+)["\'])[^>]*>/i', $body, $match) !== 1) {
            return null;
        }

        return html_entity_decode((string) $match[1]);
    }

    private function meta(string $body, string $name): string
    {
        if (preg_match('/<meta\b(?=[^>]*\bname=["\']'.preg_quote($name, '/').'["\'])(?=[^>]*\bcontent=["\']([^"\']*)["\'])[^>]*>/i', $body, $match) !== 1) {
            return '';
        }

        return (string) $match[1];
    }

    private function hasHreflang(string $body, string $locale, ?string $expectedUrl = null): bool
    {
        $expected = $locale === 'zh-CN' ? 'zh-CN' : 'en';
        if (preg_match_all('/<link\b(?=[^>]*\brel=["\'][^"\']*alternate[^"\']*["\'])(?=[^>]*\bhreflang=["\']'.preg_quote($expected, '/').'["\'])(?=[^>]*\bhref=["\']([^"\']+)["\'])[^>]*>/i', $body, $matches) < 1) {
            return false;
        }
        if ($expectedUrl === null) {
            return true;
        }

        return in_array($this->normalizedUrl($expectedUrl), array_map(
            fn (string $url): string => $this->normalizedUrl($url),
            $matches[1] ?? [],
        ), true);
    }

    private function normalizedUrl(string $url): string
    {
        return rtrim(trim(html_entity_decode($url)), '/');
    }

    private function entityIdentity(UrlTruthInventoryRecord $record): string
    {
        $entity = trim((string) $record->entityIdOrSlug);

        return $record->pageEntityType.'|'.($entity === '' ? $record->canonicalUrlHash() : $entity);
    }
}
