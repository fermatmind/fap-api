<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ContentImport\RiasecEnglishPackageImporter;
use DomainException;
use Illuminate\Console\Command;
use Throwable;

final class ImportRiasecEnglishPackage extends Command
{
    protected $signature = 'content:import-riasec-english-package
        {--package= : Exact frozen W4 package directory}
        {--package-sha= : Required exact CONTROL-accepted package SHA-256}
        {--dry-run : Explicit no-write mode (also the default)}
        {--write : Rejected: this importer has no write mode}
        {--json : Emit the complete redacted plan}';

    protected $description = 'Fail-closed dry-run validation for the exact CONTROL-accepted W4 RIASEC English package.';

    public function handle(RiasecEnglishPackageImporter $importer): int
    {
        try {
            if ((bool) $this->option('write')) {
                throw new DomainException('write_not_authorized: This PR implements no CMS, database, runtime, or publication write path.');
            }
            $sha = trim((string) $this->option('package-sha'));
            if ($sha === '') {
                throw new DomainException('package_sha_required: --package-sha is required.');
            }
            $directory = trim((string) $this->option('package'));
            $plan = $importer->plan(
                $directory === '' ? RiasecEnglishPackageImporter::defaultPackageDirectory() : $directory,
                $sha,
            );
        } catch (DomainException $exception) {
            [$code, $message] = array_pad(explode(': ', $exception->getMessage(), 2), 2, 'Exact-package validation failed closed.');
            $plan = [
                'artifact' => 'EN-PARITY-W4-RIASEC-IMPORTER-DRY-RUN-RECEIPT',
                'schema_version' => 'fermatmind.en_parity.riasec_import_dry_run_receipt.v1',
                'status' => 'fail', 'ok' => false, 'mode' => 'dry_run', 'dry_run_only' => true,
                'write_supported_in_this_pr' => false, 'writes_committed' => false,
                'database_write_attempted' => false, 'cms_write_attempted' => false,
                'runtime_write_attempted' => false, 'activation_attempted' => false,
                'publish_attempted' => false, 'indexability_attempted' => false,
                'private_payload_read_attempted' => false, 'attempt_or_report_accessed' => false,
                'row_count' => 0, 'rows' => [], 'warnings' => [],
                'errors' => [['code' => preg_match('/\A[a-z0-9_]+\z/', $code) === 1 ? $code : 'runtime_error', 'message' => $message]],
            ];
        } catch (Throwable) {
            $plan = ['artifact' => 'EN-PARITY-W4-RIASEC-IMPORTER-DRY-RUN-RECEIPT', 'status' => 'fail', 'ok' => false, 'mode' => 'dry_run', 'dry_run_only' => true, 'writes_committed' => false, 'row_count' => 0, 'rows' => [], 'errors' => [['code' => 'unexpected_error', 'message' => 'Exact-package validation failed closed.']], 'warnings' => []];
        }
        $this->line((string) json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

        return ($plan['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
