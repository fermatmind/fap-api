<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoOps\ArticleRecoveryBatchPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

final class Seo10kArticleRecoveryBatchCommand extends Command
{
    protected $signature = 'seo-ops:article-recovery-batch
        {--evidence= : Path to the sanitized live GSC recovery evidence JSON}
        {--confirm-evidence-sha256= : Exact SHA-256 of the evidence JSON}
        {--artifact-dir= : Directory for the deterministic dry-run package}
        {--json : Emit JSON}';

    protected $description = 'Build the deterministic, zero-write five-article recovery review package.';

    public function handle(ArticleRecoveryBatchPlanner $planner): int
    {
        try {
            $package = $planner->plan(
                (string) $this->option('evidence'),
                trim((string) $this->option('confirm-evidence-sha256')),
            );
        } catch (Throwable) {
            $package = [
                'schema_version' => ArticleRecoveryBatchPlanner::OUTPUT_SCHEMA,
                'task' => ArticleRecoveryBatchPlanner::TASK,
                'status' => 'blocked',
                'ok' => false,
                'dry_run' => true,
                'would_write' => false,
                'issues' => ['unexpected_planner_failure'],
            ];
        }

        $artifact = null;
        $commandIssues = [];
        if (($package['ok'] ?? false) === true || ($package['candidate_package_built'] ?? false) === true) {
            $artifact = $this->writeArtifact($planner, $package);
            if (($artifact['ok'] ?? false) !== true) {
                $commandIssues[] = (string) ($artifact['issue'] ?? 'artifact_write_failed');
            }
        }

        $summary = [
            ...$package,
            'command' => [
                'ok' => $commandIssues === [],
                'issues' => $commandIssues,
            ],
            'artifact' => $artifact,
        ];
        $this->emit($summary, $planner);

        return ($package['ok'] ?? false) === true && $commandIssues === []
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    private function writeArtifact(ArticleRecoveryBatchPlanner $planner, array $package): array
    {
        $artifactDir = trim((string) $this->option('artifact-dir'));
        if ($artifactDir === '') {
            return ['ok' => false, 'issue' => 'artifact_dir_required'];
        }
        if (is_link($artifactDir)) {
            return ['ok' => false, 'issue' => 'artifact_dir_unsafe'];
        }

        try {
            File::ensureDirectoryExists($artifactDir);
        } catch (Throwable) {
            return ['ok' => false, 'issue' => 'artifact_dir_unwritable'];
        }
        if (is_link($artifactDir) || ! is_dir($artifactDir) || ! is_writable($artifactDir)) {
            return ['ok' => false, 'issue' => 'artifact_dir_unwritable'];
        }

        $resolvedArtifactDir = realpath($artifactDir);
        if (! is_string($resolvedArtifactDir)) {
            return ['ok' => false, 'issue' => 'artifact_dir_unwritable'];
        }

        $path = rtrim($resolvedArtifactDir, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .'seo-10k-article-recovery-batch-01.dry-run.json';
        if (is_link($path) || (file_exists($path) && ! is_file($path))) {
            return ['ok' => false, 'issue' => 'artifact_destination_unsafe'];
        }

        $bytes = $planner->prettyJson($package);
        $changed = ! is_file($path) || (string) file_get_contents($path) !== $bytes;
        if ($changed) {
            try {
                $temporaryPath = tempnam($resolvedArtifactDir, '.seo-recovery-');
            } catch (Throwable) {
                return ['ok' => false, 'issue' => 'artifact_write_failed'];
            }
            if (! is_string($temporaryPath)) {
                return ['ok' => false, 'issue' => 'artifact_write_failed'];
            }

            try {
                if (is_link($temporaryPath)
                    || file_put_contents($temporaryPath, $bytes, LOCK_EX) !== strlen($bytes)
                    || ! rename($temporaryPath, $path)) {
                    return ['ok' => false, 'issue' => 'artifact_write_failed'];
                }
            } finally {
                if (file_exists($temporaryPath) || is_link($temporaryPath)) {
                    File::delete($temporaryPath);
                }
            }
        }

        return [
            'ok' => true,
            'path' => $path,
            'sha256' => hash('sha256', $bytes),
            'changed' => $changed,
            'business_write' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function emit(array $summary, ArticleRecoveryBatchPlanner $planner): void
    {
        if ((bool) $this->option('json')) {
            $this->line($planner->prettyJson($summary));

            return;
        }

        $this->line('ok='.(($summary['ok'] ?? false) ? '1' : '0'));
        $this->line('status='.(string) ($summary['status'] ?? 'blocked'));
        $this->line('dry_run=1');
        $this->line('would_write=0');
        $this->line('target_count='.(string) data_get($summary, 'selection.target_count', 0));
        $this->line('target_set_sha256='.(string) ($summary['target_set_sha256'] ?? ''));
        $this->line('package_sha256='.(string) ($summary['package_sha256'] ?? ''));
        $this->line('artifact='.(string) data_get($summary, 'artifact.path', ''));

        foreach ((array) ($summary['issues'] ?? []) as $issue) {
            $this->line('issue='.(string) $issue);
        }
        foreach ((array) data_get($summary, 'command.issues', []) as $issue) {
            $this->line('command_issue='.(string) $issue);
        }
    }
}
