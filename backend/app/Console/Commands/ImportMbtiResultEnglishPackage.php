<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ContentImport\MbtiResultEnglishPackageImporter;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class ImportMbtiResultEnglishPackage extends Command
{
    protected $signature = 'content:import-mbti-result-english-package
        {--package= : Exact package directory; defaults to the repository-frozen W1 package}
        {--package-sha= : Required exact frozen package SHA-256}
        {--dry-run : Explicitly select the default no-write mode}
        {--write : Unsupported in this PR; fails closed}
        {--json : Emit the complete redacted plan}';

    protected $description = 'Validate and plan the exact W1 MBTI English result package without writes or private-result access.';

    public function handle(MbtiResultEnglishPackageImporter $importer): int
    {
        try {
            if ((bool) $this->option('write')) {
                throw new RuntimeException('write_mode_not_supported: --write is deferred to the separately controlled inactive-content import PR.');
            }

            $packageSha = strtolower(trim((string) $this->option('package-sha')));
            if ($packageSha === '') {
                throw new RuntimeException('package_sha_required: --package-sha is required.');
            }

            $packageDirectory = trim((string) $this->option('package'));
            if ($packageDirectory === '') {
                $packageDirectory = MbtiResultEnglishPackageImporter::defaultPackageDirectory();
            } elseif (! str_starts_with($packageDirectory, '/')) {
                $packageDirectory = base_path($packageDirectory);
            }

            $summary = $importer->plan($packageDirectory, $packageSha);
        } catch (RuntimeException $exception) {
            $summary = $this->failureSummary($exception->getMessage());
        } catch (Throwable) {
            $summary = $this->failureSummary('unexpected_error: Exact-package validation failed closed.');
        }

        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $summary
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

        foreach (['ok', 'status', 'mode', 'dry_run_only', 'write_supported_in_this_pr', 'writes_committed', 'private_payload_read_attempted', 'row_count'] as $key) {
            $value = $summary[$key] ?? null;
            $this->line($key.'='.(is_bool($value) ? ($value ? '1' : '0') : (string) $value));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function failureSummary(string $message): array
    {
        [$code, $safeMessage] = array_pad(explode(': ', $message, 2), 2, 'Exact-package validation failed closed.');

        return [
            'artifact' => 'EN-PARITY-W1-MBTI-RESULT-IMPORTER-DRY-RUN-RECEIPT',
            'schema_version' => 'fermatmind.en_parity.result_import_dry_run_receipt.v1',
            'status' => 'fail',
            'ok' => false,
            'mode' => 'dry_run',
            'dry_run_only' => true,
            'write_supported_in_this_pr' => false,
            'writes_committed' => false,
            'database_write_attempted' => false,
            'cms_write_attempted' => false,
            'private_payload_read_attempted' => false,
            'attempt_or_report_accessed' => false,
            'activation_attempted' => false,
            'publish_attempted' => false,
            'indexability_attempted' => false,
            'search_submission_attempted' => false,
            'row_count' => 0,
            'rows' => [],
            'errors' => [[
                'code' => preg_match('/\A[a-z0-9_]+\z/', $code) === 1 ? $code : 'runtime_error',
                'message' => $safeMessage,
            ]],
            'warnings' => [],
        ];
    }
}
