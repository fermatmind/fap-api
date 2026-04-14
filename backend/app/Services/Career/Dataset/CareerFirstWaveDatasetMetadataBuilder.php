<?php

declare(strict_types=1);

namespace App\Services\Career\Dataset;

use App\Domain\Career\Publish\FirstWaveManifestReader;
use App\DTO\Career\CareerFirstWaveDatasetCoverage;
use App\DTO\Career\CareerFirstWaveDatasetDescriptor;
use App\DTO\Career\CareerFirstWaveDatasetFreshness;
use App\DTO\Career\CareerFirstWaveDatasetMember;
use App\DTO\Career\CareerFirstWaveDatasetMetadata;
use App\DTO\Career\CareerFirstWaveDatasetProvenance;
use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\Occupation;
use App\Models\TrustManifest;

final class CareerFirstWaveDatasetMetadataBuilder
{
    private const INCLUDED_ROUTE_KINDS = ['career_job_detail'];

    public function __construct(
        private readonly FirstWaveManifestReader $manifestReader,
    ) {}

    /**
     * @param  array<string, mixed>  $aggregate
     * @param  list<CareerFirstWaveDatasetMember>  $members
     */
    public function build(
        CareerFirstWaveDatasetDescriptor $descriptor,
        array $aggregate,
        array $members,
        ?CareerImportRun $importRun,
        ?CareerCompileRun $compileRun,
    ): CareerFirstWaveDatasetMetadata {
        $manifest = $this->manifestReader->read();
        [$contentVersion, $dataVersion, $logicVersion] = $this->resolveConsensusVersions(
            $members,
            $importRun?->id,
        );

        return new CareerFirstWaveDatasetMetadata(
            coverage: new CareerFirstWaveDatasetCoverage(
                datasetScope: $descriptor->datasetScope,
                waveName: (string) ($manifest['wave_name'] ?? $descriptor->datasetScope),
                memberKind: (string) ($aggregate['member_kind'] ?? ''),
                memberCount: (int) ($aggregate['member_count'] ?? 0),
                countExpected: (int) ($manifest['count_expected'] ?? 0),
                countActual: (int) ($manifest['count_actual'] ?? 0),
                includedRouteKinds: self::INCLUDED_ROUTE_KINDS,
                familyHubsIncluded: false,
                selectionPolicyVersion: $descriptor->selectionPolicyVersion,
                manifestVersion: $descriptor->manifestVersion,
            ),
            provenance: new CareerFirstWaveDatasetProvenance(
                datasetName: $descriptor->datasetName,
                datasetVersion: $descriptor->datasetVersion,
                datasetChecksum: $descriptor->datasetChecksum,
                sourcePath: $descriptor->sourcePath,
                importRunId: $importRun?->id,
                compileRunId: $compileRun?->id,
                contentVersion: $contentVersion,
                dataVersion: $dataVersion,
                logicVersion: $logicVersion,
            ),
            freshness: new CareerFirstWaveDatasetFreshness(
                compiledAt: $this->normalizeString($aggregate['compiled_at'] ?? null),
                lastSubstantiveUpdateAt: $this->normalizeString($aggregate['last_substantive_update_at'] ?? null),
                manifestGeneratedAt: $this->normalizeString($manifest['generated_at'] ?? null),
            ),
        );
    }

    /**
     * @param  list<CareerFirstWaveDatasetMember>  $members
     * @return array{0:?string,1:?string,2:?string}
     */
    private function resolveConsensusVersions(array $members, ?string $importRunId): array
    {
        $slugs = array_map(
            static fn (CareerFirstWaveDatasetMember $member): string => $member->canonicalSlug,
            $members,
        );

        if ($slugs === []) {
            return [null, null, null];
        }

        $occupationIds = Occupation::query()
            ->whereIn('canonical_slug', $slugs)
            ->pluck('id')
            ->all();

        if ($occupationIds === []) {
            return [null, null, null];
        }

        $query = TrustManifest::query()
            ->whereIn('occupation_id', $occupationIds);

        if ($importRunId !== null && $importRunId !== '') {
            $query->where('import_run_id', $importRunId);
        }

        $trustManifests = $query->get(['content_version', 'data_version', 'logic_version']);

        if ($trustManifests->isEmpty()) {
            return [null, null, null];
        }

        return [
            $this->resolveConsensusValue($trustManifests->pluck('content_version')->all()),
            $this->resolveConsensusValue($trustManifests->pluck('data_version')->all()),
            $this->resolveConsensusValue($trustManifests->pluck('logic_version')->all()),
        ];
    }

    /**
     * @param  list<mixed>  $values
     */
    private function resolveConsensusValue(array $values): ?string
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => $this->normalizeString($value),
            $values,
        ))));

        return count($normalized) === 1 ? $normalized[0] : null;
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
