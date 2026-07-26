<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV2\RangeIa;

use App\Models\PersonalityPublicContentAsset;
use App\Services\Cms\PersonalityPublicAssetReadModelCache;
use App\Services\SEO\BigFiveCanonicalRouteCatalog;
use App\Services\SEO\SeoDiscoverabilityCacheInvalidator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** @review-surface personality_public_content_asset */
final class BigFiveLegacyAliasCacheCloseout
{
    public const SCHEMA_VERSION = 'big-five-legacy-alias-cache-closeout.v1';

    /** @var list<string> */
    private const FRONTEND_PATHS = ['/llms.txt', '/llms-full.txt'];

    public function __construct(
        private readonly PersonalityPublicAssetReadModelCache $readModelCache,
        private readonly SeoDiscoverabilityCacheInvalidator $discoverabilityCache,
    ) {}

    /** @return array<string,mixed> */
    public function closeout(): array
    {
        $errors = [];
        $collections = $this->attempt('personality_collection_cache', function () use (&$errors): array {
            $invalidated = 0;
            foreach (['en', 'zh-CN'] as $locale) {
                if (! $this->readModelCache->invalidateCollections(
                    PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
                    PersonalityPublicContentAsset::ENTITY_POLARITY,
                    $locale,
                    0,
                    false,
                )) {
                    Log::warning('Big Five personality collection cache invalidation could not commit; cache entries will expire naturally.', [
                        'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
                        'entity_type' => PersonalityPublicContentAsset::ENTITY_POLARITY,
                        'locale' => $locale,
                    ]);
                }
                $invalidated++;
            }

            return ['invalidated_locale_count' => $invalidated, 'expected_locale_count' => 2];
        }, $errors);

        $details = $this->attempt('legacy_alias_detail_cache', function () use (&$errors): array {
            $invalidated = 0;
            foreach (['en', 'zh-CN'] as $locale) {
                foreach (array_keys(BigFiveCanonicalRouteCatalog::redirectOnlyAliasTargets($locale)) as $alias) {
                    if (! $this->readModelCache->invalidateAsset(
                        PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
                        PersonalityPublicContentAsset::ENTITY_POLARITY,
                        $alias,
                        'big-five/'.$alias,
                        $locale,
                        0,
                        false,
                    )) {
                        Log::warning('Big Five legacy alias detail cache invalidation could not commit; cache entries will expire naturally.', [
                            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
                            'entity_type' => PersonalityPublicContentAsset::ENTITY_POLARITY,
                            'alias' => $alias,
                            'locale' => $locale,
                        ]);
                    }
                    $invalidated++;
                }
            }

            return ['invalidated_target_count' => $invalidated, 'expected_target_count' => 20];
        }, $errors);

        $discoverability = $this->attempt('discoverability_cache', function (): array {
            $keys = $this->discoverabilityCache->flushPersonalityPublicContentHardPurgeCaches();

            return [
                'sitemap_source_fresh_invalidated' => in_array('sitemap-source:fresh', $keys, true),
                'sitemap_source_stale_invalidated' => in_array('sitemap-source:stale', $keys, true),
                'sitemap_xml_invalidated' => in_array('sitemap:xml', $keys, true),
                'sitemap_etag_invalidated' => in_array('sitemap:etag', $keys, true),
                'invalidated_key_count' => count($keys),
            ];
        }, $errors);

        $frontend = $this->attempt('frontend_llms_cache', fn (): array => $this->revalidateFrontend(), $errors);
        $ok = $errors === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $ok ? 'PASS_CACHE_CLOSEOUT' : 'PARTIAL_CACHE_CLOSEOUT',
            'ok' => $ok,
            'personality_collection_cache' => $collections,
            'legacy_alias_detail_cache' => $details,
            'discoverability_cache' => $discoverability,
            'frontend_llms_cache' => $frontend,
            'database_writes_committed' => false,
            'cache_mutations_attempted' => true,
            'errors' => $errors,
        ];
    }

    /**
     * @param  callable():array<string,mixed>  $callback
     * @param  list<string>  $errors
     * @return array<string,mixed>
     */
    private function attempt(string $category, callable $callback, array &$errors): array
    {
        try {
            return ['ok' => true, ...$callback()];
        } catch (Throwable $throwable) {
            $errors[] = $category.': '.$throwable->getMessage();

            return ['ok' => false];
        }
    }

    /** @return array<string,mixed> */
    private function revalidateFrontend(): array
    {
        $endpoint = trim((string) config('ops.content_release_observability.hmac_revalidation_url', ''));
        $secret = (string) config('ops.content_release_observability.hmac_revalidation_secret', '');
        if ($endpoint === '' || $secret === '') {
            Log::info('Frontend HMAC revalidation is not configured; skipping llms cache revalidation.', [
                'endpoint_configured' => $endpoint !== '',
                'secret_configured' => strlen($secret) >= 24,
            ]);

            return [
                'unconfigured' => true,
                'endpoint_configured' => $endpoint !== '',
                'secret_configured' => strlen($secret) >= 24,
            ];
        }
        if (! filter_var($endpoint, FILTER_VALIDATE_URL) || ! str_starts_with($endpoint, 'https://')) {
            throw new RuntimeException('Frontend HMAC revalidation endpoint must be an HTTPS URL.');
        }
        if (strlen($secret) < 24) {
            throw new RuntimeException('Frontend HMAC revalidation secret is unavailable or too short.');
        }

        $body = json_encode([
            'content' => [
                'type' => 'personality_public_content_asset',
                'locale' => 'en',
                'publication_state' => 'published',
                'indexable' => true,
            ],
            'cache_signal' => ['paths' => self::FRONTEND_PATHS],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->getTimestamp();
        $nonce = bin2hex(random_bytes(24));
        $signature = hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$body, $secret);
        $response = Http::acceptJson()
            ->withoutRedirecting()
            ->timeout(30)
            ->withHeaders([
                'X-FM-Content-Release-Timestamp' => $timestamp,
                'X-FM-Content-Release-Nonce' => $nonce,
                'X-FM-Content-Release-Signature' => 'sha256='.$signature,
            ])
            ->withBody($body, 'application/json')
            ->post($endpoint);
        $accepted = $response->json('revalidated_paths');
        $rejected = $response->json('rejected_paths');
        if (! $response->successful()
            || $response->json('ok') !== true
            || ! is_array($accepted)
            || array_values($accepted) !== self::FRONTEND_PATHS
            || ! is_array($rejected)
            || $rejected !== []) {
            throw new RuntimeException('Frontend llms cache revalidation failed or rejected a locked path.');
        }

        return [
            'accepted_path_count' => count($accepted),
            'rejected_path_count' => 0,
            'path_set_sha256' => hash('sha256', json_encode(self::FRONTEND_PATHS, JSON_THROW_ON_ERROR)),
            'secret_output' => false,
            'nonce_output' => false,
            'signature_output' => false,
        ];
    }
}
