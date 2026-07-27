<?php

declare(strict_types=1);

namespace App\Services\Career\Review;

use App\Services\Career\CareerDirectoryAuthorityService;

final class CareerSearchEntryQualityBatchManifestReader
{
    public const SCHEMA_VERSION = 'career.search_entry_quality_batch_manifest.v1';

    public const TASK_ID = 'CAREER-SEARCH-ENTRY-QUALITY-BATCH-01';

    public const MAX_CANDIDATES = 50;

    /** @var array<string,array{pool_rank:int,canonical_slug:string,expected_publish_track:string}>|null */
    private ?array $defaultBySlug = null;

    public function defaultPath(): string
    {
        return base_path('content_packs/career/'.self::TASK_ID.'/manifest.json');
    }

    /**
     * @return array{
     *   schema_version:string,
     *   task_id:string,
     *   selection_policy:string,
     *   expected_candidate_count:int,
     *   max_candidate_count:int,
     *   content_quality_tier:string,
     *   candidates:list<array{pool_rank:int,canonical_slug:string,expected_publish_track:string}>
     * }
     */
    public function read(?string $path = null): array
    {
        $resolved = $path === null || trim($path) === ''
            ? $this->defaultPath()
            : (str_starts_with($path, '/') ? $path : base_path($path));
        if (! is_file($resolved)) {
            throw new \RuntimeException('Career search-entry quality manifest is missing.');
        }

        $decoded = json_decode((string) file_get_contents($resolved), true);
        if (! is_array($decoded)
            || ($decoded['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ($decoded['task_id'] ?? null) !== self::TASK_ID
            || ($decoded['content_quality_tier'] ?? null)
                !== CareerSearchEntryTierResolver::CONTENT_QUALITY_TIER_CONTROLLED_CANDIDATE) {
            throw new \RuntimeException('Career search-entry quality manifest identity is invalid.');
        }

        $expected = (int) ($decoded['expected_candidate_count'] ?? 0);
        $maximum = (int) ($decoded['max_candidate_count'] ?? 0);
        $candidates = $decoded['candidates'] ?? null;
        if (! is_array($candidates)
            || $expected !== self::MAX_CANDIDATES
            || $maximum !== self::MAX_CANDIDATES
            || count($candidates) !== $expected) {
            throw new \RuntimeException('Career search-entry quality manifest count boundary is invalid.');
        }

        $seen = [];
        foreach (array_values($candidates) as $index => $candidate) {
            if (! is_array($candidate)) {
                throw new \RuntimeException('Career search-entry quality manifest candidate boundary is invalid.');
            }
            $rank = $index + 1;
            $slug = strtolower(trim((string) ($candidate['canonical_slug'] ?? '')));
            $track = strtolower(trim((string) ($candidate['expected_publish_track'] ?? '')));
            if ((int) ($candidate['pool_rank'] ?? 0) !== $rank
                || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1
                || ! in_array($track, ['stable', 'candidate'], true)
                || isset($seen[$slug])
                || in_array($slug, CareerDirectoryAuthorityService::excludedSlugs(), true)) {
                throw new \RuntimeException('Career search-entry quality manifest candidate boundary is invalid.');
            }
            $seen[$slug] = true;
            $candidates[$index] = [
                'pool_rank' => $rank,
                'canonical_slug' => $slug,
                'expected_publish_track' => $track,
            ];
        }

        $decoded['expected_candidate_count'] = $expected;
        $decoded['max_candidate_count'] = $maximum;
        $decoded['candidates'] = array_values($candidates);

        /** @var array{schema_version:string,task_id:string,selection_policy:string,expected_candidate_count:int,max_candidate_count:int,content_quality_tier:string,candidates:list<array{pool_rank:int,canonical_slug:string,expected_publish_track:string}>} $decoded */
        return $decoded;
    }

    /** @return array<string,array{pool_rank:int,canonical_slug:string,expected_publish_track:string}> */
    public function bySlug(?string $path = null): array
    {
        if ($path === null && $this->defaultBySlug !== null) {
            return $this->defaultBySlug;
        }

        $resolved = [];
        foreach ($this->read($path)['candidates'] as $candidate) {
            $resolved[$candidate['canonical_slug']] = $candidate;
        }

        if ($path === null) {
            $this->defaultBySlug = $resolved;
        }

        return $resolved;
    }
}
