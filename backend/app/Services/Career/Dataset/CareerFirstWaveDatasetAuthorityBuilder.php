<?php

declare(strict_types=1);

namespace App\Services\Career\Dataset;

use App\Domain\Career\Import\RunStatus;
use App\Domain\Career\Publish\CareerFirstWaveDiscoverabilityManifestService;
use App\Domain\Career\Publish\CareerFirstWaveLaunchTierSummaryService;
use App\Domain\Career\Publish\FirstWaveManifestReader;
use App\DTO\Career\CareerFirstWaveDatasetAuthority;
use App\DTO\Career\CareerFirstWaveDatasetDescriptor;
use App\DTO\Career\CareerFirstWaveDatasetMember;
use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\Occupation;
use App\Models\TrustManifest;

final class CareerFirstWaveDatasetAuthorityBuilder
{
    private const DATASET_KEY = 'career_first_wave_job_detail_dataset';

    private const MEMBER_KIND = 'career_job_detail';

    public function __construct(
        private readonly FirstWaveManifestReader $manifestReader,
        private readonly CareerFirstWaveLaunchTierSummaryService $launchTierSummaryService,
        private readonly CareerFirstWaveDiscoverabilityManifestService $discoverabilityManifestService,
        private readonly CareerFirstWaveDatasetMetadataBuilder $metadataBuilder,
    ) {}

    public function build(): CareerFirstWaveDatasetAuthority
    {
        $manifest = $this->manifestReader->read();
        $waveName = (string) ($manifest['wave_name'] ?? CareerFirstWaveLaunchTierSummaryService::SCOPE);
        $launchTierSummary = $this->launchTierSummaryService->build()->toArray();
        $discoverabilityManifest = $this->discoverabilityManifestService->build()->toArray();
        $importRun = $this->latestCompletedImportRun($waveName);
        $compileRun = $this->latestCompletedCompileRun($importRun?->id);

        $members = $this->buildMembers(
            manifestOccupations: (array) ($manifest['occupations'] ?? []),
            launchTierRows: (array) ($launchTierSummary['occupations'] ?? []),
            discoverabilityRoutes: (array) ($discoverabilityManifest['routes'] ?? []),
        );

        $descriptor = new CareerFirstWaveDatasetDescriptor(
            datasetKey: self::DATASET_KEY,
            datasetScope: $waveName,
            manifestVersion: (string) ($manifest['manifest_version'] ?? 'unknown'),
            selectionPolicyVersion: (string) ($manifest['selection_policy_version'] ?? 'unknown'),
            datasetName: $this->normalizeString($importRun?->dataset_name),
            datasetVersion: $this->normalizeString($importRun?->dataset_version),
            datasetChecksum: $this->normalizeString($importRun?->dataset_checksum),
            sourcePath: $this->normalizeString(data_get($importRun?->meta, 'source_path')),
        );
        $aggregate = $this->buildAggregate($members, $importRun, $compileRun);

        return new CareerFirstWaveDatasetAuthority(
            descriptor: $descriptor,
            metadata: $this->metadataBuilder->build($descriptor, $aggregate, $members, $importRun, $compileRun),
            aggregate: $aggregate,
            members: $members,
        );
    }

