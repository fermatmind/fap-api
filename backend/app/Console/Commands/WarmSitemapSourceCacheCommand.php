<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\API\V0_5\SEO\SitemapSourceController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class WarmSitemapSourceCacheCommand extends Command
{
    public const FINGERPRINT_CACHE_KEY = 'seo:sitemap-source:warm-fingerprint:v1';

    private const FINGERPRINT_SCHEMA_VERSION = 'fermatmind.sitemap-source-warm-fingerprint.v1';

    private const PUBLIC_ENUMERATION_AUTHORITY_VERSION = 'fermatmind.backend-sitemap-generator.v1';

    private const CACHE_SCHEMA_VERSION = 'seo.sitemap-source.v1';

    private const CODE_FINGERPRINT_PATHS = [
        'app/Console/Commands/WarmSitemapSourceCacheCommand.php',
        'app/Http/Controllers/API/V0_5/SEO/SitemapSourceController.php',
        'app/Services/SEO/SitemapGenerator.php',
    ];

    protected $signature = 'seo:warm-sitemap-source-cache
        {--refresh-if-changed : Verify the exact authority fingerprint and rebuild only when it changed}
        {--json : Emit JSON output}';

    protected $description = 'Warm the SEO sitemap-source fresh and stale caches outside the HTTP request path.';

    public function handle(): int
    {
        $start = microtime(true);

        try {
            $controller = app(SitemapSourceController::class);
            $generator = app(\App\Services\SEO\SitemapGenerator::class);
            $projection = app(\App\Domain\Career\Publish\CareerRuntimePublishProjectionLookup::class);

            $lock = Cache::lock(SitemapSourceController::CACHE_KEY_LOCK, SitemapSourceController::LOCK_TTL_SECONDS);
            if (! $lock->get()) {
                return $this->emitResult('locked', 0, round(microtime(true) - $start, 3));
            }

            try {
                $authorityUrls = $generator->generateSitemapUrls();
                $payload = $controller->buildPayloadFromAuthorityUrls($authorityUrls, $projection);
                if ((int) ($payload['count'] ?? 0) < 1) {
                    throw new \RuntimeException('Generated sitemap-source payload was empty.');
                }

                if ((bool) $this->option('refresh-if-changed')) {
                    return $this->refreshIfChanged($controller, $payload, $authorityUrls, $start);
                }

                $controller->storeCache($payload);

                return $this->emitResult('warmed', (int) ($payload['count'] ?? 0), round(microtime(true) - $start, 3));
            } finally {
                $lock->release();
            }
        } catch (\Throwable $throwable) {
            $elapsed = round(microtime(true) - $start, 3);
            $controller = app(SitemapSourceController::class);
            $payload = $controller->fallbackPayload();
            $controller->storeCache($payload);

            return $this->emitResult('fallback_warmed', (int) ($payload['count'] ?? 0), $elapsed, $throwable->getMessage());
        }
    }

    /**
     * @param  array{ok: bool, source: string, count: int, items: list<array{loc: string, lastmod: string}>}  $payload
     * @param  list<array<string, mixed>>  $authorityUrls
     */
    private function refreshIfChanged(
        SitemapSourceController $controller,
        array $payload,
        array $authorityUrls,
        float $start,
    ): int {
        $fingerprint = $this->buildFingerprint($authorityUrls);
        $cachedFingerprint = Cache::get(self::FINGERPRINT_CACHE_KEY);
        $cachedPayload = Cache::get(SitemapSourceController::CACHE_KEY_FRESH);

        if (
            $this->fingerprintReceiptMatches($cachedFingerprint, $fingerprint)
            && $this->cachePayloadIsReadable($cachedPayload)
        ) {
            // The authority is unchanged, but this command is also the bounded
            // freshness owner for the derived HTTP projection. Preserve the
            // validated bytes while renewing their finite fresh-cache lease.
            $controller->storeCache($cachedPayload);

            return $this->emitResult(
                'verified_unchanged',
                (int) ($cachedPayload['count'] ?? 0),
                round(microtime(true) - $start, 3),
            );
        }

        $controller->storeCache($payload);
        $storedPayload = Cache::get(SitemapSourceController::CACHE_KEY_FRESH);
        if (! $this->cachePayloadIsReadable($storedPayload)) {
            throw new \RuntimeException('Sitemap source cache rebuild did not produce a readable backend authority payload.');
        }

        $receipt = [
            ...$fingerprint,
            'generated_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
        if (! Cache::forever(self::FINGERPRINT_CACHE_KEY, $receipt)) {
            throw new \RuntimeException('Sitemap source authority fingerprint could not be published.');
        }

        return $this->emitResult(
            'rebuilt',
            (int) ($storedPayload['count'] ?? 0),
            round(microtime(true) - $start, 3),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $authorityUrls
     * @return array{
     *   schema_version: string,
     *   fingerprint_sha256: string,
     *   public_enumeration_authority_version: string,
     *   authoritative_record_summary_sha256: string,
     *   cache_schema_version: string,
     *   code_fingerprint_sha256: string,
     *   normalization_identity_sha256: string,
     *   environment_identity_sha256: string
     * }
     */
    private function buildFingerprint(array $authorityUrls): array
    {
        $authoritySummary = collect($authorityUrls)
            ->map(static function (array $url): array {
                $slug = trim((string) ($url['slug'] ?? ''));

                return [
                    'slug' => $slug,
                    'loc' => trim((string) ($url['loc'] ?? '')),
                    // Static entry timestamps are generated with now(); their paths
                    // and the generator code hash are the actual authority identity.
                    'lastmod' => str_starts_with($slug, 'static-index:')
                        ? self::PUBLIC_ENUMERATION_AUTHORITY_VERSION
                        : trim((string) ($url['lastmod'] ?? '')),
                ];
            })
            ->sortBy([
                ['loc', 'asc'],
                ['slug', 'asc'],
            ])
            ->values()
            ->all();

        $codeHashes = [];
        foreach (self::CODE_FINGERPRINT_PATHS as $relativePath) {
            $absolutePath = base_path($relativePath);
            $digest = is_file($absolutePath) ? hash_file('sha256', $absolutePath) : false;
            if (! is_string($digest) || preg_match('/\A[a-f0-9]{64}\z/', $digest) !== 1) {
                throw new \RuntimeException(sprintf(
                    'Sitemap source fingerprint code authority is unavailable: %s.',
                    $relativePath,
                ));
            }
            $codeHashes[$relativePath] = $digest;
        }

        $components = [
            'public_enumeration_authority_version' => self::PUBLIC_ENUMERATION_AUTHORITY_VERSION,
            'authoritative_record_summary_sha256' => $this->fingerprint([
                'record_count' => count($authoritySummary),
                'published_indexable_records' => $authoritySummary,
            ]),
            'cache_schema_version' => self::CACHE_SCHEMA_VERSION,
            'code_fingerprint_sha256' => $this->fingerprint($codeHashes),
            'normalization_identity_sha256' => $this->fingerprint([
                'locales' => ['en', 'zh-CN'],
                'frontend_segments' => ['en', 'zh'],
                'cohorts' => [
                    'scales',
                    'articles',
                    'career_jobs',
                    'career_guides',
                    'personality_profiles',
                    'personality_public_content',
                    'personality_comparisons',
                    'topics',
                    'content_pages',
                    'static_index',
                ],
                'static_lastmod_identity' => self::PUBLIC_ENUMERATION_AUTHORITY_VERSION,
            ]),
            'environment_identity_sha256' => $this->fingerprint([
                'app_environment' => app()->environment(),
                'frontend_url' => rtrim((string) config('app.frontend_url', config('app.url', '')), '/'),
                'cache_default_store' => (string) config('cache.default'),
                'cache_prefix' => (string) config('cache.prefix'),
            ]),
        ];

        return [
            'schema_version' => self::FINGERPRINT_SCHEMA_VERSION,
            'fingerprint_sha256' => $this->fingerprint($components),
            ...$components,
        ];
    }

    private function cachePayloadIsReadable(mixed $payload): bool
    {
        if (! is_array($payload) || array_is_list($payload)) {
            return false;
        }

        $items = $payload['items'] ?? null;

        return ($payload['ok'] ?? false) === true
            && ($payload['source'] ?? null) === 'backend_sitemap_generator'
            && is_int($payload['count'] ?? null)
            && ($payload['count'] ?? 0) >= 1
            && is_array($items)
            && count($items) === $payload['count']
            && collect($items)->every(static fn (mixed $item): bool => is_array($item)
                && trim((string) ($item['loc'] ?? '')) !== ''
                && trim((string) ($item['lastmod'] ?? '')) !== '');
    }

    /**
     * @param  array<string, string>  $expected
     */
    private function fingerprintReceiptMatches(mixed $cached, array $expected): bool
    {
        if (! is_array($cached) || array_is_list($cached)) {
            return false;
        }

        $expectedKeys = [...array_keys($expected), 'generated_at'];
        $actualKeys = array_keys($cached);
        sort($expectedKeys);
        sort($actualKeys);
        if ($actualKeys !== $expectedKeys) {
            return false;
        }

        foreach ($expected as $field => $value) {
            if (! is_string($cached[$field] ?? null) || ! hash_equals($value, $cached[$field])) {
                return false;
            }
        }

        return is_string($cached['generated_at'] ?? null)
            && preg_match('/\A20[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $cached['generated_at']) === 1;
    }

    private function fingerprint(mixed $value): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function emitResult(string $status, int $count, float $elapsed, ?string $error = null): int
    {
        if ((bool) $this->option('json')) {
            $payload = [
                'status' => $status,
                'count' => $count,
                'elapsed_seconds' => $elapsed,
            ];
            if ($error !== null && $error !== '') {
                $payload['error'] = $error;
            }

            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $suffix = $error === null || $error === '' ? '' : " error=\"{$error}\"";
        $this->line("status={$status} count={$count} elapsed={$elapsed}s{$suffix}");

        return self::SUCCESS;
    }
}
