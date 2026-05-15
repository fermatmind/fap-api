<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Expansion\CanonicalBatchPromotionExecutorService;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CareerExecuteCanonicalRolloutBatch extends Command
{
    protected $signature = 'career:execute-canonical-rollout-batch
        {--batch-id= : Canonical rollout batch identifier (required)}
        {--slugs= : Comma-separated list of canonical slugs (required)}
        {--locales= : Comma-separated list of target locales (required)}
        {--rollback-group= : Comma-separated rollback group slug list (required)}
        {--dry-run : Plan the transition without mutating state}
        {--apply : Execute the promotion (mutates database)}
        {--quarantine-on-failure : Quarantine batch on post-promotion failure instead of rolling back}
        {--projection= : Optional pre-promotion runtime publish projection JSON artifact path}
        {--json : Emit JSON output}';

    protected $description = 'Execute a canonical rollout batch promotion from published_candidate to published.';

    public function __construct(
        private readonly CanonicalBatchPromotionExecutorService $executor,
        private readonly CareerRuntimePublishProjectionExporter $projectionExporter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $batchId = $this->requiredOption('batch-id');
            $slugsRaw = $this->requiredOption('slugs');
            $localesRaw = $this->requiredOption('locales');
            $rollbackGroupRaw = $this->requiredOption('rollback-group');

            $dryRun = (bool) $this->option('dry-run');
            $apply = (bool) $this->option('apply');

            if ($dryRun && $apply) {
                throw new \RuntimeException('--dry-run and --apply are mutually exclusive');
            }

            if (! $dryRun && ! $apply) {
                throw new \RuntimeException('either --dry-run or --apply must be specified');
            }

            $prePromotionProjection = null;
            $projectionPath = $this->pathOption('projection');
            if ($projectionPath !== null) {
                $prePromotionProjection = $this->readProjection($projectionPath);
            } else {
                $prePromotionProjection = $this->projectionExporter->build();
            }

            $result = $this->executor->execute(
                params: [
                    'batch_id' => $batchId,
                    'slugs' => array_filter(array_map('trim', explode(',', $slugsRaw)), static fn (string $s): bool => $s !== ''),
                    'locales' => array_filter(array_map('trim', explode(',', $localesRaw)), static fn (string $s): bool => $s !== ''),
                    'rollback_group' => array_filter(array_map('trim', explode(',', $rollbackGroupRaw)), static fn (string $s): bool => $s !== ''),
                ],
                dryRun: $dryRun,
                quarantineOnFailure: (bool) $this->option('quarantine-on-failure'),
                prePromotionProjection: $prePromotionProjection,
            );

            $this->writeAuditReport($result);

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $this->line('status='.($result['status'] ?? 'unknown'));
                $this->line('batch_id='.($result['batch_id'] ?? ''));
                $this->line('promoted_slugs='.count($result['promoted_slugs'] ?? []));
                $this->line('promoted_locale_rows='.($result['promoted_locale_rows'] ?? 0));
                $this->line('dry_run='.($result['dry_run'] ? 'true' : 'false'));
                $this->line('writes_database='.($result['writes_database'] ? 'true' : 'false'));
                $this->line('rollback_required='.($result['rollback_required'] ? 'true' : 'false'));
                $this->line('quarantine_required='.($result['quarantine_required'] ? 'true' : 'false'));
            }

            return in_array($result['status'] ?? '', ['promoted_success', 'planned'], true) ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

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

    private function pathOption(string $name): ?string
    {
        $value = $this->option($name);
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function readProjection(string $path): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException('projection artifact not found: '.$path);
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            throw new \RuntimeException('projection artifact is not valid JSON: '.$path);
        }

        if (is_array($payload['items'] ?? null)) {
            return $payload;
        }

        return is_array($payload['projection'] ?? null) ? $payload['projection'] : $payload;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function writeAuditReport(array $result): void
    {
        $timestamp = now('UTC')->format('Ymd\THis\Z');
        $dir = storage_path('app/private/career_canonical_rollout_batch_executions');
        File::ensureDirectoryExists($dir);

        $path = $dir.DIRECTORY_SEPARATOR.$timestamp.'-'.($result['batch_id'] ?? 'unknown').'.json';
        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (is_string($encoded)) {
            File::put($path, $encoded.PHP_EOL);
        }
    }
}
