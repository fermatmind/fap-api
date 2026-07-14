<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityPublicContentAsset;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

final class PersonalityPublicAssetReadModelCache
{
    public const CACHE_KEY_PREFIX = 'public:personality:asset-read-model:v1';

    public const TTL_SECONDS = 600;

    public const LKG_TTL_SECONDS = 604800;

    private const COLLECTION_REGISTRY_LIMIT = 200;

    /**
     * @return array{state:'fresh'|'miss'|'bypass',payload:array<string,mixed>|null}
     */
    public function read(
        string $surface,
        string $framework,
        string $entityType,
        string $selector,
        string $locale,
        int $orgId,
        string $version,
    ): array {
        if (! $this->isCacheable($surface, $framework, $entityType, $selector, $locale, $orgId)) {
            $this->recordState($surface, $framework, $entityType, $locale, 'bypass');

            return ['state' => 'bypass', 'payload' => null];
        }

        try {
            $payload = Cache::get($this->key($surface, $framework, $entityType, $selector, $locale, $version));
        } catch (Throwable $throwable) {
            $this->recordState($surface, $framework, $entityType, $locale, 'bypass', $throwable);

            return ['state' => 'bypass', 'payload' => null];
        }

        if (! is_array($payload)) {
            $this->recordState($surface, $framework, $entityType, $locale, 'miss');

            return ['state' => 'miss', 'payload' => null];
        }

        try {
            Cache::put(
                $this->activeKey($surface, $framework, $entityType, $selector, $locale),
                $version,
                self::TTL_SECONDS,
            );
            if (! is_string(Cache::get($this->lkgKey($surface, $framework, $entityType, $selector, $locale)))) {
                Cache::put(
                    $this->lkgKey($surface, $framework, $entityType, $selector, $locale),
                    $version,
                    self::LKG_TTL_SECONDS,
                );
            }
        } catch (Throwable) {
            // The versioned payload is already usable. Pointer repair is best effort.
        }

        $this->recordState($surface, $framework, $entityType, $locale, 'fresh');

        return ['state' => 'fresh', 'payload' => $payload];
    }

    /**
     * @return array{state:'stale'|'miss'|'bypass',payload:array<string,mixed>|null}
     */
    public function stale(
        string $surface,
        string $framework,
        string $entityType,
        string $selector,
        string $locale,
        int $orgId,
    ): array {
        if (! $this->isCacheable($surface, $framework, $entityType, $selector, $locale, $orgId)) {
            $this->recordState($surface, $framework, $entityType, $locale, 'bypass');

            return ['state' => 'bypass', 'payload' => null];
        }

        try {
            foreach ([
                $this->activeKey($surface, $framework, $entityType, $selector, $locale),
                $this->lkgKey($surface, $framework, $entityType, $selector, $locale),
            ] as $pointerKey) {
                $version = Cache::get($pointerKey);
                $payload = is_string($version) && $version !== ''
                    ? Cache::get($this->key($surface, $framework, $entityType, $selector, $locale, $version))
                    : null;
                if (is_array($payload)) {
                    $this->recordState($surface, $framework, $entityType, $locale, 'stale');

                    return ['state' => 'stale', 'payload' => $payload];
                }
            }
        } catch (Throwable $throwable) {
            $this->recordState($surface, $framework, $entityType, $locale, 'miss', $throwable);

            return ['state' => 'miss', 'payload' => null];
        }

        $this->recordState($surface, $framework, $entityType, $locale, 'miss');

        return ['state' => 'miss', 'payload' => null];
    }