    /**
     * @param  list<mixed>  $manifestOccupations
     * @param  list<mixed>  $launchTierRows
     * @param  list<mixed>  $discoverabilityRoutes
     * @return list<CareerFirstWaveDatasetMember>
     */
    private function buildMembers(array $manifestOccupations, array $launchTierRows, array $discoverabilityRoutes): array
    {
        $manifestBySlug = [];
        foreach ($manifestOccupations as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = $this->normalizeString($row['canonical_slug'] ?? null);
            if ($slug !== null) {
                $manifestBySlug[$slug] = true;
            }
        }

        $launchTierBySlug = [];
        foreach ($launchTierRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = $this->normalizeString($row['canonical_slug'] ?? null);
            if ($slug !== null) {
                $launchTierBySlug[$slug] = $row;
            }
        }

        $discoverabilityBySlug = [];
        foreach ($discoverabilityRoutes as $row) {
            if (! is_array($row) || ($row['route_kind'] ?? null) !== self::MEMBER_KIND) {
                continue;
            }

            $slug = $this->normalizeString($row['canonical_slug'] ?? null);
            if ($slug !== null) {
                $discoverabilityBySlug[$slug] = $row;
            }
        }

        $members = [];
        foreach (array_keys($manifestBySlug) as $slug) {
            $launchTierRow = $launchTierBySlug[$slug] ?? null;
            $discoverabilityRow = $discoverabilityBySlug[$slug] ?? null;

            if (! is_array($launchTierRow) || ! is_array($discoverabilityRow)) {
                continue;
            }

            $occupationUuid = $this->normalizeString($launchTierRow['occupation_uuid'] ?? null);
            $canonicalTitleEn = $this->normalizeString($launchTierRow['canonical_title_en'] ?? null);
            $canonicalPath = $this->normalizeString($discoverabilityRow['canonical_path'] ?? null);
            $launchTier = $this->normalizeString($launchTierRow['launch_tier'] ?? null);
            $discoverabilityState = $this->normalizeString($discoverabilityRow['discoverability_state'] ?? null);

            if (
                $occupationUuid === null
                || $canonicalTitleEn === null
                || $canonicalPath === null
                || $launchTier === null
                || $discoverabilityState === null
            ) {
                continue;
            }

            $members[] = new CareerFirstWaveDatasetMember(
                occupationUuid: $occupationUuid,
                canonicalSlug: $slug,
                canonicalTitleEn: $canonicalTitleEn,
                canonicalPath: $canonicalPath,
                launchTier: $launchTier,
                discoverabilityState: $discoverabilityState,
                indexEligible: (bool) ($launchTierRow['index_eligible'] ?? false),
            );
        }

        usort($members, static fn (CareerFirstWaveDatasetMember $left, CareerFirstWaveDatasetMember $right): int => strcmp(
            $left->canonicalSlug,
            $right->canonicalSlug,
        ));

        return $members;
    }

    /**
     * @param  list<CareerFirstWaveDatasetMember>  $members
     * @return array<string, mixed>
     */
    private function buildAggregate(
        array $members,
        ?CareerImportRun $importRun,
        ?CareerCompileRun $compileRun,
    ): array {
        $slugs = array_map(
            static fn (CareerFirstWaveDatasetMember $member): string => $member->canonicalSlug,
            $members,
        );

        $occupationIds = Occupation::query()
            ->whereIn('canonical_slug', $slugs)
            ->pluck('id')
            ->all();

        $lastSubstantiveUpdateAt = null;
        if ($occupationIds !== []) {
            /** @var TrustManifest|null $latestTrustManifest */
            $latestTrustManifest = TrustManifest::query()
                ->whereIn('occupation_id', $occupationIds)
                ->whereNotNull('last_substantive_update_at')
                ->orderByDesc('last_substantive_update_at')
                ->first();

            $lastSubstantiveUpdateAt = optional($latestTrustManifest?->last_substantive_update_at)->toISOString();
        }

        $counts = [
            'stable' => 0,
            'candidate' => 0,
            'hold' => 0,
            'discoverable' => 0,
            'excluded' => 0,
        ];

        foreach ($members as $member) {
            if (array_key_exists($member->launchTier, $counts)) {
                $counts[$member->launchTier]++;
            }

            if (array_key_exists($member->discoverabilityState, $counts)) {
                $counts[$member->discoverabilityState]++;
            }
        }

        return [
            'member_kind' => self::MEMBER_KIND,
            'member_count' => count($members),
            'counts' => $counts,
            'import_run_id' => $importRun?->id,
            'compile_run_id' => $compileRun?->id,
            'compiled_at' => $this->normalizeString(data_get($compileRun?->meta, 'compiled_at'))
                ?? optional($compileRun?->finished_at)->toISOString()
                ?? optional($compileRun?->started_at)->toISOString(),
            'last_substantive_update_at' => $lastSubstantiveUpdateAt,
        ];
    }

    private function latestCompletedImportRun(string $waveName): ?CareerImportRun
    {
        /** @var CareerImportRun|null $importRun */
        $importRun = CareerImportRun::query()
            ->where('status', RunStatus::COMPLETED)
            ->where('meta->wave_name', $waveName)
            ->orderByDesc('finished_at')
            ->orderByDesc('started_at')
            ->first();

        return $importRun;
    }

    private function latestCompletedCompileRun(?string $importRunId): ?CareerCompileRun
    {
        $query = CareerCompileRun::query()
            ->where('status', RunStatus::COMPLETED);

        if ($importRunId !== null && $importRunId !== '') {
            $query->where('import_run_id', $importRunId);
        }

        /** @var CareerCompileRun|null $compileRun */
        $compileRun = $query
            ->orderByDesc('finished_at')
            ->orderByDesc('started_at')
            ->first();

        return $compileRun;
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
