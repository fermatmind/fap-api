<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ContentImport\MbtiComparisonEnglishPackageImporter;
use DomainException;
use Illuminate\Console\Command;
use Throwable;

final class ImportMbtiComparisonEnglishPackage extends Command
{
    protected $signature = 'content:import-mbti-comparison-english-package
        {--package= : Exact package directory; defaults to the repository-frozen W1 package}
        {--package-sha= : Required exact frozen package SHA-256}
        {--dry-run : Explicitly select the default no-write mode}
        {--write : Import the exact package as seven English inactive draft rows}
        {--approval= : Exact CONTROL approval artifact; required for --write}
        {--approval-sha= : Required exact CONTROL approval SHA-256 for --write}
        {--json : Emit the complete redacted plan}';

    protected $description = 'Dry-run or CONTROL-authorized draft-import the exact W1 MBTI English comparison package.';

    public function handle(MbtiComparisonEnglishPackageImporter $importer): int
    {
        try {
            $write = (bool) $this->option('write');
            if ($write && (bool) $this->option('dry-run')) {
                throw new DomainException('mode_conflict: --dry-run and --write are mutually exclusive.');
            }

            $packageSha = strtolower(trim((string) $this->option('package-sha')));
            if ($packageSha === '') {
                throw new DomainException('package_sha_required: --package-sha is required.');
            }

            $packageDirectory = trim((string) $this->option('package'));
            if ($packageDirectory === '') {
                $packageDirectory = MbtiComparisonEnglishPackageImporter::defaultPackageDirectory();
            } elseif (! str_starts_with($packageDirectory, '/')) {
                $packageDirectory = base_path($packageDirectory);
            }

            if (! $write) {
                $summary = $importer->plan($packageDirectory, $packageSha);
            } else {
                $approvalSha = strtolower(trim((string) $this->option('approval-sha')));
                if ($approvalSha === '') {
                    throw new DomainException('approval_sha_required: --approval-sha is required for --write.');
                }
                $approvalPath = trim((string) $this->option('approval'));
                if ($approvalPath === '') {
                    $approvalPath = MbtiComparisonEnglishPackageImporter::defaultApprovalPath();
                } elseif (! str_starts_with($approvalPath, '/')) {
                    $approvalPath = base_path($approvalPath);
                }

                $summary = $importer->importDraft($packageDirectory, $packageSha, $approvalPath, $approvalSha);
            }
        } catch (DomainException $exception) {
            $summary = $this->failureSummary($exception->getMessage(), $importer->writeAttempted());
        } catch (Throwable) {
            $summary = $this->failureSummary(
                'unexpected_error: Exact-package validation failed closed.',
                $importer->writeAttempted(),
            );
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

        foreach (['ok', 'status', 'mode', 'dry_run_only', 'write_supported_in_this_pr', 'writes_committed', 'database_write_attempted', 'cms_write_attempted', 'row_count'] as $key) {
            $value = $summary[$key] ?? null;
            $this->line($key.'='.(is_bool($value) ? ($value ? '1' : '0') : (string) $value));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function failureSummary(string $message, bool $writeAttempted): array
    {
        [$code, $safeMessage] = array_pad(explode(': ', $message, 2), 2, 'Exact-package validation failed closed.');

        return [
            'artifact' => (bool) $this->option('write')
                ? 'EN-PARITY-W1-MBTI-COMPARISON-DRAFT-IMPORT-RECEIPT'
                : 'EN-PARITY-W1-MBTI-COMPARISON-IMPORTER-DRY-RUN-RECEIPT',
            'schema_version' => (bool) $this->option('write')
                ? 'fermatmind.en_parity.comparison_draft_import_receipt.v1'
                : 'fermatmind.en_parity.comparison_import_dry_run_receipt.v1',
            'status' => 'fail',
            'ok' => false,
            'mode' => (bool) $this->option('write') ? 'write_inactive_draft' : 'dry_run',
            'dry_run_only' => ! (bool) $this->option('write'),
            'write_supported_in_this_pr' => (bool) $this->option('write'),
            'writes_committed' => false,
            'database_write_attempted' => $writeAttempted,
            'cms_write_attempted' => $writeAttempted,
            'publish_attempted' => false,
            'activation_attempted' => false,
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
