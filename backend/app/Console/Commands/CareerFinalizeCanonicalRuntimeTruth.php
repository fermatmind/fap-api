<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Publish\CareerCanonicalRuntimeTruthExporter;
use App\Domain\Career\Publish\CareerCanonicalRuntimeTruthValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CareerFinalizeCanonicalRuntimeTruth extends Command
{
    public const FINALIZATION_FILENAME = 'career-canonical-runtime-truth-finalization.json';

    protected $signature = 'career:finalize-canonical-runtime-truth
        {--timestamp= : Optional output directory timestamp segment}
        {--ledger= : Optional Career full release ledger JSON artifact}
        {--projection= : Optional Career runtime publish projection JSON artifact}
        {--json : Emit JSON output}';

    protected $description = 'Export and validate final Career canonical runtime truth counts for rollout readiness.';

    public function __construct(
        private readonly CareerCanonicalRuntimeTruthExporter $exporter,
        private readonly CareerCanonicalRuntimeTruthValidator $validator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $timestamp = $this->normalizeTimestamp($this->option('timestamp') !== null ? (string) $this->option('timestamp') : null);
            $rootDir = storage_path('app/private/career_canonical_runtime_truth_finalization');
            $finalDir = $rootDir.DIRECTORY_SEPARATOR.$timestamp;
            $tmpDir = $finalDir.'.tmp';

            if (is_dir($finalDir) || is_dir($tmpDir)) {
                throw new \RuntimeException('canonical runtime truth finalization output dir already exists: '.$finalDir);
            }

            $truth = $this->exporter->build($this->ledgerPathOption(), $this->projectionPathOption());
            $validation = $this->validator->validate($truth);
            $payload = $this->payload($truth, $validation);

            File::ensureDirectoryExists($tmpDir);
            $tmpPath = $tmpDir.DIRECTORY_SEPARATOR.self::FINALIZATION_FILENAME;
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (! is_string($encoded)) {
                throw new \RuntimeException('failed to encode canonical runtime truth finalization payload');
            }
            File::put($tmpPath, $encoded.PHP_EOL);

            if (! @rename($tmpDir, $finalDir)) {
                throw new \RuntimeException('failed to finalize canonical runtime truth finalization output dir: '.$finalDir);
            }

            $path = $finalDir.DIRECTORY_SEPARATOR.self::FINALIZATION_FILENAME;
            $commandPayload = [
                'status' => $payload['status'],
                'output_dir' => $finalDir,
                'artifacts' => [
                    self::FINALIZATION_FILENAME => $path,
                ],
                'finalization' => $payload,
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($commandPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $this->line('status='.$payload['status']);
                $this->line('fully_live='.(string) data_get($payload, 'counts.fully_live', 0));
                $this->line('surface_equality='.$payload['surface_equality']);
            }

            return $payload['status'] === 'complete' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $truth
     * @param  array<string, mixed>  $validation
     * @return array<string, mixed>
     */
    private function payload(array $truth, array $validation): array
    {
        $counts = is_array($truth['counts'] ?? null) ? $truth['counts'] : [];
        $validationCounts = is_array($validation['counts'] ?? null) ? $validation['counts'] : [];
        $surfaceEquality = ($validation['status'] ?? null) === 'pass' ? 'pass' : 'blocked';

        return [
            'status' => $surfaceEquality === 'pass' ? 'complete' : 'blocked',
            'phase' => 'Canonical Runtime Truth Finalization',
            'source_authority' => $truth['source_authority'] ?? 'CareerFullReleaseLedger',
            'truth_kind' => $truth['truth_kind'] ?? null,
            'truth_version' => $truth['truth_version'] ?? null,
            'counts' => [
                'canonical_projection_rows' => (int) ($counts['canonical_projection_rows'] ?? 0),
                'published' => (int) ($counts['published'] ?? 0),
                'route_live' => (int) ($counts['final_200'] ?? 0),
                'dataset_visible' => (int) ($counts['dataset_visible'] ?? 0),
                'search_visible' => (int) ($counts['search_visible'] ?? 0),
                'sitemap_live' => (int) ($counts['sitemap_live'] ?? 0),
                'llms_live' => (int) ($counts['llms_live'] ?? 0),
                'llms_full_live' => (int) ($counts['llms_full_live'] ?? 0),
                'fully_live' => (int) ($counts['fully_live'] ?? 0),
            ],
            'surface_equality' => $surfaceEquality,
            'validation' => [
                'status' => $validation['status'] ?? null,
                'mismatch_count' => (int) ($validationCounts['failures'] ?? 0),
                'projection_only' => (int) ($validationCounts['projection_only'] ?? 0),
                'dataset_only' => (int) ($validationCounts['dataset_only'] ?? 0),
                'search_only' => (int) ($validationCounts['search_only'] ?? 0),
                'route_only' => (int) ($validationCounts['route_only'] ?? 0),
                'sitemap_only' => (int) ($validationCounts['sitemap_only'] ?? 0),
                'llms_only' => (int) ($validationCounts['llms_only'] ?? 0),
                'llms_full_only' => (int) ($validationCounts['llms_full_only'] ?? 0),
                'failures' => $validation['failures'] ?? [],
            ],
            'rollout_readiness' => [
                'ready_for_expansion_batches' => $surfaceEquality === 'pass',
                'recommended_first_batch_size' => $surfaceEquality === 'pass' ? 50 : 0,
                'requires_surface_reconciliation_before_rollout' => $surfaceEquality !== 'pass',
            ],
        ];
    }

    private function normalizeTimestamp(?string $value): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            $normalized = now('UTC')->format('Ymd\THis\Z');
        }

        if (! preg_match('/^[A-Za-z0-9._-]+$/', $normalized)) {
            throw new \RuntimeException('invalid timestamp segment for canonical runtime truth finalization');
        }

        return $normalized;
    }

    private function ledgerPathOption(): ?string
    {
        $value = $this->option('ledger');
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function projectionPathOption(): ?string
    {
        $value = $this->option('projection');
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }
}
