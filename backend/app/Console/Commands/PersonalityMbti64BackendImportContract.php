<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\Mbti64BackendImportContractPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityMbti64BackendImportContract extends Command
{
    protected $signature = 'personality:mbti64-backend-import-contract
        {--package= : Path to the MBTI64 pilot V2.1 JSON package}
        {--dry-run : Required; validate and plan without database writes}
        {--write : Unsupported in this PR; fails closed}
        {--json : Emit the full JSON contract plan}
        {--output= : Optional path to write the JSON contract plan}';

    protected $description = 'Validate the MBTI64 pilot V2.1 backend import contract as a no-write dry run.';

    public function handle(Mbti64BackendImportContractPlanner $planner): int
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
    private function guardedPlan(Mbti64BackendImportContractPlanner $planner): array
    {
        if ((bool) $this->option('write')) {
            throw new RuntimeException('--write is intentionally unsupported in MBTI64-BACKEND-IMPORT-CONTRACT-PATCH-01.');
        }

        if (! (bool) $this->option('dry-run')) {
            throw new RuntimeException('--dry-run is required so this command cannot be mistaken for a write/import command.');
        }

        $packagePath = trim((string) $this->option('package'));
        if ($packagePath === '') {
            throw new RuntimeException('--package is required.');
        }

        $resolved = $this->resolvePath($packagePath);
        $decoded = json_decode((string) File::get($resolved), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Package must be a JSON object.');
        }

        return array_merge($planner->plan($decoded), [
            'package_path' => $resolved,
            'command' => 'personality:mbti64-backend-import-contract',
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
        $this->line('publish_attempted='.(($summary['publish_attempted'] ?? false) ? '1' : '0'));
        $this->line('search_release_attempted='.(($summary['search_release_attempted'] ?? false) ? '1' : '0'));
        $this->line('row_count='.(string) ($summary['row_count'] ?? 0));
        $this->line('variant_row_count='.(string) ($summary['variant_row_count'] ?? 0));
        $this->line('comparison_row_count='.(string) ($summary['comparison_row_count'] ?? 0));
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
            'artifact' => 'MBTI64-BACKEND-IMPORT-CONTRACT-PATCH-01',
            'status' => 'fail',
            'ok' => false,
            'dry_run_only' => true,
            'write_supported_in_this_pr' => false,
            'writes_committed' => false,
            'publish_attempted' => false,
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
