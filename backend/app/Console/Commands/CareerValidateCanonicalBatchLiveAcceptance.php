<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Expansion\CanonicalPostPromotionReleaseGateService;
use App\Domain\Career\Expansion\CanonicalPromotionRollbackGate;
use App\Domain\Career\Publish\CareerCanonicalRuntimeTruthExporter;
use App\Domain\Career\Publish\CareerCanonicalRuntimeTruthValidator;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionExporter;
use Illuminate\Console\Command;

final class CareerValidateCanonicalBatchLiveAcceptance extends Command
{
    protected $signature = 'career:validate-canonical-batch-live-acceptance
        {--batch-id= : Canonical rollout batch identifier (required)}
        {--slugs= : Comma-separated list of promoted canonical slugs}
        {--locales= : Comma-separated list of target locales (default: en,zh)}
        {--json : Emit JSON output}';

    protected $description = 'Validate live acceptance of a promoted canonical rollout batch across projection, truth, release gate, and surface equality.';

    public function __construct(
        private readonly CareerRuntimePublishProjectionExporter $projectionExporter,
        private readonly CareerCanonicalRuntimeTruthExporter $truthExporter,
        private readonly CareerCanonicalRuntimeTruthValidator $truthValidator,
        private readonly CanonicalPostPromotionReleaseGateService $releaseGateService,
        private readonly CanonicalPromotionRollbackGate $rollbackGate,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $batchId = $this->requiredOption('batch-id');
            $slugsRaw = $this->option('slugs');
            $localesRaw = $this->option('locales') ?? 'en,zh';

            $slugs = array_values(array_filter(
                array_map('trim', $slugsRaw !== null ? explode(',', (string) $slugsRaw) : []),
                static fn (string $s): bool => $s !== '',
            ));
            $locales = array_values(array_filter(
                array_map('trim', explode(',', (string) $localesRaw)),
                static fn (string $s): bool => $s !== '',
            ));

            $projection = $this->projectionExporter->build();
            $truth = $this->truthExporter->buildFromProjectionArray($projection);

            $surfaceValidation = $this->truthValidator->validate($truth);
            $surfaceEqualityResult = ($surfaceValidation['status'] ?? null) === 'pass' ? 'pass' : 'blocked';

            $manifest = $this->publishedManifest($batchId, $slugs, $locales, $slugs);
            $releaseGateResult = $this->releaseGateService->evaluate($manifest, $truth, $projection);

            $releaseGatePassCount = (int) ($releaseGateResult['release_gate_pass_count'] ?? 0);
            $releaseGateBlockedCount = (int) ($releaseGateResult['release_gate_blocked_count'] ?? 0);
            $closeoutAllowed = (bool) ($releaseGateResult['closeout_allowed'] ?? false);

            $surfaceMismatches = $this->collectSurfaceMismatches(
                $surfaceValidation, $releaseGateResult, $truth,
            );

            $mismatchCount = count($surfaceMismatches);
            $unexpectedExposureCount = $this->candidateUnexpectedExposureCount($surfaceValidation);

            $truthCounts = is_array($truth['counts'] ?? null) ? $truth['counts'] : [];

            $accepted = $surfaceEqualityResult === 'pass'
                && $releaseGateBlockedCount === 0
                && $mismatchCount === 0
                && $unexpectedExposureCount === 0;

            $result = [
                'validator' => 'career.canonical_batch_live_acceptance.v1',
                'batch_id' => $batchId,
                'accepted' => $accepted,
                'read_only' => true,
                'writes_database' => false,
                'surface_equality' => $surfaceEqualityResult,
                'surface_validation' => [
                    'status' => $surfaceValidation['status'] ?? null,
                    'mismatch_count' => (int) ($surfaceValidation['counts']['failures'] ?? $surfaceValidation['counts']['mismatch_count'] ?? 0),
                ],
                'release_gate' => [
                    'pass' => $releaseGatePassCount,
                    'blocked' => $releaseGateBlockedCount,
                    'closeout_allowed' => $closeoutAllowed,
                ],
                'projection_counts' => [
                    'published' => (int) ($truthCounts['published'] ?? 0),
                    'published_candidate' => (int) ($truthCounts['published_candidate'] ?? 0),
                    'blocked' => (int) ($truthCounts['blocked'] ?? 0),
                    'quarantined' => (int) ($truthCounts['quarantined'] ?? 0),
                    'fully_live' => (int) ($truthCounts['fully_live'] ?? 0),
                ],
                'mismatches' => $surfaceMismatches,
                'mismatch_count' => $mismatchCount,
                'unexpected_exposure' => $unexpectedExposureCount,
                'rollback_required' => ! $accepted,
                'quarantine_required' => false,
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode(
                    $result,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
                ));
            } else {
                $this->line('accepted='.($result['accepted'] ? 'true' : 'false'));
                $this->line('surface_equality='.$result['surface_equality']);
                $this->line('release_gate.pass='.(string) $releaseGatePassCount);
                $this->line('release_gate.blocked='.(string) $releaseGateBlockedCount);
                $this->line('mismatch_count='.(string) $mismatchCount);
                $this->line('unexpected_exposure='.(string) $unexpectedExposureCount);
                $this->line('published='.(string) ($truthCounts['published'] ?? 0));
                $this->line('fully_live='.(string) ($truthCounts['fully_live'] ?? 0));
            }

