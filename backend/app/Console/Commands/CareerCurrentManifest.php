<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Display\CareerCurrentAuthorityManifestRefresher;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use Illuminate\Console\Command;
use Throwable;

final class CareerCurrentManifest extends Command
{
    protected $signature = 'career:current-manifest
        {--write : Atomically refresh the repository manifest; the default mode is read-only validation}';

    protected $description = 'Validate or deterministically refresh the Career Current authority manifest without runtime writes';

    public function handle(CareerCurrentAuthorityManifestRefresher $refresher): int
    {
        ini_set('memory_limit', '1024M');

        try {
            $backendRoot = base_path();
            $write = (bool) $this->option('write');
            if ($write && (! app()->environment(['local', 'testing']) || ! $this->isGitWorktree(dirname($backendRoot)))) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_MANIFEST_WRITE_NOT_ALLOWED');
            }

            $result = $write ? $refresher->write($backendRoot) : $refresher->check($backendRoot);
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
