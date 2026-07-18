<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\MbtiFullCmsImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityMbtiCompRuntime46IntpRevision extends Command
{
    private const WRITE_SAFETY_FLAGS = [
        'production-write-authorized',
        'no-publication-change',
        'no-indexability-change',
        'no-sitemap',
        'no-llms',
        'no-search-release',
    ];

    protected $signature = 'personality:mbti-comp-runtime46-intp-revision
        {--package= : Path to the exact one-record INTP revision package}
        {--source-package-sha256= : Exact operator-authorized source package sha256}
        {--authorization-payload-sha256= : Exact operator-authorized authorization payload sha256}
        {--import-scope-mode= : Must be single_intp_at_content_revision_only}
        {--record-count= : Must be 1}
        {--dry-run : Validate and plan without database writes}
        {--write : Stage the exact content revision as draft only}
        {--production-write-authorized : Required with --write}
        {--no-publication-change : Required with --write}
        {--no-indexability-change : Required with --write}
        {--no-sitemap : Required with --write}
        {--no-llms : Required with --write}
        {--no-search-release : Required with --write}
        {--json : Emit the full JSON summary}
        {--output= : Optional path for exact preflight/write evidence JSON}';

    protected $description = 'Fail-closed one-record INTP-A vs INTP-T draft revision preflight/import.';

    public function handle(MbtiFullCmsImportService $service): int
    {
        try {
            $summary = $this->buildSummary($service);
        } catch (Throwable $exception) {
            $summary = [
                'artifact' => 'MBTI-COMP-RUNTIME-46-INTP-REVISION-IMPORT',
                'ok' => false,
                'status' => 'fail',
                'write' => (bool) $this->option('write'),
                'writes_committed' => false,
                'cms_write_attempted' => false,
                'errors' => [['field' => 'command', 'code' => $exception instanceof RuntimeException ? 'runtime_error' : 'unexpected_error', 'message' => $exception->getMessage()]],
            ];
        }

        $this->writeOutput($summary);
        $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string,mixed> */
    private function buildSummary(MbtiFullCmsImportService $service): array
    {
        $write = (bool) $this->option('write');
        $dryRun = (bool) $this->option('dry-run');
        if ($write === $dryRun) {
            throw new RuntimeException('Exactly one of --dry-run or --write is required.');
        }
        if ($write) {
            foreach (self::WRITE_SAFETY_FLAGS as $flag) {
                if (! (bool) $this->option($flag)) {
                    throw new RuntimeException('--'.$flag.' is required with --write.');
                }
            }
        }

        $path = trim((string) $this->option('package'));
        $resolved = str_starts_with($path, '/') ? $path : base_path($path);
        if ($path === '' || ! File::isFile($resolved)) {
            throw new RuntimeException('A readable --package file is required.');
        }
        $package = json_decode((string) File::get($resolved), true);
        if (! is_array($package)) {
            throw new RuntimeException('Package must be a JSON object.');
        }
        $options = [
            'expected_source_package_sha256' => trim((string) $this->option('source-package-sha256')),
            'expected_authorization_payload_sha256' => trim((string) $this->option('authorization-payload-sha256')),
            'expected_import_scope_mode' => trim((string) $this->option('import-scope-mode')),
            'expected_record_count' => trim((string) $this->option('record-count')),
        ];
        if (in_array('', $options, true)) {
            throw new RuntimeException('All exact hash, scope, and record-count options are required.');
        }

        return array_merge(
            $write ? $service->stageIntpRevision($package, $options) : $service->planIntpRevision($package, $options),
            ['command' => 'personality:mbti-comp-runtime46-intp-revision', 'package_path' => $resolved],
        );
    }

    /** @param array<string,mixed> $summary */
    private function writeOutput(array $summary): void
    {
        $path = trim((string) $this->option('output'));
        if ($path === '') {
            return;
        }
        $resolved = str_starts_with($path, '/') ? $path : base_path($path);
        File::ensureDirectoryExists(dirname($resolved));
        File::put($resolved, (string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL);
    }
}
