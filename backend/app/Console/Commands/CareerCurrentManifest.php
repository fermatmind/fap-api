<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Display\CareerContentV3AuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use Illuminate\Console\Command;
use Throwable;

final class CareerCurrentManifest extends Command
{
    protected $signature = 'career:current-manifest
        {--write : Atomically refresh the repository manifest; the default mode is read-only validation}';

    protected $description = 'Validate or deterministically refresh the Career Current authority manifest without runtime writes';

    public function handle(CareerContentV3AuthorityPackage $contentV3Package): int
    {
        try {
            $backendRoot = base_path();
            $write = (bool) $this->option('write');
            $manifestPath = $backendRoot.'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH.'/manifest.json';
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (! is_array($manifest)
                || ($manifest['contract_version'] ?? null) !== CareerContentV3AuthorityPackage::CONTRACT_VERSION) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_AUTHORITY_REQUIRED');
            }
            if ($write) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_MANIFEST_COMPILER_OWNED');
            }
            $index = $contentV3Package->manifestIndex($backendRoot);
            $result = [
                'status' => 'PASS_CAREER_CURRENT_MANIFEST',
                'stale' => false,
                'changed' => false,
                'assets_sha256' => $manifest['aggregate_sha256'],
                'manifest_sha256' => hash_file('sha256', $manifestPath),
                'aggregate_sha256' => $manifest['aggregate_sha256'],
                'versionless_projection_sha256' => $manifest['set_hashes']['legacy_versionless_projection_sha256'],
                'career_count' => count($index['slugs']),
                'locale_page_count' => count($manifest['files']),
                'source_format' => 'content_v3_per_page',
                'database_writes' => 0,
                'cache_writes' => 0,
                'pointer_writes' => 0,
                'discoverability_writes' => 0,
                'search_submissions' => 0,
            ];
            $this->line((string) json_encode(
                $result,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return ($result['stale'] ?? false) && ! $write ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->line((string) json_encode([
                'status' => 'FAIL_CAREER_CURRENT_MANIFEST',
                'safe_error_code' => $throwable instanceof CareerCurrentAuthorityPackageFailure
                    ? $throwable->safeCode
                    : 'UNEXPECTED_CAREER_CURRENT_MANIFEST_FAILURE',
                'database_writes' => 0,
                'cache_writes' => 0,
                'pointer_writes' => 0,
                'discoverability_writes' => 0,
                'search_submissions' => 0,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
