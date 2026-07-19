<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\MbtiCompRuntime46IntpPromotionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityMbtiCompRuntime46IntpPromote extends Command
{
    private const WRITE_FLAGS = ['production-content-write-authorized', 'no-publication-change', 'no-indexability-change', 'no-sitemap', 'no-llms', 'no-search-release'];

    protected $signature = 'personality:mbti-comp-runtime46-intp-promote
        {--package= : Exact one-record INTP revision package}
        {--source-package-sha256= : Exact source package sha256}
        {--authorization-payload-sha256= : Exact source authorization payload sha256}
        {--promotion-package-sha256= : Exact promotion package sha256; required with --write}
        {--promotion-authorization-sha256= : Exact promotion authorization sha256; required with --write}
        {--import-scope-mode= : Must be single_intp_at_content_revision_only}
        {--record-count= : Must be 1}
        {--dry-run : Plan the exact public content apply without writes}
        {--write : Apply only the exact INTP comparison public content revision}
        {--rollback : Restore only the exact previous INTP comparison section from the promotion receipt}
        {--production-content-write-authorized : Required with --write}
        {--rollback-on-readback-failure-authorized : Required with --rollback}
        {--no-publication-change : Required with --write}
        {--no-indexability-change : Required with --write}
        {--no-sitemap : Required with --write}
        {--no-llms : Required with --write}
        {--no-search-release : Required with --write}
        {--json : Emit JSON}
        {--output= : Optional evidence JSON path}';

    protected $description = 'Fail-closed exact one-record Runtime 46 INTP public content promotion.';

    public function handle(MbtiCompRuntime46IntpPromotionService $service): int
    {
        try {
            $summary = $this->summary($service);
        } catch (Throwable $exception) {
            $summary = ['artifact' => 'MBTI-COMP-RUNTIME-46-INTP-PUBLIC-CONTENT-PROMOTION', 'ok' => false, 'status' => 'fail', 'writes_committed' => false, 'errors' => [['field' => 'command', 'code' => $exception instanceof RuntimeException ? 'runtime_error' : 'unexpected_error', 'message' => $exception->getMessage()]]];
        }
        $this->writeOutput($summary);
        $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string,mixed> */
    private function summary(MbtiCompRuntime46IntpPromotionService $service): array
    {
        $write = (bool) $this->option('write');
        $rollback = (bool) $this->option('rollback');
        $dryRun = (bool) $this->option('dry-run');
        if (((int) $write + (int) $rollback + (int) $dryRun) !== 1) {
            throw new RuntimeException('Exactly one of --dry-run, --write, or --rollback is required.');
        }
        if ($write || $rollback) {
            foreach (self::WRITE_FLAGS as $flag) {
                if (! (bool) $this->option($flag)) {
                    throw new RuntimeException('--'.$flag.' is required with --write/--rollback.');
                }
            }
        }
        if ($rollback && ! (bool) $this->option('rollback-on-readback-failure-authorized')) {
            throw new RuntimeException('--rollback-on-readback-failure-authorized is required with --rollback.');
        }
        $path = trim((string) $this->option('package'));
        $resolved = str_starts_with($path, '/') ? $path : base_path($path);
        if ($path === '' || ! File::isFile($resolved)) {
            throw new RuntimeException('A readable --package is required.');
        }
        $package = json_decode((string) File::get($resolved), true);
        if (! is_array($package)) {
            throw new RuntimeException('Package must be a JSON object.');
        }
        $options = [
            'expected_source_package_sha256' => trim((string) $this->option('source-package-sha256')),
            'expected_authorization_payload_sha256' => trim((string) $this->option('authorization-payload-sha256')),
            'expected_promotion_package_sha256' => trim((string) $this->option('promotion-package-sha256')),
            'expected_promotion_authorization_sha256' => trim((string) $this->option('promotion-authorization-sha256')),
            'expected_import_scope_mode' => trim((string) $this->option('import-scope-mode')),
            'expected_record_count' => trim((string) $this->option('record-count')),
        ];
        foreach (['expected_source_package_sha256', 'expected_authorization_payload_sha256', 'expected_import_scope_mode', 'expected_record_count'] as $key) {
            if ($options[$key] === '') {
                throw new RuntimeException('All exact source hash, scope, and record-count options are required.');
            }
        }
        if (($write || $rollback) && ($options['expected_promotion_package_sha256'] === '' || $options['expected_promotion_authorization_sha256'] === '')) {
            throw new RuntimeException('Exact promotion hashes are required with --write/--rollback.');
        }

        $summary = match (true) {
            $write => $service->promote($package, $options),
            $rollback => $service->rollback($package, $options),
            default => $service->plan($package, $options),
        };

        return array_merge($summary, ['command' => 'personality:mbti-comp-runtime46-intp-promote', 'package_path' => $resolved]);
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
