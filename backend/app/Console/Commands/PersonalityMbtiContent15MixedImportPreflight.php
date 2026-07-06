<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\MbtiContent15MixedImportPreflightPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityMbtiContent15MixedImportPreflight extends Command
{
    protected $signature = 'personality:mbti-content15-mixed-import-preflight
        {--package= : Path to the MBTI-CMS-22 final dry-run JSON package}
        {--authorization-package= : Path to the MBTI-CMS-23 authorization JSON package}
        {--source-package-sha256= : Expected CMS-22 exact package sha256}
        {--authorization-payload-sha256= : Expected CMS-23 authorization payload sha256}
        {--import-scope-mode= : Expected import scope mode}
        {--record-count= : Expected import record count}
        {--dry-run : Required; validate and plan without database writes}
        {--write : Unsupported in this PR; fails closed before any mutation}
        {--json : Emit the full JSON preflight plan}
        {--output= : Optional path to write the JSON preflight plan}';

    protected $description = 'Preflight the CONTENT-15 mixed MBTI CMS import package without CMS writes.';

    public function handle(MbtiContent15MixedImportPreflightPlanner $planner): int
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
    private function guardedPlan(MbtiContent15MixedImportPreflightPlanner $planner): array
    {
        if ((bool) $this->option('write')) {
            throw new RuntimeException('--write is intentionally unsupported in MBTI-CMS-26; MBTI-CMS-27 owns production CMS import execution after separate exact authorization.');
        }

        if (! (bool) $this->option('dry-run')) {
            throw new RuntimeException('--dry-run is required so this command cannot be mistaken for a write/import command.');
        }

        $packagePath = $this->requiredPathOption('package');
        $authorizationPath = $this->requiredPathOption('authorization-package');

        [$package, $packageRaw, $resolvedPackagePath] = $this->readJsonFile($packagePath);
        [$authorizationPackage, $authorizationRaw, $resolvedAuthorizationPath] = $this->readJsonFile($authorizationPath);

        return array_merge($planner->plan($package, $authorizationPackage, [
            'package_file_sha256' => hash('sha256', $packageRaw),
            'authorization_file_sha256' => hash('sha256', $authorizationRaw),
            'expected_source_package_sha256' => trim((string) $this->option('source-package-sha256')),
            'expected_authorization_payload_sha256' => trim((string) $this->option('authorization-payload-sha256')),
            'expected_import_scope_mode' => trim((string) $this->option('import-scope-mode')),
            'expected_record_count' => trim((string) $this->option('record-count')),
        ]), [
            'package_path' => $resolvedPackagePath,
            'authorization_package_path' => $resolvedAuthorizationPath,
            'command' => 'personality:mbti-content15-mixed-import-preflight',
        ]);
    }

    private function requiredPathOption(string $name): string
    {
        $path = trim((string) $this->option($name));
        if ($path === '') {
            throw new RuntimeException('--'.$name.' is required.');
        }

        return $path;
    }

    /**
     * @return array{0:array<string,mixed>,1:string,2:string}
     */
    private function readJsonFile(string $path): array
    {
        $resolved = str_starts_with($path, '/')
            ? $path
            : base_path($path);

        if (! File::isFile($resolved)) {
            throw new RuntimeException('Package file not found: '.$resolved);
        }

        $raw = (string) File::get($resolved);
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Package must be a JSON object: '.$resolved);
        }

        return [$decoded, $raw, $resolved];
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
        $this->line('profile_record_count='.(string) ($summary['profile_record_count'] ?? 0));
        $this->line('at_comparison_count='.(string) ($summary['at_comparison_count'] ?? 0));
        $this->line('cross_type_comparison_count='.(string) ($summary['cross_type_comparison_count'] ?? 0));
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
            'artifact' => 'MBTI-CMS-26-CONTENT15-MIXED-IMPORT-PREFLIGHT',
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
