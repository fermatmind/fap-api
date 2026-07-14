<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\MbtiFullCmsPromotionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityMbtiFullCmsPromote extends Command
{
    private const WRITE_SAFETY_FLAGS = [
        'public-content-promotion-authorized',
        'no-index',
        'no-sitemap',
        'no-llms',
        'no-search-release',
    ];

    protected $signature = 'personality:mbti-full-cms-promote
        {--package= : Path to the exact MBTI-CMS-39 approval package}
        {--source-package-sha256= : Exact source package sha256}
        {--promotion-package-sha256= : Exact dry-run promotion package sha256; required with --write}
        {--authorization-payload-sha256= : Exact dry-run authorization payload sha256; required with --write}
        {--import-scope-mode= : Exact promotion scope mode}
        {--record-count= : Exact record count}
        {--dry-run : Validate and generate exact promotion authorization without writes}
        {--write : Promote the exact 43-record public content batch}
        {--public-content-promotion-authorized : Required with --write}
        {--no-index : Required with --write; confirms indexability stays unchanged}
        {--no-sitemap : Required with --write; confirms sitemap stays unchanged}
        {--no-llms : Required with --write; confirms llms stays unchanged}
        {--no-search-release : Required with --write; confirms no search release occurs}
        {--json : Emit full JSON}
        {--output= : Optional exact evidence JSON path}';

    protected $description = 'Fail-closed promotion gate for the exact 43 CMS-40 draft repairs; public content only, no discoverability release.';

    public function handle(MbtiFullCmsPromotionService $service): int
    {
        try {
            $summary = $this->buildSummary($service);
        } catch (RuntimeException $exception) {
            $summary = $this->failureSummary('runtime_error', $exception->getMessage());
        } catch (Throwable $exception) {
            $summary = $this->failureSummary('unexpected_error', $exception->getMessage());
        }

        $this->writeOutput($summary);
        $this->emit($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string,mixed> */
    private function buildSummary(MbtiFullCmsPromotionService $service): array
    {
        $write = (bool) $this->option('write');
        $dryRun = (bool) $this->option('dry-run');
        if ($write === $dryRun) {
            throw new RuntimeException('Exactly one of --dry-run or --write is required.');
        }
        if ($write) {
            $this->assertWriteGuards();
        }

        $packagePath = $this->requiredFileOption('package');
        $options = [
            'expected_source_package_sha256' => $this->requiredStringOption('source-package-sha256'),
            'expected_import_scope_mode' => $this->requiredStringOption('import-scope-mode'),
            'expected_record_count' => (int) $this->requiredStringOption('record-count'),
            'expected_promotion_package_sha256' => trim((string) $this->option('promotion-package-sha256')),
            'expected_authorization_payload_sha256' => trim((string) $this->option('authorization-payload-sha256')),
        ];
        if ($write && ($options['expected_promotion_package_sha256'] === '' || $options['expected_authorization_payload_sha256'] === '')) {
            throw new RuntimeException('--promotion-package-sha256 and --authorization-payload-sha256 are required with --write.');
        }

        $package = json_decode((string) File::get($packagePath), true);
        if (! is_array($package)) {
            throw new RuntimeException('Package must be a JSON object.');
        }

        return array_merge(
            $write ? $service->promote($package, $options) : $service->plan($package, $options),
            ['command' => 'personality:mbti-full-cms-promote', 'package_path' => $packagePath]
        );
    }

    private function assertWriteGuards(): void
    {
        foreach (self::WRITE_SAFETY_FLAGS as $flag) {
            if (! (bool) $this->option($flag)) {
                throw new RuntimeException('--'.$flag.' is required with --write.');
            }
        }
    }

    private function requiredStringOption(string $name): string
    {
        $value = trim((string) $this->option($name));
        if ($value === '') {
            throw new RuntimeException('--'.$name.' is required.');
        }

        return $value;
    }

    private function requiredFileOption(string $name): string
    {
        $path = $this->requiredStringOption($name);
        $resolved = str_starts_with($path, '/') ? $path : base_path($path);
        if (! File::isFile($resolved)) {
            throw new RuntimeException('File not found: '.$resolved);
        }

        return $resolved;
    }

    /** @param array<string,mixed> $summary */
    private function writeOutput(array $summary): void
    {
        $output = trim((string) $this->option('output'));
        if ($output === '') {
            return;
        }
        $resolved = str_starts_with($output, '/') ? $output : base_path($output);
        File::ensureDirectoryExists(dirname($resolved));
        File::put($resolved, $this->encode($summary).PHP_EOL);
    }

    /** @param array<string,mixed> $summary */
    private function emit(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($summary));

            return;
        }
        $this->line('ok='.(($summary['ok'] ?? false) ? '1' : '0'));
        $this->line('status='.(string) ($summary['status'] ?? 'fail'));
        $this->line('promotion_package_sha256='.(string) ($summary['promotion_package_sha256'] ?? ''));
        $this->line('authorization_payload_sha256='.(string) ($summary['authorization_payload_sha256'] ?? ''));
        $this->line('row_count='.(string) ($summary['row_count'] ?? 0));
    }

    /** @param array<string,mixed> $value */
    private function encode(array $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** @return array<string,mixed> */
    private function failureSummary(string $code, string $message): array
    {
        return [
            'artifact' => 'MBTI-CMS-40-PUBLIC-CONTENT-PROMOTION-V1',
            'ok' => false,
            'status' => 'fail',
            'write' => (bool) $this->option('write'),
            'writes_committed' => false,
            'indexability_mutated' => false,
            'sitemap_mutated' => false,
            'llms_mutated' => false,
            'search_release_mutated' => false,
            'errors' => [['field' => 'command', 'code' => $code, 'message' => $message]],
        ];
    }
}
