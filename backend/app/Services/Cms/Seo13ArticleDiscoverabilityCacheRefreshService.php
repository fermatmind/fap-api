<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionLookup;
use App\Http\Controllers\API\V0_5\SEO\LlmsController;
use App\Http\Controllers\API\V0_5\SEO\SitemapSourceController;
use App\Services\SEO\SitemapCache;
use App\Services\SEO\SitemapGenerator;
use App\Services\SeoIntel\UrlTruth\PublicCanonicalConsumerSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * @review-surface article
 */
final class Seo13ArticleDiscoverabilityCacheRefreshService
{
    public const TARGET_COUNT = 13;

    /** @var list<string> */
    private const CACHE_KEYS = [
        SitemapSourceController::CACHE_KEY_FRESH,
        SitemapSourceController::CACHE_KEY_STALE,
        SitemapCache::XML_CACHE_KEY,
        SitemapCache::ETAG_CACHE_KEY,
        SitemapCache::IDENTITY_CACHE_KEY,
        'seo:llms-txt:v1:body',
        'seo:llms-txt:v1:body:career-authority-identity',
        'seo:llms-full-txt:v1:body',
        'seo:llms-full-txt:v1:body:career-authority-identity',
    ];

    /** @var list<string> */
    private const FRONTEND_PATHS = [
        '/sitemap.xml',
        '/llms.txt',
        '/llms-full.txt',
    ];

    public function __construct(
        private readonly Seo13ArticleSchemaReleaseService $schemaReleaseService,
        private readonly SitemapGenerator $sitemapGenerator,
        private readonly SitemapSourceController $sitemapSourceController,
        private readonly CareerRuntimePublishProjectionLookup $careerProjection,
        private readonly SitemapCache $sitemapCache,
        private readonly LlmsController $llmsController,
        private readonly PublicCanonicalConsumerSnapshot $publicCanonicalSnapshot,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function preflight(): array
    {
        $authority = $this->schemaReleaseService->preflight();
        $rows = (array) ($authority['rows'] ?? []);
        $errors = [];

        if (($authority['ok'] ?? false) !== true
            || ($authority['released_count'] ?? 0) !== self::TARGET_COUNT
            || ($authority['held_count'] ?? 0) !== 0
            || ($authority['readback_complete'] ?? false) !== true) {
            $errors[] = ['code' => 'schema_release_incomplete'];
        }

        $sitemapUrls = $this->locations($this->sitemapGenerator->generateSitemapUrls());
        $llmsUrls = $this->locations($this->sitemapGenerator->generateLlmsUrls());
        $endpoints = $this->frontendEndpoints();
        $secretPresent = $this->frontendSecret() !== '';
        if ($endpoints === []) {
            $errors[] = ['code' => 'frontend_revalidation_endpoints_missing'];
        }
        if (! $secretPresent) {
            $errors[] = ['code' => 'frontend_revalidation_secret_missing'];
        }
        $safeRows = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $errors[] = ['code' => 'authority_row_invalid'];

                continue;
            }

            $articleId = (int) ($row['article_id'] ?? 0);
            $canonical = trim((string) ($row['canonical_url'] ?? ''));
            $sitemapCount = $this->occurrenceCount($sitemapUrls, $canonical);
            $llmsCount = $this->occurrenceCount($llmsUrls, $canonical);

            if (($row['schema_state'] ?? '') !== 'released') {
                $errors[] = ['article_id' => $articleId, 'code' => 'schema_not_released'];
            }
            if (($row['sitemap_eligible'] ?? false) !== true) {
                $errors[] = ['article_id' => $articleId, 'code' => 'sitemap_eligibility_not_enabled'];
            }
            if (($row['llms_eligible'] ?? false) !== true) {
                $errors[] = ['article_id' => $articleId, 'code' => 'llms_eligibility_not_enabled'];
            }
            if ($sitemapCount !== 1) {
                $errors[] = ['article_id' => $articleId, 'code' => 'sitemap_source_canonical_count_mismatch'];
            }
            if ($llmsCount !== 1) {
                $errors[] = ['article_id' => $articleId, 'code' => 'llms_source_canonical_count_mismatch'];
            }

            $safeRows[] = [
                'article_id' => $articleId,
                'slug' => (string) ($row['slug'] ?? ''),
                'locale' => (string) ($row['locale'] ?? ''),
                'published_revision_id' => (int) ($row['published_revision_id'] ?? 0),
                'canonical_url' => $canonical,
                'schema_state' => (string) ($row['schema_state'] ?? ''),
                'sitemap_eligible' => (bool) ($row['sitemap_eligible'] ?? false),
                'llms_eligible' => (bool) ($row['llms_eligible'] ?? false),
                'sitemap_source_count' => $sitemapCount,
                'llms_source_count' => $llmsCount,
            ];
        }