    /** @param array<string,mixed> $payload */
    public function put(
        string $surface,
        string $framework,
        string $entityType,
        string $selector,
        string $locale,
        int $orgId,
        string $version,
        array $payload,
    ): void {
        if (! $this->isCacheable($surface, $framework, $entityType, $selector, $locale, $orgId)) {
            return;
        }

        try {
            $activeKey = $this->activeKey($surface, $framework, $entityType, $selector, $locale);
            $lkgKey = $this->lkgKey($surface, $framework, $entityType, $selector, $locale);

            Cache::put(
                $this->key($surface, $framework, $entityType, $selector, $locale, $version),
                $payload,
                self::LKG_TTL_SECONDS,
            );

            $publishPointers = function () use ($activeKey, $lkgKey, $version): void {
                $previousVersion = Cache::get($activeKey);
                if (is_string($previousVersion) && $previousVersion !== '' && $previousVersion !== $version) {
                    Cache::put($lkgKey, $previousVersion, self::LKG_TTL_SECONDS);
                } elseif (! is_string(Cache::get($lkgKey))) {
                    Cache::put($lkgKey, $version, self::LKG_TTL_SECONDS);
                }
                Cache::put($activeKey, $version, self::TTL_SECONDS);
            };

            if ($surface === 'index') {
                $this->withCollectionLock(
                    $framework,
                    $entityType,
                    $locale,
                    function () use ($framework, $entityType, $selector, $locale, $publishPointers): void {
                        $this->registerCollectionSelector($framework, $entityType, $selector, $locale);
                        $publishPointers();
                    },
                );

                return;
            }

            $publishPointers();
        } catch (Throwable $throwable) {
            $this->recordState($surface, $framework, $entityType, $locale, 'bypass', $throwable);
        }
    }

    public function invalidate(
        string $surface,
        string $framework,
        string $entityType,
        string $selector,
        string $locale,
        int $orgId,
        bool $preserveLkg,
    ): void {
        if (! $this->isCacheable($surface, $framework, $entityType, $selector, $locale, $orgId)) {
            return;
        }

        try {
            $activeKey = $this->activeKey($surface, $framework, $entityType, $selector, $locale);
            $lkgKey = $this->lkgKey($surface, $framework, $entityType, $selector, $locale);
            $activeVersion = Cache::get($activeKey);

            if ($preserveLkg && is_string($activeVersion) && $activeVersion !== '') {
                Cache::put($lkgKey, $activeVersion, self::LKG_TTL_SECONDS);
            }
            Cache::forget($activeKey);
            if (! $preserveLkg) {
                Cache::forget($lkgKey);
            }

            $this->recordState(
                $surface,
                $framework,
                $entityType,
                $locale,
                $preserveLkg ? 'invalidated' : 'withdrawn',
            );
        } catch (Throwable $throwable) {
            $this->recordState($surface, $framework, $entityType, $locale, 'bypass', $throwable);
        }
    }

    public function invalidateAsset(
        string $framework,
        string $entityType,
        string $entityKey,
        string $slug,
        string $locale,
        int $orgId,
        bool $preserveLkg,
    ): void {
        $this->invalidate('detail-code', $framework, $entityType, $entityKey, $locale, $orgId, $preserveLkg);
        $this->invalidate('detail-slug', $framework, 'slug', $slug, $locale, $orgId, $preserveLkg);
    }

    public function invalidateCollections(
        string $framework,
        string $entityType,
        string $locale,
        int $orgId,
        bool $preserveLkg,
    ): void {
        if ($orgId !== 0) {
            return;
        }

        try {
            foreach (array_unique([$entityType, 'all']) as $collectionEntityType) {
                $this->withCollectionLock(
                    $framework,
                    $collectionEntityType,
                    $locale,
                    function () use (
                        $framework,
                        $collectionEntityType,
                        $locale,
                        $orgId,
                        $preserveLkg,
                    ): void {
                        $registered = Cache::get(
                            $this->collectionRegistryKey($framework, $collectionEntityType, $locale),
                            [],
                        );
                        if (! is_array($registered)) {
                            return;
                        }

                        foreach (array_unique($registered) as $selector) {
                            if (! is_string($selector) || $selector === '') {
                                continue;
                            }

                            $this->invalidate(
                                'index',
                                $framework,
                                $collectionEntityType,
                                $selector,
                                $locale,
                                $orgId,
                                $preserveLkg,
                            );
                        }
                    },
                );
            }
        } catch (Throwable $throwable) {
            $this->recordState('index', $framework, $entityType, $locale, 'bypass', $throwable);
        }
    }

