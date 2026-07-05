<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\BigFiveCmsImportDraftDryRunPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityBigFiveCmsImportDraftDryRun extends Command
{
    protected $signature = 'personality:big-five-cms-import-draft-dry-run
        {--package= : Path to the Big Five cms-import-draft.json package}
        {--dry-run : Required; validate and plan without database writes}
        {--write : Unsupported in this PR; fails closed}
        {--json : Emit the full JSON dry-run plan}
        {--output= : Optional path to write the JSON dry-run plan}';

    protected $description = 'Validate Big Five cms-import-draft mapping as a no-write dry run.';

    public function handle(BigFiveCmsImportDraftDryRunPlanner $planner): int
    {
        try {
            $summary = $this->guardedPlan($planner);
        } catch (RuntimeException $exception) {
            $summary = $this->failureSummary($exception->getMessage());
        } catch (Throwable $exception) {
            $summary = $this->failureSummary($exception->getMessage(), 'unexpected_error');
        }

        $this->writeOutputFile($summary);
        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function guardedPlan(BigFiveCmsImportDraftDryRunPlanner $planner): array
    {
        if ((bool) $this->option('write')) {
            throw new RuntimeException('--write is intentionally unsupported in BIG5-CMS-IMPORT-DRYRUN-01.');
        }

        if (! (bool) $this->option('dry-run')) {
            throw new RuntimeException('--dry-run is required so this command cannot be mistaken for a write/import command.');
        }

        $packagePath = trim((string) $this->option('package'));
        if ($packagePath === '') {
            throw new RuntimeException('--package is required.');
        }

        $resolved = $this->resolvePath($packagePath);
        $raw = (string) File::get($resolved);
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Package must be a JSON array or object.');
        }

        return array_merge($planner->plan($decoded, hash('sha256', $raw)), [
            'package_path' => $resolved,
            'command' => 'personality:big-five-cms-import-draft-dry-run',
        ]);
    }

    private function resolvePath(string $path): string
    {
        $resolved = str_starts_with($path, '/')
            ? $path
            : base_path($path);

        if (! File::isFile($resolved)) {
            throw new RuntimeException('Package file not found: '.$resolved);
        }

        return $resolved;
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function emitSummary(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $summary,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ));

            return;
        }

        $this->line('ok='.(($summary['ok'] ?? false) ? '1' : '0'));
        $this->line('status='.(string) ($summary['status'] ?? 'fail'));
        $this->line('dry_run_only='.(($summary['dry_run_only'] ?? false) ? '1' : '0'));
        $this->line('write_supported_in_this_pr='.(($summary['write_supported_in_this_pr'] ?? false) ? '1' : '0'));
        $this->line('writes_committed='.(($summary['writes_committed'] ?? false) ? '1' : '0'));
        $this->line('cms_write_attempted='.(($summary['cms_write_attempted'] ?? false) ? '1' : '0'));
        $this->line('publish_attempted='.(($summary['publish_attempted'] ?? false) ? '1' : '0'));
        $this->line('index_attempted='.(($summary['index_attempted'] ?? false) ? '1' : '0'));
        $this->line('sitemap_llms_release_attempted='.(($summary['sitemap_llms_release_attempted'] ?? false) ? '1' : '0'));
        $this->line('row_count='.(string) ($summary['row_count'] ?? 0));
        $this->line('errors_count='.(string) count((array) ($summary['errors'] ?? [])));
        $this->line('warnings_count='.(string) count((array) ($summary['warnings'] ?? [])));
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function writeOutputFile(array $summary): void
    {
        $output = trim((string) $this->option('output'));
        if ($output === '') {
            return;
        }

        $resolved = str_starts_with($output, '/')
            ? $output
            : base_path($output);
        File::ensureDirectoryExists(dirname($resolved));
        File::put($resolved, ((string) json_encode(
            $summary,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        )).PHP_EOL);
    }

    /**
     * @return array<string,mixed>
     */
    private function failureSummary(string $message, string $code = 'runtime_error'): array
    {
        return [
            'artifact' => 'BIG5-CMS-IMPORT-DRYRUN-01',
            'status' => 'fail',
            'ok' => false,
            'dry_run_only' => true,
            'write_supported_in_this_pr' => false,
            'writes_committed' => false,
            'cms_write_attempted' => false,
            'publish_attempted' => false,
            'index_attempted' => false,
            'search_release_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'errors' => [[
                'field' => 'command',
                'code' => $code,
                'message' => $message,
            ]],
            'warnings' => [],
        ];
    }
}