        if (count($safeRows) !== self::TARGET_COUNT) {
            $errors[] = ['code' => 'target_count_mismatch'];
        }

        return [
            'ok' => $errors === [],
            'target_count' => self::TARGET_COUNT,
            'state_sha256' => (string) ($authority['state_sha256'] ?? ''),
            'content_set_sha256' => (string) ($authority['content_set_sha256'] ?? ''),
            'target_set_sha256' => (string) ($authority['target_set_sha256'] ?? ''),
            'schema_released_count' => (int) ($authority['released_count'] ?? 0),
            'readback_complete' => (bool) ($authority['readback_complete'] ?? false),
            'frontend_revalidation_endpoint_count' => count($endpoints),
            'frontend_revalidation_token_present' => $secretPresent,
            'frontend_revalidation_token_output' => false,
            'apply_supported' => $errors === [],
            'rows' => $safeRows,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function apply(
        string $expectedStateSha256,
        string $expectedContentSetSha256,
        string $expectedTargetSetSha256,
    ): array {
        foreach ([$expectedStateSha256, $expectedContentSetSha256, $expectedTargetSetSha256] as $hash) {
            if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                throw new RuntimeException('discoverability_cache_expected_hash_invalid');
            }
        }

        $before = $this->preflight();
        if (($before['ok'] ?? false) !== true || ($before['apply_supported'] ?? false) !== true) {
            throw new RuntimeException('discoverability_cache_preflight_rejected');
        }
        foreach ([
            'state_sha256' => $expectedStateSha256,
            'content_set_sha256' => $expectedContentSetSha256,
            'target_set_sha256' => $expectedTargetSetSha256,
        ] as $field => $expected) {
            if (! hash_equals($expected, (string) ($before[$field] ?? ''))) {
                throw new RuntimeException('discoverability_cache_'.$field.'_drift');
            }
        }

        foreach (self::CACHE_KEYS as $key) {
            Cache::forget($key);
        }

        $sourcePayload = $this->sitemapSourceController->buildPayload(
            $this->sitemapGenerator,
            $this->careerProjection,
        );
        $this->sitemapSourceController->storeCache($sourcePayload);

        $sitemap = $this->sitemapGenerator->generate();
        $xml = (string) ($sitemap['xml'] ?? '');
        $etag = $this->sitemapCache->buildEtag(
            (string) ($sitemap['max_updated_at'] ?? ''),
            (int) ($sitemap['slug_count'] ?? 0),
            (array) ($sitemap['slug_list'] ?? []),
        );
        $this->sitemapCache->put(
            $xml,
            $etag,
            $this->sitemapGenerator->careerDiscoverabilityCacheIdentity(),
        );

        $llms = (string) $this->llmsController->llmsTxt($this->publicCanonicalSnapshot)->getContent();
        $llmsFull = (string) $this->llmsController->llmsFullTxt($this->publicCanonicalSnapshot)->getContent();

        foreach ((array) $before['rows'] as $row) {
            $canonical = (string) ($row['canonical_url'] ?? '');
            if ($this->occurrenceCount(
                $this->locations((array) ($sourcePayload['items'] ?? [])),
                $canonical,
            ) !== 1
                || substr_count($xml, '<loc>'.htmlspecialchars($canonical, ENT_XML1).'</loc>') !== 1
                || $this->textUrlOccurrenceCount($llms, $canonical) !== 1
                || $this->textUrlOccurrenceCount($llmsFull, $canonical) !== 1) {
                throw new RuntimeException('discoverability_cache_readback_failed');
            }
        }

        $this->revalidateFrontend();

        $after = $this->preflight();
        foreach (['state_sha256', 'content_set_sha256', 'target_set_sha256'] as $field) {
            if (! hash_equals((string) $before[$field], (string) ($after[$field] ?? ''))) {
                throw new RuntimeException('discoverability_cache_authority_drift');
            }
        }
        if (($after['ok'] ?? false) !== true) {
            throw new RuntimeException('discoverability_cache_after_preflight_rejected');
        }

        return [
            'before' => $before,
            'after' => $after,
            'writes' => [
                'cache_invalidation_count' => count(self::CACHE_KEYS),
                'cache_warm_write_count' => 5,
                'sitemap_cache_refresh_count' => 4,
                'llms_cache_refresh_count' => 2,
                'frontend_revalidation_count' => count(self::FRONTEND_PATHS),
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return list<string>
     */
    private function locations(array $rows): array
    {
        return collect($rows)
            ->map(static fn (array $row): string => trim((string) ($row['loc'] ?? '')))
            ->filter(static fn (string $location): bool => $location !== '')
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $locations
     */
    private function occurrenceCount(array $locations, string $canonical): int
    {
        return count(array_filter(
            $locations,
            static fn (string $location): bool => hash_equals($canonical, $location),
        ));
    }

    private function textUrlOccurrenceCount(string $body, string $canonical): int
    {
        return count(array_filter(
            preg_split('/\R/', $body) ?: [],
            static fn (string $line): bool => trim($line) === '- '.$canonical,
        ));
    }

    /**
     * @return list<string>
     */
    private function frontendEndpoints(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) config('ops.content_release_observability.cache_invalidation_urls', []),
        )));
    }

