<?php

declare(strict_types=1);

namespace App\Services\Enneagram\AuthorityV2;

use App\Models\PersonalityPublicContentAsset;
use App\Services\Cms\PersonalityPublicAssetReadModelCache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class EnneagramPublicAuthorityV224CacheCoordinator
{
    public const ARTIFACT = 'ENNEAGRAM-PUBLIC-AUTHORITY-V2-CACHE-COORDINATOR-22E';

    public function __construct(
        private readonly PersonalityPublicAssetReadModelCache $cache,
        private readonly EnneagramPublicAuthorityV224RuntimeManifest $manifest,
    ) {}

    /** @param array<string,mixed> $releaseReport @return array<string,mixed> */
    public function invalidatePreservingLkg(array $releaseReport): array
    {
        $targets = $this->targets($releaseReport);
        $collections = [];
        foreach ($targets as $target) {
            $asset = $this->asset($target);
            $this->cache->invalidateAsset(
                PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
                (string) $asset->entity_type,
                (string) $asset->entity_key,
                (string) $asset->slug,
                (string) $asset->locale,
                0,
                true,
            );
            $collectionKey = (string) $asset->entity_type.'|'.(string) $asset->locale;
            if (! isset($collections[$collectionKey])) {
                $this->cache->invalidateCollections(
                    PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
                    (string) $asset->entity_type,
                    (string) $asset->locale,
                    0,
                    true,
                );
                $collections[$collectionKey] = true;
            }
        }

        return [
            'artifact' => self::ARTIFACT,
            'ok' => true,
            'status' => 'PASS_ACTIVE_POINTER_INVALIDATION_LKG_PRESERVED',
            'detail_asset_count' => count($targets),
            'detail_pointer_count' => count($targets) * 2,
            'collection_family_count' => count($collections),
            'lkg_preserved' => true,
            'writes_committed' => true,
        ];
    }

    /** @param array<string,mixed> $releaseReport @return array<string,mixed> */
    public function warmAndVerifyFresh(array $releaseReport, string $apiBaseUrl): array
    {
        $targets = $this->targets($releaseReport);
        foreach ($targets as $target) {
            $url = $this->apiUrl($apiBaseUrl, $target);
            $warm = Http::acceptJson()->timeout(20)->get($url);
            if (! $warm->successful() || ($warm->json('ok') ?? false) !== true) {
                throw new RuntimeException('Backend cache warm failed: '.(string) $target['asset_key'].'.');
            }
            $fresh = Http::acceptJson()->timeout(20)->get($url);
            if (! $fresh->successful()
                || ($fresh->json('ok') ?? false) !== true
                || strtolower((string) $fresh->header('X-Fermat-Public-Read-Cache')) !== 'fresh') {
                throw new RuntimeException('Backend cache fresh readback failed: '.(string) $target['asset_key'].'.');
            }
        }

        return [
            'artifact' => self::ARTIFACT,
            'ok' => true,
            'status' => 'PASS_BACKEND_WARM_THEN_FRESH_READBACK',
            'warm_read_count' => count($targets),
            'fresh_read_count' => count($targets),
            'fresh_header_verified_count' => count($targets),
            'writes_committed' => false,
        ];
    }

    /** @param array<string,mixed> $releaseReport @return array<string,mixed> */
    public function revalidateFrontend(array $releaseReport, string $endpoint, string $secret): array
    {
        $paths = array_map(
            static fn (array $target): string => (string) $target['path'],
            $this->targets($releaseReport),
        );
        if (count($paths) !== EnneagramPublicAuthorityV224RuntimeManifest::TARGET_COUNT
            || count(array_unique($paths)) !== EnneagramPublicAuthorityV224RuntimeManifest::TARGET_COUNT) {
            throw new RuntimeException('Frontend revalidation requires exactly 116 unique public paths.');
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
            'cache_signal' => ['paths' => $paths],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->getTimestamp();
        $nonce = bin2hex(random_bytes(24));
        $signature = hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$body, $secret);
        $response = Http::acceptJson()
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
            || array_values($accepted) !== $paths
            || ! is_array($rejected)
            || $rejected !== []) {
            throw new RuntimeException('Frontend exact-path HMAC revalidation failed or rejected one or more paths.');
        }

        return [
            'artifact' => self::ARTIFACT,
            'ok' => true,
            'status' => 'PASS_EXACT_HMAC_FRONTEND_REVALIDATION',
            'accepted_count' => count($accepted),
            'rejected_count' => 0,
            'path_set_sha256' => $this->fingerprint($paths),
            'secret_output' => false,
            'nonce_output' => false,
            'signature_output' => false,
            'writes_committed' => true,
        ];
    }

    /** @param array<string,mixed> $releaseReport @return list<array<string,mixed>> */
    private function targets(array $releaseReport): array
    {
        $batches = $this->manifest->readbackBatches($releaseReport);

        return array_merge(...array_values($batches));
    }

    /** @param array<string,mixed> $target */
    private function asset(array $target): PersonalityPublicContentAsset
    {
        $asset = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM)
            ->where('entity_type', (string) $target['entity_type'])
            ->where('entity_key', (string) $target['code'])
            ->where('locale', (string) $target['locale'])
            ->first();
        if (! $asset instanceof PersonalityPublicContentAsset) {
            throw new RuntimeException('Cache target is missing: '.(string) $target['asset_key'].'.');
        }

        return $asset;
    }

    /** @param array<string,mixed> $target */
    private function apiUrl(string $baseUrl, array $target): string
    {
        return rtrim($baseUrl, '/').'/api/v0.5/personality-content-assets?'.http_build_query([
            'org_id' => 0,
            'locale' => (string) $target['locale'],
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            'entity_type' => (string) $target['entity_type'],
            'code' => (string) $target['code'],
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function fingerprint(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
