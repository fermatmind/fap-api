<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\MbtiFullProfileImportDryRunPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityMbtiFullProfileImportDryRun extends Command
{
    protected $signature = 'personality:mbti-full-profile-import-dry-run
        {--package=* : Paths to the four approved Chinese MBTI Profile content packages}
        {--dry-run : Required; validate and plan without database writes}
        {--write : Unsupported; fails closed before reading packages}
        {--json : Emit the full JSON dry-run plan}
        {--output= : Optional path to write the JSON dry-run plan}';

    protected $description = 'Validate all 32 approved Chinese MBTI Profile assets as a no-write CMS import plan.';

    public function handle(MbtiFullProfileImportDryRunPlanner $planner): int
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

    /** @return array<string,mixed> */
    private function guardedPlan(MbtiFullProfileImportDryRunPlanner $planner): array
    {
        if ((bool) $this->option('write')) {
            throw new RuntimeException('--write is intentionally unsupported in MBTI-CMS-PROFILE-37.');
        }

        if (! (bool) $this->option('dry-run')) {
            throw new RuntimeException('--dry-run is required so this command cannot be mistaken for a write/import command.');
        }

        $packagePaths = array_values(array_filter(array_map(
            static fn (mixed $path): string => trim((string) $path),
            (array) $this->option('package'),
        ), static fn (string $path): bool => $path !== ''));
        if ($packagePaths === []) {
            throw new RuntimeException('At least one --package is required.');
        }

        $packages = [];
        foreach ($packagePaths as $path) {
            $resolved = $this->resolvePath($path);
            $raw = (string) File::get($resolved);
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                throw new RuntimeException('Package must be a JSON object: '.$resolved);
            }

            $packages[] = [
                'path' => $resolved,
                'sha256' => hash('sha256', $raw),
                'payload' => $decoded,
            ];
        }

        return array_merge($planner->plan($packages), [
            'command' => 'personality:mbti-full-profile-import-dry-run',
        ]);
    }

    private function resolvePath(string $path): string
    {
        $resolved = str_starts_with($path, '/') ? $path : base_path($path);
        if (! File::isFile($resolved)) {
            throw new RuntimeException('Package file not found: '.$resolved);
        }

        return $resolved;
    }

    /** @param array<string,mixed> $summary */
    private function emitSummary(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

            return;
        }

        foreach (['ok', 'status', 'dry_run_only', 'write_supported_in_this_pr', 'writes_committed', 'cms_write_attempted', 'record_count', 'repair_record_count', 'verify_only_record_count'] as $key) {
            $value = $summary[$key] ?? ($key === 'ok' ? false : '');
            $this->line($key.'='.(is_bool($value) ? ($value ? '1' : '0') : (string) $value));
        }
        $this->line('errors_count='.(string) count((array) ($summary['errors'] ?? [])));
        $this->line('warnings_count='.(string) count((array) ($summary['warnings'] ?? [])));
    }

    /** @param array<string,mixed> $summary */
    private function writeOutputFile(array $summary): void
    {
        $output = trim((string) $this->option('output'));
        if ($output === '') {
            return;
        }

        $resolved = str_starts_with($output, '/') ? $output : base_path($output);
        File::ensureDirectoryExists(dirname($resolved));
        File::put($resolved, ((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)).PHP_EOL);
    }

    /** @return array<string,mixed> */
    private function failureSummary(string $message, string $code = 'runtime_error'): array
    {
        return [
            'artifact' => 'MBTI-CMS-PROFILE-37-FULL-DRY-RUN',
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
            'errors' => [['field' => 'command', 'code' => $code, 'message' => $message]],
            'warnings' => [],
        ];
    }
}
