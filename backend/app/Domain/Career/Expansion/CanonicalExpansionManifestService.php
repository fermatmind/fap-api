<?php

declare(strict_types=1);

namespace App\Domain\Career\Expansion;

use App\Domain\Career\Publish\CareerCanonicalRuntimeTruthExporter;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;

final class CanonicalExpansionManifestService
{
    public const MANIFEST_KIND = 'career_canonical_expansion_manifest';

    public const MANIFEST_VERSION = 'career.canonical_expansion_manifest.v1';

    public const DEFAULT_BATCH_ID = 'canonical-rollout-batch-001';

    public const DEFAULT_BATCH_SIZE = 50;

    public const ROLLOUT_STATE_BLOCKED = 'blocked';

    public const ROLLOUT_STATE_PUBLISHED_CANDIDATE = 'published_candidate';

    public const ROLLOUT_STATE_PUBLISHED = 'published';

    public const ROLLOUT_STATE_QUARANTINED = 'quarantined';

    /**
     * @var list<string>
     */
    public const ALLOWED_ROLLOUT_STATES = [
        self::ROLLOUT_STATE_BLOCKED,
        self::ROLLOUT_STATE_PUBLISHED_CANDIDATE,
        self::ROLLOUT_STATE_PUBLISHED,
        self::ROLLOUT_STATE_QUARANTINED,
    ];

    public function __construct(
        private readonly CareerCanonicalRuntimeTruthExporter $truthExporter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?string $truthPath = null, ?string $projectionPath = null, ?string $ledgerPath = null, ?int $batchSize = null, ?string $batchId = null): array
    {
        $truth = $truthPath !== null
            ? $this->readTruthPath($truthPath)
            : $this->truthExporter->build($ledgerPath, $projectionPath);

        return $this->buildFromTruthArray(
            truth: $truth,
            batchSize: $batchSize ?? self::DEFAULT_BATCH_SIZE,
            batchId: $batchId !== null && trim($batchId) !== '' ? trim($batchId) : self::DEFAULT_BATCH_ID,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildFromTruthArray(array $truth, int $batchSize = self::DEFAULT_BATCH_SIZE, string $batchId = self::DEFAULT_BATCH_ID): array
    {
        if ($batchSize < 1) {
            throw new \RuntimeException('canonical expansion batch size must be greater than zero');
        }

        $items = is_array($truth['items'] ?? null) ? $truth['items'] : [];
        $candidateSlugs = $this->candidateSlugs($items, $batchSize);
        $locales = $this->localesForSlugs($items, $candidateSlugs);
        $manifest = new CanonicalExpansionManifestDTO(
            batchId: $batchId,
            batchSize: $batchSize,
            slugs: $candidateSlugs,
            locales: $locales,
            projectionState: CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE,
            releaseGateRequired: true,
            surfaceEqualityRequired: true,
            rollbackGroup: $candidateSlugs,
            rolloutState: self::ROLLOUT_STATE_PUBLISHED_CANDIDATE,
        );

        return [
            'manifest_kind' => self::MANIFEST_KIND,
            'manifest_version' => self::MANIFEST_VERSION,
            'source_authority' => $truth['source_authority'] ?? 'CareerFullReleaseLedger',
            'source_truth_kind' => $truth['truth_kind'] ?? null,
            'source_truth_version' => $truth['truth_version'] ?? null,
            'ledger_kind' => $truth['ledger_kind'] ?? null,
            'ledger_version' => $truth['ledger_version'] ?? null,
            'scope' => $truth['scope'] ?? null,
            'counts' => [
                'candidate_slugs' => count($candidateSlugs),
                'candidate_locale_rows' => count($candidateSlugs) * count($locales),
                'batch_size' => $batchSize,
            ],
            'manifest' => $manifest->toArray(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function candidateSlugs(array $items, int $batchSize): array
    {
        $slugs = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $slug = strtolower(trim((string) ($item['slug'] ?? '')));
            if ($slug === '' || $slug === 'software-developers' || str_starts_with($slug, 'cn-')) {
                continue;
            }

            if (($item['projection_state'] ?? null) !== CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE) {
                continue;
            }

            if (($item['public_resolution_type'] ?? null) !== 'public_canonical_job') {
                continue;
            }

            $slugs[$slug] = $slug;
            if (count($slugs) >= $batchSize) {
                break;
            }
        }

        ksort($slugs);

        return array_values($slugs);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $slugs
     * @return list<string>
     */
    private function localesForSlugs(array $items, array $slugs): array
    {
        $slugSet = array_fill_keys($slugs, true);
        $locales = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $slug = strtolower(trim((string) ($item['slug'] ?? '')));
            if (! isset($slugSet[$slug])) {
                continue;
            }

            $locale = strtolower(trim((string) ($item['locale'] ?? '')));
            if ($locale !== '') {
                $locales[$locale] = $locale;
            }
        }

        ksort($locales);

        return array_values($locales);
    }

    /**
     * @return array<string, mixed>
     */
    private function readTruthPath(string $truthPath): array
    {
        if (! is_file($truthPath)) {
            throw new \RuntimeException('Career canonical runtime truth file not found: '.$truthPath);
        }

        $payload = json_decode((string) file_get_contents($truthPath), true);
        if (! is_array($payload)) {
            throw new \RuntimeException('Career canonical runtime truth file is not valid JSON: '.$truthPath);
        }

        return is_array($payload['truth'] ?? null) ? $payload['truth'] : $payload;
    }
}
