<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\MbtiContent15ProductionCmsImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityMbtiContent15ProductionImport extends Command
{
    private const WRITE_SAFETY_FLAGS = [
        'production-import-authorized',
        'no-index',
        'no-sitemap',
        'no-llms',
        'no-search-release',
    ];

    protected $signature = 'personality:mbti-content15-production-import
        {--package= : Path to the exact MBTI-CMS-22 final dry-run package}
        {--authorization-package= : Path to the exact MBTI-CMS-23 authorization package}
        {--source-package-sha256= : Exact operator-authorized MBTI-CMS-22 package sha256}
        {--authorization-payload-sha256= : Exact operator-authorized MBTI-CMS-23 authorization payload sha256}
        {--import-scope-mode= : Exact operator-authorized import scope mode}
        {--record-count= : Exact operator-authorized record count}
        {--dry-run : Verify the exact package and resolved authority targets without writes}
        {--write : Import the exact authorized CONTENT-15 package into CMS authority rows}
        {--production-import-authorized : Required with --write}
        {--no-index : Required with --write; confirms indexability stays held}
        {--no-sitemap : Required with --write; confirms sitemap eligibility stays held}
        {--no-llms : Required with --write; confirms llms eligibility stays held}
        {--no-search-release : Required with --write; confirms no search submission occurs}
        {--json : Emit the full JSON summary}
        {--output= : Optional path for the exact import evidence JSON}';

    protected $description = 'Import the exact authorized CONTENT-15 MBTI CMS package while holding all search/indexability gates closed.';

    public function handle(MbtiContent15ProductionCmsImportService $service): int
    {
        try {
            $summary = $this->buildSummary($service);
        } catch (RuntimeException $exception) {
            $summary = $this->failureSummary('runtime_error', $exception->getMessage());
        } catch (Throwable $exception) {
            $summary = $this->failureSummary('unexpected_error', $exception->getMessage());
        }

        $this->writeOutputFile($summary);
        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSummary(MbtiContent15ProductionCmsImportService $service): array
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
        $authorizationPackagePath = $this->requiredFileOption('authorization-package');
        $package = $this->decodeJson($packagePath, 'Package');
        $authorizationPackage = $this->decodeJson($authorizationPackagePath, 'Authorization package');
        $options = [
            'expected_source_package_sha256' => trim((string) $this->option('source-package-sha256')),
            'expected_authorization_payload_sha256' => trim((string) $this->option('authorization-payload-sha256')),
            'expected_import_scope_mode' => trim((string) $this->option('import-scope-mode')),
            'expected_record_count' => trim((string) $this->option('record-count')),
        ];
        foreach ($options as $key => $value) {
            if ($value === '') {
                throw new RuntimeException('--'.str_replace('expected_', '', str_replace('_', '-', $key)).' is required.');
            }
        }

        $summary = $write
            ? $service->import($package, $authorizationPackage, $options)
            : $service->plan($package, $authorizationPackage, $options);

        return array_merge($summary, [
            'command' => 'personality:mbti-content15-production-import',
            'package_path' => $packagePath,
            'authorization_package_path' => $authorizationPackagePath,
        ]);
    }

    private function assertWriteGuards(): void
    {
        foreach (self::WRITE_SAFETY_FLAGS as $flag) {
            if (! (bool) $this->option($flag)) {
                throw new RuntimeException('--'.$flag.' is required with --write.');
            }
        }
    }

    private function requiredFileOption(string $name): string
    {
        $path = trim((string) $this->option($name));
        if ($path === '') {
            throw new RuntimeException('--'.$name.' is required.');
        }

        $resolved = str_starts_with($path, '/') ? $path : base_path($path);
        if (! File::isFile($resolved)) {
            throw new RuntimeException('File not found: '.$resolved);
        }

        return $resolved;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(string $path, string $label): array
    {
        $decoded = json_decode((string) File::get($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException($label.' must be a JSON object.');
        }

        return $decoded;
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

        $resolved = str_starts_with($output, '/') ? $output : base_path($output);
        File::ensureDirectoryExists(dirname($resolved));
        File::put($resolved, (string) json_encode(
            $summary,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        ).PHP_EOL);
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
        $this->line('write='.(($summary['write'] ?? false) ? '1' : '0'));
        $this->line('writes_committed='.(($summary['writes_committed'] ?? false) ? '1' : '0'));
        $this->line('published_content_count='.(string) ($summary['published_content_count'] ?? 0));
        $this->line('errors_count='.(string) count((array) ($summary['errors'] ?? [])));
    }

    /**
     * @return array<string,mixed>
     */
    private function failureSummary(string $code, string $message): array
    {
        return [
            'artifact' => 'MBTI-CMS-27-CONTENT15-PRODUCTION-IMPORT',
            'ok' => false,
            'status' => 'fail',
            'write' => (bool) $this->option('write'),
            'dry_run' => (bool) $this->option('dry-run'),
            'writes_committed' => false,
            'cms_write_attempted' => false,
            'publish_attempted' => false,
            'index_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'search_release_attempted' => false,
            'errors' => [[
                'field' => 'command',
                'code' => $code,
                'message' => $message,
            ]],
            'warnings' => [],
        ];
    }
}
