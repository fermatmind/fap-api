<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Attempt;
use App\Services\Storage\ArtifactStore;
use App\Support\SchemaBaseline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class StorageMigrateLegacyArtifacts extends Command
{
    protected $signature = 'storage:migrate-legacy-artifacts
        {--dry-run : Plan migration only}
        {--execute : Execute migration copy}';

    protected $description = 'Migrate legacy report/pdf artifacts into artifacts/* paths (copy only).';

    /** @var array<string,string> */
    private array $attemptScaleCache = [];

    public function __construct(
        private readonly ArtifactStore $artifactStore
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');

        if (($dryRun && $execute) || (! $dryRun && ! $execute)) {
            $this->error('exactly one of --dry-run or --execute is required.');

            return self::FAILURE;
        }

        $operations = $this->collectOperations();
        if ($dryRun) {
            $this->line('status=planned');
            $this->line('operations='.count($operations));
            $this->line('report_json_ops='.$this->countOperationsByKind($operations, 'report_json'));
            $this->line('pdf_ops='.$this->countOperationsByKind($operations, 'pdf'));

            return self::SUCCESS;
        }

        return $this->executeOperations($operations);
    }

    /**
     * @return list<array{kind:string,source:string,target:string,attempt_id:string,scale_code:string,manifest_hash:string,variant:string}>
     */
    private function collectOperations(): array
    {
        $operations = array_merge(
            $this->collectReportJsonOperations(),
            $this->collectPdfOperations()
        );

        usort(
            $operations,
            static fn (array $a, array $b): int => strcmp($a['source'].'|'.$a['target'], $b['source'].'|'.$b['target'])
        );

        return $operations;
    }

    /**
     * @return list<array{kind:string,source:string,target:string,attempt_id:string,scale_code:string,manifest_hash:string,variant:string}>
     */
    private function collectReportJsonOperations(): array
    {
        $operations = [];
        $seen = [];

        foreach ($this->legacyReportsFiles() as $path) {
            if (! str_ends_with($path, '/report.json')) {
                continue;
            }

            $attemptId = null;
            $scaleHint = null;

            if (preg_match('#^(?:private/)?reports/([^/]+)/report\.json$#', $path, $m) === 1) {
                $attemptId = (string) ($m[1] ?? '');
            } elseif (preg_match('#^(?:private/)?reports/([^/]+)/([^/]+)/report\.json$#', $path, $m) === 1) {
                $scaleHint = (string) ($m[1] ?? '');
                $attemptId = (string) ($m[2] ?? '');
            }

            $attemptId = trim((string) $attemptId);
            if ($attemptId === '') {
                continue;
            }

            $scaleCode = $this->resolveScaleCode($attemptId, $scaleHint);
            $target = $this->artifactStore->reportJsonPath($scaleCode, $attemptId);
            if ($target === $path) {
                continue;
            }

            $dedupeKey = $path.'|'.$target;
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $operations[] = [
                'kind' => 'report_json',
                'source' => $path,
                'target' => $target,
                'attempt_id' => $attemptId,
                'scale_code' => $scaleCode,
                'manifest_hash' => 'nohash',
                'variant' => 'free',
            ];
        }

        return $operations;
    }

    /**
     * @return list<array{kind:string,source:string,target:string,attempt_id:string,scale_code:string,manifest_hash:string,variant:string}>
     */
    private function collectPdfOperations(): array
    {
        $operations = [];
        $seen = [];

        foreach ($this->legacyReportsFiles() as $path) {
            if (! str_ends_with($path, '.pdf')) {
                continue;
            }

            $attemptId = '';
            $manifestHash = 'nohash';
            $variant = 'free';
            $scaleHint = null;

            if (preg_match('#^(?:private/)?reports/([^/]+)/([^/]+)/([^/]+)/report_(free|full)\.pdf$#i', $path, $m) === 1) {
                $scaleHint = (string) ($m[1] ?? '');
                $attemptId = (string) ($m[2] ?? '');
                $manifestHash = (string) ($m[3] ?? 'nohash');
                $variant = strtolower((string) ($m[4] ?? 'free'));
            } elseif (preg_match('#^(?:private/)?reports/big5/([^/]+)/report_(free|full)\.pdf$#i', $path, $m) === 1) {
                $scaleHint = 'BIG5_OCEAN';
                $attemptId = (string) ($m[1] ?? '');
                $manifestHash = 'nohash';
                $variant = strtolower((string) ($m[2] ?? 'free'));
            } else {
                continue;
            }

            $attemptId = trim($attemptId);
            if ($attemptId === '') {
                continue;
            }

            $variant = in_array($variant, ['free', 'full'], true) ? $variant : 'free';
            $scaleCode = $this->resolveScaleCode($attemptId, $scaleHint);
            $target = $this->artifactStore->pdfPath($scaleCode, $attemptId, $manifestHash, $variant);
            if ($target === $path) {
                continue;
            }

            $dedupeKey = $path.'|'.$target;
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $operations[] = [
                'kind' => 'pdf',
                'source' => $path,
                'target' => $target,
                'attempt_id' => $attemptId,
                'scale_code' => $scaleCode,
                'manifest_hash' => trim($manifestHash) !== '' ? trim($manifestHash) : 'nohash',
                'variant' => $variant,
            ];
        }

        return $operations;
    }

    /**
     * @return list<string>
     */
    private function legacyReportsFiles(): array
    {
        $disk = Storage::disk('local');
        $files = [];

        foreach (['reports', 'private/reports'] as $dir) {
            if (! $disk->exists($dir)) {
                continue;
            }

            foreach ($disk->allFiles($dir) as $path) {
                $path = str_replace('\\', '/', trim((string) $path));
                if ($path !== '') {
                    $files[] = $path;
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * @param  list<array{kind:string,source:string,target:string,attempt_id:string,scale_code:string,manifest_hash:string,variant:string}>  $operations
     */
    private function executeOperations(array $operations): int
    {
        $disk = Storage::disk('local');

        $copied = 0;
        $copiedBytes = 0;
        $skippedExisting = 0;
        $missingSource = 0;
        $failed = 0;

        foreach ($operations as $operation) {
            $source = $operation['source'];
            $target = $operation['target'];

            if (! $disk->exists($source)) {
                $missingSource++;
                continue;
            }

            $sourceBytes = $disk->get($source);
            if (! is_string($sourceBytes) || $sourceBytes === '') {
                $failed++;
                $this->warn('skip unreadable source: '.$source);
                continue;
            }

            $copiedNow = false;
            if (! $disk->exists($target)) {
                $targetDir = trim((string) dirname($target), './');
                if ($targetDir !== '' && $targetDir !== '.') {
                    $disk->makeDirectory($targetDir);
                }

                if (! $disk->copy($source, $target)) {
                    $disk->put($target, $sourceBytes);
                }

                if (! $disk->exists($target)) {
                    $failed++;
                    $this->warn('copy failed: '.$source.' -> '.$target);
                    continue;
                }

                $copiedNow = true;
                $copied++;
                $copiedBytes += strlen($sourceBytes);
            } else {
                $skippedExisting++;
            }

            $targetBytes = $disk->get($target);
            $sha256 = is_string($targetBytes) ? hash('sha256', $targetBytes) : hash('sha256', $sourceBytes);
            $this->writeAuditLog($operation, $sha256, $copiedNow);
        }

        $this->line('status=executed');
        $this->line('operations='.count($operations));
        $this->line('copied='.$copied);
        $this->line('copied_bytes='.$copiedBytes);
        $this->line('skipped_existing='.$skippedExisting);
        $this->line('missing_source='.$missingSource);
        $this->line('failed='.$failed);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array{kind:string,source:string,target:string,attempt_id:string,scale_code:string,manifest_hash:string,variant:string}  $operation
     */
    private function writeAuditLog(array $operation, string $sha256, bool $copiedNow): void
    {
        if (! SchemaBaseline::hasTable('audit_logs')) {
            return;
        }

        $meta = [
            'kind' => (string) ($operation['kind'] ?? ''),
            'source_path' => (string) ($operation['source'] ?? ''),
            'target_path' => (string) ($operation['target'] ?? ''),
            'scale_code' => (string) ($operation['scale_code'] ?? ''),
            'manifest_hash' => (string) ($operation['manifest_hash'] ?? ''),
            'variant' => (string) ($operation['variant'] ?? ''),
            'copied' => $copiedNow,
            'sha256' => $sha256,
        ];
        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($metaJson)) {
            $metaJson = '{}';
        }

        $row = [
            'action' => 'storage_artifact_migrate',
            'target_type' => 'attempt',
            'target_id' => (string) ($operation['attempt_id'] ?? ''),
            'meta_json' => $metaJson,
            'created_at' => now(),
        ];

        if (SchemaBaseline::hasColumn('audit_logs', 'org_id')) {
            $row['org_id'] = 0;
        }
        if (SchemaBaseline::hasColumn('audit_logs', 'actor_admin_id')) {
            $row['actor_admin_id'] = null;
        }
        if (SchemaBaseline::hasColumn('audit_logs', 'ip')) {
            $row['ip'] = '127.0.0.1';
        }
        if (SchemaBaseline::hasColumn('audit_logs', 'user_agent')) {
            $row['user_agent'] = 'artisan';
        }
        if (SchemaBaseline::hasColumn('audit_logs', 'request_id')) {
            $row['request_id'] = 'storage:migrate-legacy-artifacts';
        }
        if (SchemaBaseline::hasColumn('audit_logs', 'reason')) {
            $row['reason'] = 'storage_governance_migrate';
        }
        if (SchemaBaseline::hasColumn('audit_logs', 'result')) {
            $row['result'] = $copiedNow ? 'success' : 'skipped';
        }

        DB::table('audit_logs')->insert($row);
    }

    private function resolveScaleCode(string $attemptId, ?string $hint = null): string
    {
        $attemptId = trim($attemptId);
        if ($attemptId !== '' && isset($this->attemptScaleCache[$attemptId])) {
            return $this->attemptScaleCache[$attemptId];
        }

        $scaleCode = '';
        if ($attemptId !== '' && SchemaBaseline::hasTable('attempts')) {
            $scaleCode = trim((string) (Attempt::query()->where('id', $attemptId)->value('scale_code') ?? ''));
        }

        if ($scaleCode === '') {
            $hint = trim((string) $hint);
            if (strcasecmp($hint, 'big5') === 0) {
                $hint = 'BIG5_OCEAN';
            }

            $scaleCode = $hint;
        }

        if ($scaleCode === '') {
            $scaleCode = 'MBTI';
        }

        if ($attemptId !== '') {
            $this->attemptScaleCache[$attemptId] = $scaleCode;
        }

        return $scaleCode;
    }

    /**
     * @param  list<array{kind:string,source:string,target:string,attempt_id:string,scale_code:string,manifest_hash:string,variant:string}>  $operations
     */
    private function countOperationsByKind(array $operations, string $kind): int
    {
        $count = 0;
        foreach ($operations as $operation) {
            if (($operation['kind'] ?? '') === $kind) {
                $count++;
            }
        }

        return $count;
    }
}
