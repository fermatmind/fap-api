<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use App\Services\Career\PublicCareerAuthorityResponseCache;

class CareerCurrentAuthorityCacheGateway
{
    public function __construct(
        private readonly PublicCareerAuthorityResponseCache $cache,
    ) {}

    /** @return array<string,mixed> */
    public function prepare(string $slug, string $locale): array
    {
        return $this->cache->preparePublishedJobDetailReplacement($slug, $locale);
    }

    /** @param array<string,mixed> $entry @return array<string,mixed>|null */
    public function preparedPayload(array $entry): ?array
    {
        return $this->cache->preparedJobDetailReplacementPayload($entry);
    }

    /** @param list<array<string,mixed>> $entries @return array<string,mixed> */
    public function activate(array $entries): array
    {
        return $this->cache->activatePreparedJobDetailPayloadsForExposure($entries, true);
    }

    /** @param list<array<string,mixed>> $entries @param array<string,mixed> $snapshots */
    public function restore(array $entries, array $snapshots): void
    {
        $this->cache->restorePreparedJobDetailExposurePointers($entries, $snapshots);
    }

    /** @param list<array<string,mixed>> $entries */
    public function forget(array $entries): void
    {
        $this->cache->forgetPreparedJobDetailCandidates($entries);
    }

    /** @param list<string> $slugs @param list<string> $locales @return array<string,mixed> */
    public function publicationSnapshot(array $slugs, array $locales): array
    {
        return $this->cache->jobDetailPublicationSnapshot($slugs, $locales);
    }

    /** @return array<string,mixed> */
    public function verifyOnlyRead(string $slug, string $locale): array
    {
        return $this->cache->jobDetailVerifyOnlyRead($slug, $locale);
    }
}