            return $accepted ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            if ((bool) $this->option('json')) {
                $this->line((string) json_encode([
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }
    }

    private function requiredOption(string $name): string
    {
        $value = $this->option($name);
        if ($value === null || trim((string) $value) === '') {
            throw new \RuntimeException("--{$name} is required");
        }

        return trim((string) $value);
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @param  list<string>  $rollbackGroup
     */
    private function publishedManifest(string $batchId, array $slugs, array $locales, array $rollbackGroup): array
    {
        return [
            'batch_id' => $batchId,
            'slugs' => $slugs,
            'locales' => $locales,
            'rollback_group' => $rollbackGroup,
            'rollout_state' => 'published',
            'projection_state' => 'published',
        ];
    }

    /**
     * @param  array<string, mixed>  $surfaceValidation
     * @param  array<string, mixed>  $releaseGateResult
     * @param  array<string, mixed>  $truth
     * @return list<array<string, mixed>>
     */
    private function collectSurfaceMismatches(
        array $surfaceValidation,
        array $releaseGateResult,
        array $truth,
    ): array {
        $mismatches = [];

        foreach ((array) ($surfaceValidation['failures'] ?? []) as $failure) {
            if (! is_array($failure)) {
                continue;
            }

            $mismatches[] = [
                'source' => 'surface_validation',
                'slug' => $failure['slug'] ?? null,
                'locale' => $failure['locale'] ?? null,
                'reason' => $failure['reason'] ?? 'unknown',
            ];
        }

        $releaseGateFailedSlugs = $releaseGateResult['failed_slugs'] ?? [];
        $releaseGateFailures = $releaseGateResult['failure_reasons'] ?? [];

        foreach ($releaseGateFailedSlugs as $slug) {
            $mismatches[] = [
                'source' => 'release_gate',
                'slug' => $slug,
                'locale' => null,
                'reason' => 'release_gate_blocked',
                'failure_reasons' => $releaseGateFailures,
            ];
        }

        return $mismatches;
    }

    /**
     * @param  array<string, mixed>  $surfaceValidation
     */
    private function candidateUnexpectedExposureCount(array $surfaceValidation): int
    {
        $counts = $surfaceValidation['counts'] ?? [];

        return (int) ($counts['candidate_unexpected_route_exposure_count'] ?? 0)
            + (int) ($counts['candidate_unexpected_api_exposure_count'] ?? 0)
            + (int) ($counts['candidate_unexpected_dataset_exposure_count'] ?? 0)
            + (int) ($counts['candidate_unexpected_search_exposure_count'] ?? 0)
            + (int) ($counts['candidate_unexpected_sitemap_exposure_count'] ?? 0)
            + (int) ($counts['candidate_unexpected_llms_exposure_count'] ?? 0)
            + (int) ($counts['candidate_unexpected_llms_full_exposure_count'] ?? 0)
            + (int) ($counts['candidate_unexpected_indexable_exposure_count'] ?? 0);
    }
}