    /** @throws JsonException */
    public function versionFor(PersonalityPublicContentAsset $asset): string
    {
        $attributes = $asset->getAttributes();
        ksort($attributes);

        return 'asset:'.(int) $asset->id.':'.hash(
            'sha256',
            json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @param  list<PersonalityPublicContentAsset>  $assets
     * @param  array<string,int>  $pagination
     *
     * @throws JsonException
     */
    public function collectionVersion(array $assets, array $pagination): string
    {
        $versions = array_map(fn (PersonalityPublicContentAsset $asset): string => $this->versionFor($asset), $assets);

        return 'collection:'.hash(
            'sha256',
            json_encode([$versions, $pagination], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    public function key(
        string $surface,
        string $framework,
        string $entityType,
        string $selector,
        string $locale,
        string $version,
    ): string {
        return $this->pointerPrefix($surface, $framework, $entityType, $selector, $locale)
            .':versions:'.hash('xxh3', $version);
    }

    public function activeKey(
        string $surface,
        string $framework,
        string $entityType,
        string $selector,
        string $locale,
    ): string {
        return $this->pointerPrefix($surface, $framework, $entityType, $selector, $locale).':active';
    }

    public function lkgKey(
        string $surface,
        string $framework,
        string $entityType,
        string $selector,
        string $locale,
    ): string {
        return $this->pointerPrefix($surface, $framework, $entityType, $selector, $locale).':lkg';
    }

    private function pointerPrefix(
        string $surface,
        string $framework,
        string $entityType,
        string $selector,
        string $locale,
    ): string {
        return implode(':', [
            self::CACHE_KEY_PREFIX,
            strtolower($surface),
            strtolower($locale),
            strtolower($framework),
            strtolower($entityType),
            hash('xxh3', strtolower($selector)),
        ]);
    }

    private function registerCollectionSelector(
        string $framework,
        string $entityType,
        string $selector,
        string $locale,
    ): void {
        $registryKey = $this->collectionRegistryKey($framework, $entityType, $locale);
        $registered = Cache::get($registryKey, []);
        $selectors = is_array($registered)
            ? array_values(array_filter($registered, static fn (mixed $value): bool => is_string($value) && $value !== ''))
            : [];
        $selectors = array_values(array_filter(
            array_unique($selectors),
            static fn (string $registeredSelector): bool => $registeredSelector !== $selector,
        ));
        $selectors[] = $selector;
        $evictedSelectors = array_slice($selectors, 0, max(0, count($selectors) - self::COLLECTION_REGISTRY_LIMIT));
        $selectors = array_slice($selectors, -self::COLLECTION_REGISTRY_LIMIT);

        foreach ($evictedSelectors as $evictedSelector) {
            Cache::forget($this->activeKey('index', $framework, $entityType, $evictedSelector, $locale));
            Cache::forget($this->lkgKey('index', $framework, $entityType, $evictedSelector, $locale));
        }

        Cache::put($registryKey, $selectors, self::LKG_TTL_SECONDS);
    }

    private function collectionRegistryKey(string $framework, string $entityType, string $locale): string
    {
        return implode(':', [
            self::CACHE_KEY_PREFIX,
            'index-registry',
            strtolower($locale),
            strtolower($framework),
            strtolower($entityType),
        ]);
    }

    private function withCollectionLock(
        string $framework,
        string $entityType,
        string $locale,
        callable $callback,
    ): void {
        Cache::lock(
            $this->collectionRegistryKey($framework, $entityType, $locale).':lock',
            10,
        )->block(10, $callback);
    }

    private function isCacheable(
        string $surface,
        string $framework,
        string $entityType,
        string $selector,
        string $locale,
        int $orgId,
    ): bool {
        $surfaceEntityTypeIsValid = match ($surface) {
            'detail-code' => in_array($entityType, PersonalityPublicContentAsset::ENTITY_TYPES, true),
            'detail-slug' => $entityType === 'slug',
            'index' => $entityType === 'all' || in_array($entityType, PersonalityPublicContentAsset::ENTITY_TYPES, true),
            default => false,
        };

        return $orgId === 0
            && in_array($framework, PersonalityPublicContentAsset::FRAMEWORKS, true)
            && in_array($locale, PersonalityPublicContentAsset::SUPPORTED_LOCALES, true)
            && $surfaceEntityTypeIsValid
            && $selector !== ''
            && strlen($selector) <= 512;
    }

    private function recordState(
        string $surface,
        string $framework,
        string $entityType,
        string $locale,
        string $state,
        ?Throwable $throwable = null,
    ): void {
        try {
            Log::info('personality_public_asset_read_model_cache', array_filter([
                'surface' => strtolower($surface),
                'framework' => strtolower($framework),
                'entity_type' => strtolower($entityType),
                'locale' => $locale,
                'cache_state' => $state,
                'error_class' => $throwable !== null ? $throwable::class : null,
            ]));
        } catch (Throwable) {
            // Cache observability must never take down a public read.
        }
    }
}