    private function revalidateFrontend(): void
    {
        $endpoints = $this->frontendEndpoints();
        $secret = $this->frontendSecret();
        if ($endpoints === [] || $secret === '') {
            throw new RuntimeException('discoverability_frontend_revalidation_not_configured');
        }

        $payload = [
            'event' => 'content_release_revalidate',
            'source' => 'seo13_article_discoverability_cache_refresh',
            'content' => [
                'type' => 'article-discoverability',
                'id' => 0,
                'org_id' => 0,
                'title' => 'SEO 13 derived discoverability cache refresh',
                'slug' => 'seo13-20260726',
                'locale' => 'zh-CN',
                'status' => 'published',
                'visibility' => 'public',
            ],
            'cache_signal' => [
                'kind' => 'invalidate',
                'paths' => self::FRONTEND_PATHS,
                'urls' => self::FRONTEND_PATHS,
            ],
        ];
        $requestBody = $this->jsonBody($payload);

        foreach ($endpoints as $endpoint) {
            $response = Http::acceptJson()
                ->timeout(15)
                ->withHeaders([
                    'X-FM-Content-Release-Source' => 'seo13_article_discoverability_cache_refresh',
                ] + $this->hmacHeaders($secret, $requestBody))
                ->withBody($requestBody, 'application/json')
                ->post($endpoint);
            $responseBody = $response->json();
            $revalidated = is_array($responseBody) ? (array) ($responseBody['revalidated_paths'] ?? []) : [];
            $rejected = is_array($responseBody) ? (array) ($responseBody['rejected_paths'] ?? []) : [];
            sort($revalidated);
            $expected = self::FRONTEND_PATHS;
            sort($expected);
            if (! $response->successful() || $rejected !== [] || $revalidated !== $expected) {
                throw new RuntimeException('discoverability_frontend_revalidation_failed');
            }
        }
    }

    private function frontendSecret(): string
    {
        $hmacSecret = trim((string) config('ops.content_release_observability.hmac_revalidation_secret', ''));
        if ($hmacSecret !== '') {
            return $hmacSecret;
        }

        return trim((string) config('ops.content_release_observability.cache_invalidation_secret', ''));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function jsonBody(array $payload): string
    {
        return (string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    /**
     * @return array<string,string>
     */
    private function hmacHeaders(string $secret, string $body): array
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$body, $secret);

        return [
            'X-FM-Content-Release-Timestamp' => $timestamp,
            'X-FM-Content-Release-Nonce' => $nonce,
            'X-FM-Content-Release-Signature' => 'sha256='.$signature,
        ];
    }
}
