<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Production\CareerAssetBatchPipeline;
use Illuminate\Console\Command;
use RuntimeException;

final class CareerRunAssetBatch extends Command
{
    protected $signature = 'career:run-asset-batch
        {--manifest=* : Path(s) to batch manifest JSON}
        {--set= : Optional manifest-set JSON with manifests[] paths}
        {--mode=full : validate|compile-trust|publish-candidate|regression|full}
        {--json : Emit JSON output}';

    protected $description = 'Run Career asset batch pipeline with staged validate/compile-trust/publish-candidate/regression modes.';

    public function handle(CareerAssetBatchPipeline $pipeline): int
    {
        $manifestOptions = (array) $this->option('manifest');
        $manifestPaths = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $manifestOptions,
        ), static fn (string $path): bool => $path !== ''));
        $setCoverageBaseline = [];

        try {
            $setConfig = $this->loadSetConfig(trim((string) $this->option('set')));
            $setCoverageBaseline = (array) ($setConfig['coverage_baseline'] ?? []);
            $manifestPaths = array_values(array_unique(array_merge(
                $manifestPaths,
                (array) ($setConfig['manifests'] ?? []),
            )));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($manifestPaths === []) {
            $this->error('--manifest or --set is required.');

            return self::FAILURE;
        }

        try {
            $runs = array_map(
                fn (string $path): array => $pipeline->run($path, (string) $this->option('mode')),
                $manifestPaths,
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (count($runs) === 1) {
            $result = $runs[0];
        } else {
            $result = [
                'batch_execution_kind' => 'career_asset_batch_execution_b2b3b4',
                'batch_execution_version' => 'career.asset_batch_execution.v1',
                'status' => collect($runs)->every(
                    static fn (array $run): bool => (string) ($run['status'] ?? 'aborted') === 'completed'
                ) ? 'completed' : 'aborted',
                'mode' => (string) $this->option('mode'),
                'runs' => $runs,
                'combined_summary' => $this->combinedSummary($runs, $setCoverageBaseline),
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return ($result['status'] ?? 'aborted') === 'completed' ? self::SUCCESS : self::FAILURE;
        }

        $this->line('status='.(string) ($result['status'] ?? 'aborted'));
        $this->line('mode='.(string) ($result['mode'] ?? ''));

        if (isset($result['runs'])) {
            foreach ((array) ($result['runs'] ?? []) as $run) {
                $this->line('batch_key='.(string) data_get($run, 'manifest.batch_key', ''));
                $this->line('member_count='.(string) data_get($run, 'manifest.member_count', 0));
                foreach ((array) ($run['stages'] ?? []) as $name => $stage) {
                    $this->line(sprintf(
                        'stage[%s]=%s%s',
                        (string) $name,
                        ((bool) data_get($stage, 'passed', false)) ? 'passed' : 'failed',
                        ((bool) data_get($stage, 'skipped', false)) ? ' (skipped)' : '',
                    ));
                }
            }

            $this->line('combined_total_members='.(string) data_get($result, 'combined_summary.total_members', 0));
            $this->line('combined_review_queue='.(string) data_get($result, 'combined_summary.review_queue_handoff', 0));
        } else {
            $this->line('batch_key='.(string) data_get($result, 'manifest.batch_key', ''));
            $this->line('member_count='.(string) data_get($result, 'manifest.member_count', 0));

            foreach ((array) ($result['stages'] ?? []) as $name => $stage) {
                $this->line(sprintf(
                    'stage[%s]=%s%s',
                    (string) $name,
                    ((bool) data_get($stage, 'passed', false)) ? 'passed' : 'failed',
                    ((bool) data_get($stage, 'skipped', false)) ? ' (skipped)' : '',
                ));
            }
        }

        return ($result['status'] ?? 'aborted') === 'completed' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     * @return array<string, mixed>
     */
    private function combinedSummary(array $runs, array $setCoverageBaseline = []): array
    {
        $summary = [
            'total_members' => 0,
            'validate_passed' => 0,
            'trust_ready' => 0,
            'trust_missing' => 0,
            'stable' => 0,
            'candidate' => 0,
            'hold' => 0,
            'explorer_only' => 0,
            'review_needed' => 0,
            'review_queue_handoff' => 0,
            'family_handoff' => 0,
            'unmapped' => 0,
            'by_batch' => [],
        ];
        $manifestSlugs = [];

        foreach ($runs as $run) {
            $batchKey = (string) data_get($run, 'manifest.batch_key', '');
            $memberCount = (int) data_get($run, 'manifest.member_count', 0);
            $summary['total_members'] += $memberCount;
            $summary['validate_passed'] += (int) data_get($run, 'stages.validate.counts.valid', 0);
            $summary['trust_ready'] += (int) data_get($run, 'stages.compile_trust.counts.trust_ready', 0);
            $summary['trust_missing'] += (int) data_get($run, 'stages.compile_trust.counts.trust_missing', 0);
            $summary['stable'] += (int) data_get($run, 'stages.publish_candidate.production_state_summary.stable', 0);
            $summary['candidate'] += (int) data_get($run, 'stages.publish_candidate.production_state_summary.candidate', 0);
            $summary['hold'] += (int) data_get($run, 'stages.publish_candidate.production_state_summary.hold', 0);
            $summary['explorer_only'] += (int) data_get($run, 'stages.publish_candidate.production_state_summary.explorer_only', 0);
            $summary['review_needed'] += (int) data_get($run, 'stages.publish_candidate.production_state_summary.review_needed', 0);
            $summary['review_queue_handoff'] += (int) data_get($run, 'stages.review_queue_handoff.counts.queue_total', 0);
            $summary['family_handoff'] += (int) data_get($run, 'stages.review_queue_handoff.counts.family_handoff', 0);
            $summary['unmapped'] += (int) data_get($run, 'stages.review_queue_handoff.counts.unmapped', 0);
            foreach ((array) data_get($run, 'manifest.members', []) as $member) {
                $slug = trim((string) data_get($member, 'canonical_slug', ''));
                if ($slug !== '') {
                    $manifestSlugs[] = $slug;
                }
            }
            $summary['by_batch'][] = [
                'batch_key' => $batchKey,
                'batch_kind' => (string) data_get($run, 'manifest.batch_kind', ''),
                'member_count' => $memberCount,
                'status' => (string) ($run['status'] ?? 'aborted'),
                'trust_ready' => (int) data_get($run, 'stages.compile_trust.counts.trust_ready', 0),
                'trust_missing' => (int) data_get($run, 'stages.compile_trust.counts.trust_missing', 0),
                'stable' => (int) data_get($run, 'stages.publish_candidate.production_state_summary.stable', 0),
                'candidate' => (int) data_get($run, 'stages.publish_candidate.production_state_summary.candidate', 0),
                'hold' => (int) data_get($run, 'stages.publish_candidate.production_state_summary.hold', 0),
                'explorer_only' => (int) data_get($run, 'stages.publish_candidate.production_state_summary.explorer_only', 0),
                'review_needed' => (int) data_get($run, 'stages.publish_candidate.production_state_summary.review_needed', 0),
            ];
        }

        $excludedFirstWaveSlugs = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) ($setCoverageBaseline['excluded_first_wave_slugs'] ?? []),
        ), static fn (string $slug): bool => $slug !== ''));
        $trackedSlugs = array_values(array_unique(array_merge($manifestSlugs, $excludedFirstWaveSlugs)));
        $expectedTotal = (int) ($setCoverageBaseline['expected_total_occupations'] ?? 0);
        $trackedTotal = count($trackedSlugs);
        $missing = $expectedTotal > 0 ? max(0, $expectedTotal - $trackedTotal) : 0;

        $summary['coverage_summary'] = [
            'source' => (string) ($setCoverageBaseline['source'] ?? 'not_set'),
            'expected_total_occupations' => $expectedTotal,
            'excluded_first_wave_count' => count($excludedFirstWaveSlugs),
            'tracked_total_occupations' => $trackedTotal,
            'missing_occupations' => $missing,
            'tracking_complete' => $expectedTotal > 0 ? $missing === 0 : false,
        ];

        return $summary;
    }

    /**
     * @return array{manifests:list<string>, coverage_baseline:array<string, mixed>}
     */
    private function loadSetConfig(string $setPath): array
    {
        if ($setPath === '') {
            return [
                'manifests' => [],
                'coverage_baseline' => [],
            ];
        }

        $resolved = str_starts_with($setPath, '/')
            ? $setPath
            : base_path($setPath);
        if (! is_file($resolved)) {
            throw new RuntimeException(sprintf('Batch manifest set not found at [%s].', $resolved));
        }

        $decoded = json_decode((string) file_get_contents($resolved), true);
        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('Batch manifest set must be valid JSON: [%s].', $resolved));
        }

        $paths = [];
        foreach ((array) ($decoded['manifests'] ?? []) as $path) {
            $normalized = trim((string) $path);
            if ($normalized !== '') {
                $paths[] = $normalized;
            }
        }

        return [
            'manifests' => array_values(array_unique($paths)),
            'coverage_baseline' => is_array($decoded['coverage_baseline'] ?? null)
                ? (array) $decoded['coverage_baseline']
                : [],
        ];
    }
}
