<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\PersonalityPublicContentAsset;
use App\Services\Cms\PersonalityPublicAssetReadModelCache;
use Carbon\CarbonImmutable;
use Throwable;

final class PersonalityPublicContentAssetObserver
{
    public function __construct(
        private readonly PersonalityPublicAssetReadModelCache $cache,
    ) {}

    public function saved(PersonalityPublicContentAsset $asset): void
    {
        $current = $this->identity($asset->getAttributes());
        $original = $asset->wasRecentlyCreated ? null : $this->identity($asset->getRawOriginal());
        $currentPublic = $this->isPubliclyReadable($asset->getAttributes());
        $originalPublic = ! $asset->wasRecentlyCreated && $this->isPubliclyReadable($asset->getRawOriginal());
        $identityChanged = $original !== null && $original !== $current;

        if ($original !== null && $originalPublic && (! $currentPublic || $identityChanged)) {
            $this->invalidateIdentity($original, false);
        }

        if ($currentPublic) {
            $preserveLkg = ! $identityChanged;
            $this->invalidateIdentity($current, $preserveLkg);
        }
    }

    public function deleted(PersonalityPublicContentAsset $asset): void
    {
        $this->invalidateIdentity($this->identity($asset->getAttributes()), false);
    }

    /**
     * @param  array{framework:string,entity_type:string,entity_key:string,slug:string,locale:string,org_id:int}  $identity
     */
    private function invalidateIdentity(array $identity, bool $preserveLkg): void
    {
        $this->cache->invalidateAsset(
            $identity['framework'],
            $identity['entity_type'],
            $identity['entity_key'],
            $identity['slug'],
            $identity['locale'],
            $identity['org_id'],
            $preserveLkg,
        );
        $this->cache->invalidateCollections(
            $identity['framework'],
            $identity['entity_type'],
            $identity['locale'],
            $identity['org_id'],
            $preserveLkg,
        );
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array{framework:string,entity_type:string,entity_key:string,slug:string,locale:string,org_id:int}
     */
    private function identity(array $attributes): array
    {
        return [
            'framework' => PersonalityPublicContentAsset::normalizeToken((string) ($attributes['framework'] ?? '')),
            'entity_type' => PersonalityPublicContentAsset::normalizeToken((string) ($attributes['entity_type'] ?? '')),
            'entity_key' => PersonalityPublicContentAsset::normalizeEntityKey((string) ($attributes['entity_key'] ?? '')),
            'slug' => PersonalityPublicContentAsset::normalizeSlug((string) ($attributes['slug'] ?? '')),
            'locale' => PersonalityPublicContentAsset::normalizeLocale((string) ($attributes['locale'] ?? '')),
            'org_id' => max(0, (int) ($attributes['org_id'] ?? 0)),
        ];
    }

    /** @param array<string,mixed> $attributes */
    private function isPubliclyReadable(array $attributes): bool
    {
        if (! (bool) ($attributes['is_public'] ?? false)) {
            return false;
        }

        if (! in_array((string) ($attributes['launch_state'] ?? ''), [
            PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
            PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
        ], true)) {
            return false;
        }

        $publishedAt = $attributes['published_at'] ?? null;
        if ($publishedAt === null || trim((string) $publishedAt) === '') {
            return true;
        }

        try {
            return CarbonImmutable::parse((string) $publishedAt)->isPast();
        } catch (Throwable) {
            return false;
        }
    }
}
