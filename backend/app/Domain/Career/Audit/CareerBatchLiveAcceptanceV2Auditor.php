<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerBatchLiveAcceptanceV2Auditor
{
    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @param  array<string, mixed>  $projection
     * @param  array<string, mixed>  $truth
     * @param  array<string, mixed>  $surfaces
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    public function audit(
        string $batchId,
        array $slugs,
        array $locales,
        array $projection,
        array $truth,
        array $surfaces = [],
        bool $includeLiveHtml = false,
        array $sidecars = [],
    ): CareerBatchLiveAcceptanceV2Result {
        $slugs = $this->strings($slugs, 'slugs');
        $locales = $this->strings($locales, 'locales');
        if (trim($batchId) === '') {
            throw new InvalidArgumentException('Batch live acceptance v2 batch_id is required.');
        }

        $projectionByKey = $this->itemsByKey($projection);
        $truthByKey = $this->itemsByKey($truth);
        $surfaceByKey = $this->itemsByKey($surfaces);
        $rows = [];
        $issues = [];

        foreach ($slugs as $slug) {
            foreach ($locales as $locale) {
                $key = $slug.'|'.$locale;
                $rowIssues = [];
                $evidence = [['slug' => $slug, 'locale' => $locale]];
                $projectionRow = $projectionByKey[$key] ?? null;
                $truthRow = $truthByKey[$key] ?? null;
                $surfaceRow = $surfaceByKey[$key] ?? null;

                if ($projectionRow === null) {
                    $rowIssues[] = $this->issue(CareerBatchLiveAcceptanceV2Issue::PROJECTION_ROW_MISSING, $slug, $locale);
                }
                if ($truthRow === null) {
                    $rowIssues[] = $this->issue(CareerBatchLiveAcceptanceV2Issue::TRUTH_ROW_MISSING, $slug, $locale);
                }
                $releaseGatePass = (bool) (($truthRow['release_gate_pass'] ?? null) === true || ($projectionRow['release_gate_pass'] ?? null) === true);
                if (! $releaseGatePass) {
                    $rowIssues[] = $this->issue(CareerBatchLiveAcceptanceV2Issue::RELEASE_GATE_BLOCKED, $slug, $locale, [['release_gate_pass' => false]]);
                }

                $surfaceStatus = CareerCanonicalEligibilityStatus::PASS;
                if ($surfaceRow === null && $includeLiveHtml) {
                    $surfaceStatus = CareerCanonicalEligibilityStatus::UNVERIFIED;
                    $rowIssues[] = $this->issue(CareerBatchLiveAcceptanceV2Issue::SURFACE_UNVERIFIED, $slug, $locale, [['surface' => 'live_html']]);
                } elseif ($surfaceRow !== null && (
                    ($surfaceRow['surface_match'] ?? true) !== true
                    || ($surfaceRow['canonical_self'] ?? true) !== true
                    || ($surfaceRow['robots_indexable'] ?? true) !== true
                )) {
                    $surfaceStatus = CareerCanonicalEligibilityStatus::BLOCKED;
                    $rowIssues[] = $this->issue(CareerBatchLiveAcceptanceV2Issue::SURFACE_MISMATCH, $slug, $locale, [$surfaceRow]);
                }

                $issues = [...$issues, ...$rowIssues];
                $rows[] = new CareerBatchLiveAcceptanceV2Row(
                    canonicalSlug: $slug,
                    locale: $locale,
                    status: $rowIssues === [] ? CareerCanonicalEligibilityStatus::PASS : ($surfaceStatus === CareerCanonicalEligibilityStatus::UNVERIFIED ? CareerCanonicalEligibilityStatus::UNVERIFIED : CareerCanonicalEligibilityStatus::BLOCKED),
                    projectionFound: $projectionRow !== null,
                    truthFound: $truthRow !== null,
                    releaseGatePass: $releaseGatePass,
                    surfaceStatus: $surfaceStatus,
                    reasons: array_values(array_unique(array_map(static fn (CareerBatchLiveAcceptanceV2Issue $issue): string => $issue->reason, $rowIssues))),
                    evidence: $evidence,
                    issues: $rowIssues,
                );
            }
        }

        $unverified = count(array_filter($rows, static fn (CareerBatchLiveAcceptanceV2Row $row): bool => $row->status === CareerCanonicalEligibilityStatus::UNVERIFIED));
        $blocked = count(array_filter($rows, static fn (CareerBatchLiveAcceptanceV2Row $row): bool => $row->status === CareerCanonicalEligibilityStatus::BLOCKED));
        $accepted = $rows !== [] && $blocked === 0 && $unverified === 0;

        return new CareerBatchLiveAcceptanceV2Result(
            status: $accepted ? CareerCanonicalEligibilityStatus::PASS : ($unverified > 0 && $blocked === 0 ? CareerCanonicalEligibilityStatus::UNVERIFIED : CareerCanonicalEligibilityStatus::BLOCKED),
            accepted: $accepted,
            batchId: $batchId,
            expectedRows: count($slugs) * count($locales),
            foundProjectionRows: count(array_filter($rows, static fn (CareerBatchLiveAcceptanceV2Row $row): bool => $row->projectionFound)),
            foundTruthRows: count(array_filter($rows, static fn (CareerBatchLiveAcceptanceV2Row $row): bool => $row->truthFound)),
            releaseGatePassCount: count(array_filter($rows, static fn (CareerBatchLiveAcceptanceV2Row $row): bool => $row->releaseGatePass)),
            releaseGateBlockedCount: count(array_filter($rows, static fn (CareerBatchLiveAcceptanceV2Row $row): bool => ! $row->releaseGatePass)),
            surfaceEquality: $blocked === 0 && $unverified === 0 ? 'pass' : ($unverified > 0 && $blocked === 0 ? 'unverified' : 'fail'),
            mismatchCount: count(array_filter($issues, static fn (CareerBatchLiveAcceptanceV2Issue $issue): bool => $issue->reason === CareerBatchLiveAcceptanceV2Issue::SURFACE_MISMATCH)),
            unverifiedSurfaceCount: count(array_filter($issues, static fn (CareerBatchLiveAcceptanceV2Issue $issue): bool => $issue->reason === CareerBatchLiveAcceptanceV2Issue::SURFACE_UNVERIFIED)),
            readOnly: true,
            writesDatabase: false,
            rows: $rows,
            issues: $issues,
            sidecars: $sidecars,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array<string, mixed>>
     */
    private function itemsByKey(array $payload): array
    {
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $byKey = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $slug = strtolower(trim((string) ($item['slug'] ?? $item['canonical_slug'] ?? '')));
            $locale = strtolower(trim((string) ($item['locale'] ?? '')));
            if ($slug !== '' && $locale !== '') {
                $byKey[$slug.'|'.$locale] = $item;
            }
        }

        return $byKey;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function strings(array $values, string $key): array
    {
        if (! array_is_list($values) || $values === []) {
            throw new InvalidArgumentException('Batch live acceptance v2 '.$key.' must be a non-empty list.');
        }

        return array_values(array_map(static function (string $value) use ($key): string {
            $value = strtolower(trim($value));
            if ($value === '') {
                throw new InvalidArgumentException('Batch live acceptance v2 '.$key.' must contain non-empty strings.');
            }

            return $value;
        }, $values));
    }

    /**
     * @param  list<mixed>  $evidence
     */
    private function issue(string $reason, string $slug, string $locale, array $evidence = []): CareerBatchLiveAcceptanceV2Issue
    {
        return new CareerBatchLiveAcceptanceV2Issue(
            reason: $reason,
            canonicalSlug: $slug,
            locale: $locale,
            severity: CareerCanonicalEligibilitySeverity::HIGH,
            evidence: $evidence,
        );
    }
}
