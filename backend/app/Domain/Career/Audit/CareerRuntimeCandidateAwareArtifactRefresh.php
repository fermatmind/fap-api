<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use RuntimeException;

final class CareerRuntimeCandidateAwareArtifactRefresh
{
    public const SCHEMA_VERSION = 'career_runtime_candidate_aware_artifact_refresh.v1';

    public const OVERLAY_SOURCE = 'candidate_prep_apply_overlay';

    private const DEFAULT_TARGET = 'career_80_delta';

    private const RUNTIME_STATE = 'published_candidate';

    private const DEFAULT_LOCALES = ['en', 'zh'];

    /**
     * @param  array<string, mixed>  $candidatePrepApply
     * @param  array<string, mixed>  $projection
     * @param  array<string, mixed>  $truth
     * @param  array<string, mixed>  $ledger
     * @return array{summary: array<string, mixed>, projection: array<string, mixed>, truth: array<string, mixed>, ledger: array<string, mixed>}
     */
    public function build(
        array $candidatePrepApply,
        string $candidatePrepApplyPath,
        ?string $candidatePrepApplyFileSha256,
        array $projection,
        string $projectionPath,
        array $truth,
        string $truthPath,
        array $ledger,
        string $ledgerPath,
        string $projectionOutputPath,
        string $truthOutputPath,
        string $ledgerOutputPath,
        int $expectedSlugCount = 51,
        string $target = self::DEFAULT_TARGET,
    ): array {
        $source = $this->verifiedSource($candidatePrepApply, $candidatePrepApplyPath, $candidatePrepApplyFileSha256, $expectedSlugCount);
        $slugs = $source['slugs'];
        $locales = $source['locales'];

        $projectionArtifact = $this->projectionArtifact($projection, $slugs, $locales, $source, $projectionPath);
        $truthArtifact = $this->truthArtifact($truth, $slugs, $locales, $source, $truthPath);
        $ledgerArtifact = $this->ledgerArtifact($ledger, $slugs, $source, $ledgerPath);

        return [
            'summary' => [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'pass',
                'source_apply_artifact' => [
                    'path' => $candidatePrepApplyPath,
                    'status' => $candidatePrepApply['status'] ?? null,
                    'write_verified' => true,
                    'slug_count' => count($slugs),
                    'verified_count' => $candidatePrepApply['verified_count'] ?? null,
                    'artifact_sha256' => $source['artifact_sha256'],
                    'file_sha256' => $candidatePrepApplyFileSha256,
                ],
                'target' => $this->target($target),
                'delta_slug_count' => count($slugs),
                'expected_delta_locale_rows' => count($slugs) * count($locales),
                'locales' => $locales,
                'projection' => [
                    'source_path' => $projectionPath,
                    'output_path' => $projectionOutputPath,
                    'overlay_rows' => count($slugs) * count($locales),
                    'source' => self::OVERLAY_SOURCE,
                ],
                'truth' => [
                    'source_path' => $truthPath,
                    'output_path' => $truthOutputPath,
                    'overlay_rows' => count($slugs) * count($locales),
                    'source' => self::OVERLAY_SOURCE,
                ],
                'ledger' => [
                    'source_path' => $ledgerPath,
                    'output_path' => $ledgerOutputPath,
                    'overlay_members' => count($slugs),
                    'source' => self::OVERLAY_SOURCE,
                ],
                'blockers' => [],
                'writes_database' => false,
                'read_only' => true,
                'apply_allowed' => false,
                'next_required_action' => $this->nextRequiredAction($target),
            ],
            'projection' => $projectionArtifact,
            'truth' => $truthArtifact,
            'ledger' => $ledgerArtifact,
        ];
    }

    private function target(string $target): string
    {
        $normalized = strtolower(trim($target));
        if ($normalized === '') {
            return self::DEFAULT_TARGET;
        }

        $key = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? $normalized;

        return trim($key, '_') ?: self::DEFAULT_TARGET;
    }

    private function nextRequiredAction(string $target): string
    {
        $target = $this->target($target);

        return $target === self::DEFAULT_TARGET
            ? '51_DELTA_ROLLOUT_DRY_RUN'
            : 'PROGRESSIVE_ROLLOUT_DRY_RUN';
    }

