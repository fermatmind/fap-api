<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Display\CareerCurrentAuthorityManifestRefresher;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageLoader;
use App\Domain\Career\Display\CareerShardedCurrentAuthorityPackage;
use Illuminate\Console\Command;
use Throwable;

final class CareerCurrentManifest extends Command
{
    protected $signature = 'career:current-manifest
        {--write : Atomically refresh the repository manifest; the default mode is read-only validation}';

    protected $description = 'Validate or deterministically refresh the Career Current authority manifest without runtime writes';

    public function handle(
        CareerCurrentAuthorityManifestRefresher $refresher,
        CareerCurrentAuthorityPackageLoader $loader,
    ): int {
        ini_set('memory_limit', '1024M');

        try {
            $backendRoot = base_path();
            $write = (bool) $this->option('write');
            $manifestPath = $backendRoot.'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH.'/manifest.json';
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (is_array($manifest)
                && ($manifest['contract_version'] ?? null) === CareerShardedCurrentAuthorityPackage::CONTRACT_VERSION) {
                if ($write) {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_MANIFEST_COMPILER_OWNED');
                }
                $authority = $loader->load($backendRoot);
                $result = [
                    'status' => 'PASS_CAREER_CURRENT_MANIFEST',
                    'stale' => false,
                    'changed' => false,
                    'assets_sha256' => $authority['summary']['assets_sha256'],
                    'manifest_sha256' => $authority['summary']['manifest_sha256'],
                    'sharded_aggregate_sha256' => $authority['summary']['sharded_aggregate_sha256'],
                    'versionless_projection_sha256' => $authority['summary']['versionless_projection_sha256'],
                    'career_count' => $authority['summary']['career_count'],
                    'locale_page_count' => $authority['summary']['locale_page_count'],
                    'components_per_page' => $authority['summary']['components_per_page'],
                    'database_writes' => 0,
                    'cache_writes' => 0,
                    'pointer_writes' => 0,
                    'discoverability_writes' => 0,
                    'search_submissions' => 0,
                ];
            } else {
                if ($write && (! app()->environment(['local', 'testing']) || ! $this->isGitWorktree(dirname($backendRoot)))) {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_MANIFEST_WRITE_NOT_ALLOWED');
                }
                $result = $write ? $refresher->write($backendRoot) : $refresher->check($backendRoot);
            }
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

    private function isGitWorktree(string $repositoryRoot): bool
    {
        $gitEntry = rtrim($repositoryRoot, '/').'/.git';

        return is_file($gitEntry) || is_dir($gitEntry);
    }
}