    /**
     * @param  array<string, mixed>  $candidatePrepApply
     * @return array{slugs: list<string>, locales: list<string>, artifact_sha256: string|null}
     */
    private function verifiedSource(array $candidatePrepApply, string $path, ?string $fileSha256, int $expectedSlugCount): array
    {
        if (($candidatePrepApply['status'] ?? null) !== 'applied') {
            throw new RuntimeException('candidate_prep_apply_not_applied');
        }

        if (($candidatePrepApply['write_verified'] ?? null) !== true) {
            throw new RuntimeException('candidate_prep_apply_not_verified');
        }

        $failures = $candidatePrepApply['failures'] ?? [];
        if ($failures !== null && (! is_array($failures) || count($failures) > 0)) {
            throw new RuntimeException('candidate_prep_apply_failures_present');
        }

        $slugs = $this->applySlugs($candidatePrepApply);
        if (count($slugs) !== $expectedSlugCount) {
            throw new RuntimeException('candidate_prep_apply_slug_count_mismatch');
        }

        foreach (['slug_count', 'created_count', 'verified_count'] as $key) {
            $value = $candidatePrepApply[$key] ?? null;
            if (! is_numeric($value) || (int) $value !== $expectedSlugCount) {
                throw new RuntimeException('candidate_prep_apply_'.$key.'_mismatch');
            }
        }

        return [
            'slugs' => $slugs,
            'locales' => $this->locales($candidatePrepApply),
            'artifact_sha256' => is_string($candidatePrepApply['artifact_sha256'] ?? null)
                ? trim((string) $candidatePrepApply['artifact_sha256'])
                : $fileSha256,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidatePrepApply
     * @return list<string>
     */
    private function applySlugs(array $candidatePrepApply): array
    {
        $rawSlugs = [];

        $created = $candidatePrepApply['created'] ?? null;
        if (is_array($created) && array_is_list($created)) {
            foreach ($created as $row) {
                if (is_array($row) && is_string($row['canonical_slug'] ?? null)) {
                    $rawSlugs[] = trim((string) $row['canonical_slug']);
                }
            }
        }

        if ($rawSlugs === [] && isset($candidatePrepApply['slugs']) && is_array($candidatePrepApply['slugs']) && array_is_list($candidatePrepApply['slugs'])) {
            foreach ($candidatePrepApply['slugs'] as $slug) {
                if (is_string($slug)) {
                    $rawSlugs[] = trim($slug);
                }
            }
        }

        $slugs = array_values(array_filter($rawSlugs, static fn (string $slug): bool => $slug !== ''));
        $unique = array_values(array_unique($slugs));
        sort($unique);

        if ($slugs === []) {
            throw new RuntimeException('candidate_prep_apply_slugs_missing');
        }
        if (count($slugs) !== count($unique)) {
            throw new RuntimeException('candidate_prep_apply_duplicate_slugs');
        }

        return $unique;
    }

    /**
     * @param  array<string, mixed>  $candidatePrepApply
     * @return list<string>
     */
    private function locales(array $candidatePrepApply): array
    {
        if (! array_key_exists('locales', $candidatePrepApply)) {
            return self::DEFAULT_LOCALES;
        }

        $rawLocales = $candidatePrepApply['locales'];
        if (! is_array($rawLocales) || ! array_is_list($rawLocales)) {
            throw new RuntimeException('candidate_prep_apply_locales_invalid');
        }

        return CareerRuntimeArtifactRefreshPlanner::normalizeLocaleList($rawLocales, 'candidate_prep_apply_locale');
    }

    /**
     * @param  array<string, mixed>  $projection
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @param  array{artifact_sha256: string|null, slugs: list<string>, locales: list<string>}  $source
     * @return array<string, mixed>
     */
    private function projectionArtifact(array $projection, array $slugs, array $locales, array $source, string $sourcePath): array
    {
        $rows = [
            ...$this->nonOverlayRows($this->artifactRows($projection, ['items', 'rows']), $slugs),
            ...$this->projectionOverlayRows($slugs, $locales, $source),
        ];

        $projection['projection_kind'] = 'career_runtime_publish_projection_candidate_aware';
        $projection['projection_version'] = 'v1';
        $projection['source_authority'] = [
            'base' => $projection['source_authority'] ?? null,
            'candidate_overlay' => self::OVERLAY_SOURCE,
            'source_path' => $sourcePath,
            'canonical_ledger_authority_claimed' => false,
        ];
        $projection['candidate_aware_overlay'] = $this->overlaySummary($slugs, $locales, $source);
        $projection['items'] = $rows;
        unset($projection['rows']);
        $projection['counts'] = $this->projectionCounts($rows);

        return $projection;
    }

    /**
     * @param  array<string, mixed>  $truth
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @param  array{artifact_sha256: string|null, slugs: list<string>, locales: list<string>}  $source
     * @return array<string, mixed>
     */
    private function truthArtifact(array $truth, array $slugs, array $locales, array $source, string $sourcePath): array
    {
        $rows = [
            ...$this->nonOverlayRows($this->artifactRows($truth, ['items', 'rows']), $slugs),
            ...$this->truthOverlayRows($slugs, $locales, $source),
        ];

        $truth['truth_kind'] = 'career_canonical_runtime_truth_candidate_aware';
        $truth['truth_version'] = 'v1';
        $truth['source_authority'] = [
            'base' => $truth['source_authority'] ?? null,
            'candidate_overlay' => self::OVERLAY_SOURCE,
            'source_path' => $sourcePath,
            'canonical_ledger_authority_claimed' => false,
        ];
        $truth['candidate_aware_overlay'] = $this->overlaySummary($slugs, $locales, $source);
        $truth['items'] = $rows;
        unset($truth['rows']);
        $truth['counts'] = $this->truthCounts($rows);

        return $truth;
    }

    /**
     * @param  array<string, mixed>  $ledger
     * @param  list<string>  $slugs
     * @param  array{artifact_sha256: string|null, slugs: list<string>, locales: list<string>}  $source
     * @return array<string, mixed>
     */
    private function ledgerArtifact(array $ledger, array $slugs, array $source, string $sourcePath): array
    {
        $members = [
            ...$this->nonOverlayMembers($this->artifactRows($ledger, ['members', 'items', 'rows']), $slugs),
            ...$this->ledgerOverlayMembers($slugs, $source),
        ];

        $ledger['ledger_kind'] = 'career_candidate_aware_full_release_ledger';
        $ledger['ledger_version'] = 'v1';
        $ledger['source_authority'] = [
            'base' => $ledger['source_authority'] ?? 'CareerFullReleaseLedger',
            'candidate_overlay' => self::OVERLAY_SOURCE,
            'source_path' => $sourcePath,
            'canonical_ledger_authority_claimed' => false,
        ];
        $ledger['candidate_aware_overlay'] = $this->overlaySummary($slugs, [], $source);
        $ledger['members'] = $members;
        unset($ledger['items'], $ledger['rows']);
        $ledger['counts'] = $this->ledgerCounts($ledger['counts'] ?? [], $members, count($slugs));

        return $ledger;
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @param  array{artifact_sha256: string|null}  $source
     * @return array<string, mixed>
     */
    private function overlaySummary(array $slugs, array $locales, array $source): array
    {
        return [
            'source' => self::OVERLAY_SOURCE,
            'runtime_publish_state' => self::RUNTIME_STATE,
            'slug_count' => count($slugs),
            'locale_count' => count($locales),
            'expected_locale_rows' => $locales === [] ? null : count($slugs) * count($locales),
            'artifact_sha256' => $source['artifact_sha256'],
            'canonical_ledger_authority_claimed' => false,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $slugs
     * @return list<array<string, mixed>>
     */
    private function nonOverlayRows(array $rows, array $slugs): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($slugs): bool {
            $slug = $row['slug'] ?? null;

            return ! is_string($slug) || ! in_array($slug, $slugs, true);
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @param  list<string>  $slugs
     * @return list<array<string, mixed>>
     */
    private function nonOverlayMembers(array $members, array $slugs): array
    {
        return array_values(array_filter($members, static function (array $member) use ($slugs): bool {
            $slug = $member['canonical_slug'] ?? $member['slug'] ?? null;

            return ! is_string($slug) || ! in_array($slug, $slugs, true);
        }));
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @param  array{artifact_sha256: string|null}  $source
     * @return list<array<string, mixed>>
     */
    private function projectionOverlayRows(array $slugs, array $locales, array $source): array
    {
        $rows = [];
        foreach ($slugs as $slug) {
            foreach ($locales as $locale) {
                $rows[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'public_resolution_type' => 'public_canonical_job',
                    'runtime_publish_state' => self::RUNTIME_STATE,
                    'detail_route_enabled' => false,
                    'dataset_visible' => false,
                    'search_visible' => false,
                    'sitemap_live' => false,
                    'llms_live' => false,
                    'llms_full_live' => false,
                    'canonical_url' => null,
                    'canonical_self' => false,
                    'robots_indexable' => false,
                    'release_gate_pass' => false,
                    'blockers' => [],
                    'overlay_source' => self::OVERLAY_SOURCE,
                    'source_artifact_sha256' => $source['artifact_sha256'],
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @param  array{artifact_sha256: string|null}  $source
     * @return list<array<string, mixed>>
     */
    private function truthOverlayRows(array $slugs, array $locales, array $source): array
    {
        $rows = [];
        foreach ($slugs as $slug) {
            foreach ($locales as $locale) {
                $rows[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'public_resolution_type' => 'public_canonical_job',
                    'projection_state' => self::RUNTIME_STATE,
                    'route_exists' => false,
                    'final_200' => false,
                    'robots_indexable' => false,
                    'canonical_self' => false,
                    'dataset_visible' => false,
                    'search_visible' => false,
                    'sitemap_live' => false,
                    'llms_live' => false,
                    'llms_full_live' => false,
                    'release_gate_pass' => false,
                    'canonical_url' => null,
                    'fully_live' => false,
                    'candidate_pre_route_expected' => true,
                    'candidate_route_expectation' => 'expected_pre_route',
                    'candidate_release_gate_applicability' => 'not_applicable_before_promotion',
                    'candidate_unexpected_exposures' => [],
                    'overlay_source' => self::OVERLAY_SOURCE,
                    'source_artifact_sha256' => $source['artifact_sha256'],
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  list<string>  $slugs
     * @param  array{artifact_sha256: string|null}  $source
     * @return list<array<string, mixed>>
     */
    private function ledgerOverlayMembers(array $slugs, array $source): array
    {
        return array_map(static fn (string $slug): array => [
            'member_kind' => 'career_runtime_candidate_prep_overlay',
            'canonical_slug' => $slug,
            'current_index_state' => 'promotion_candidate',
            'public_index_state' => 'trust_limited',
            'index_eligible' => true,
            'release_cohort' => 'public_detail_conservative',
            'blocker_reasons' => [],
            'runtime_publish_state' => self::RUNTIME_STATE,
            'overlay_source' => self::OVERLAY_SOURCE,
            'source_artifact_sha256' => $source['artifact_sha256'],
            'canonical_ledger_authority_claimed' => false,
            'evidence_refs' => [
                'candidate_prep_apply' => [
                    'kind' => self::OVERLAY_SOURCE,
                    'write_verified' => true,
                    'artifact_sha256' => $source['artifact_sha256'],
                ],
            ],
        ], $slugs);
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function artifactRows(array $artifact, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($artifact[$key]) && is_array($artifact[$key]) && array_is_list($artifact[$key])) {
                return array_values(array_filter(
                    $artifact[$key],
                    static fn (mixed $row): bool => is_array($row) && ! array_is_list($row)
                ));
            }
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function projectionCounts(array $rows): array
    {
        return [
            'projection_rows' => count($rows),
            'canonical_published' => $this->countRows($rows, 'public_resolution_type', 'public_canonical_job'),
            'dataset_visible' => $this->countTrue($rows, 'dataset_visible'),
            'search_visible' => $this->countTrue($rows, 'search_visible'),
            'detail_route_enabled' => $this->countTrue($rows, 'detail_route_enabled'),
            'sitemap_live' => $this->countTrue($rows, 'sitemap_live'),
            'llms_live' => $this->countTrue($rows, 'llms_live'),
            'llms_full_live' => $this->countTrue($rows, 'llms_full_live'),
            'blocked' => $this->countRows($rows, 'runtime_publish_state', 'blocked'),
            'published_candidate' => $this->countRows($rows, 'runtime_publish_state', self::RUNTIME_STATE),
            'published' => $this->countRows($rows, 'runtime_publish_state', 'published'),
            'quarantined' => $this->countRows($rows, 'runtime_publish_state', 'quarantined'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function truthCounts(array $rows): array
    {
        return [
            'canonical_projection_rows' => $this->countRows($rows, 'public_resolution_type', 'public_canonical_job'),
            'excluded_non_canonical_rows' => count($rows) - $this->countRows($rows, 'public_resolution_type', 'public_canonical_job'),
            'published' => $this->countRows($rows, 'projection_state', 'published'),
            'published_candidate' => $this->countRows($rows, 'projection_state', self::RUNTIME_STATE),
            'blocked' => $this->countRows($rows, 'projection_state', 'blocked'),
            'quarantined' => $this->countRows($rows, 'projection_state', 'quarantined'),
            'route_exists' => $this->countTrue($rows, 'route_exists'),
            'final_200' => $this->countTrue($rows, 'final_200'),
            'robots_indexable' => $this->countTrue($rows, 'robots_indexable'),
            'canonical_self' => $this->countTrue($rows, 'canonical_self'),
            'dataset_visible' => $this->countTrue($rows, 'dataset_visible'),
            'search_visible' => $this->countTrue($rows, 'search_visible'),
            'sitemap_live' => $this->countTrue($rows, 'sitemap_live'),
            'llms_live' => $this->countTrue($rows, 'llms_live'),
            'llms_full_live' => $this->countTrue($rows, 'llms_full_live'),
            'release_gate_pass' => $this->countTrue($rows, 'release_gate_pass'),
            'fully_live' => $this->countTrue($rows, 'fully_live'),
            'candidate_pre_route_expected_count' => $this->countTrue($rows, 'candidate_pre_route_expected'),
            'candidate_release_gate_not_applicable_count' => $this->countRows($rows, 'candidate_release_gate_applicability', 'not_applicable_before_promotion'),
            'candidate_unexpected_route_exposure_count' => 0,
            'candidate_unexpected_api_exposure_count' => 0,
            'candidate_unexpected_dataset_exposure_count' => 0,
            'candidate_unexpected_search_exposure_count' => 0,
            'candidate_unexpected_sitemap_exposure_count' => 0,
            'candidate_unexpected_llms_exposure_count' => 0,
            'candidate_unexpected_llms_full_exposure_count' => 0,
            'candidate_unexpected_indexable_exposure_count' => 0,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @return array<string, mixed>
     */
    private function ledgerCounts(mixed $existingCounts, array $members, int $overlayCount): array
    {
        $releaseCounts = [];
        foreach ($members as $member) {
            $cohort = is_string($member['release_cohort'] ?? null) ? $member['release_cohort'] : 'unknown';
            $releaseCounts[$cohort] = ($releaseCounts[$cohort] ?? 0) + 1;
        }
        ksort($releaseCounts);

        $counts = is_array($existingCounts) && ! array_is_list($existingCounts) ? $existingCounts : [];
        $counts['member_count'] = count($members);
        $counts['candidate_prep_overlay_members'] = $overlayCount;
        $counts['release_counts'] = $releaseCounts;

        return $counts;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function countTrue(array $rows, string $key): int
    {
        return count(array_filter($rows, static fn (array $row): bool => ($row[$key] ?? null) === true));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function countRows(array $rows, string $key, string $value): int
    {
        return count(array_filter($rows, static fn (array $row): bool => ($row[$key] ?? null) === $value));
    }
}
