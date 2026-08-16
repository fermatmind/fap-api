<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

final class CareerCurrentAuthorityManifestRefresher
{
    public function __construct(
        private readonly CareerCurrentAuthorityPackage $package,
    ) {}

    /** @return array<string,mixed> */
    public function check(string $backendRoot): array
    {
        return $this->evaluate($backendRoot, false);
    }

    /** @return array<string,mixed> */
    public function write(string $backendRoot): array
    {
        return $this->evaluate($backendRoot, true);
    }

    /** @return array<string,mixed> */
    private function evaluate(string $backendRoot, bool $write): array
    {
        $manifestPath = rtrim($backendRoot, '/').'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH.'/manifest.json';
        if (! is_file($manifestPath) || is_link($manifestPath)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_PACKAGE_FILE_MISSING');
        }

        $built = $this->package->expectedManifest($backendRoot);
        $expectedBytes = CareerCurrentAuthorityPackage::encodePrettyCanonical($built['manifest']);
        $currentBytes = file_get_contents($manifestPath);
        if (! is_string($currentBytes)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_MANIFEST_UNREADABLE');
        }
        $stale = ! hash_equals(hash('sha256', $expectedBytes), hash('sha256', $currentBytes));
        $changed = false;
        if ($write && $stale) {
            $this->replaceAtomically($manifestPath, $expectedBytes);
            $changed = true;
        }

        return [
            'status' => $stale && ! $write
                ? 'STALE_CAREER_CURRENT_MANIFEST'
                : ($changed ? 'UPDATED_CAREER_CURRENT_MANIFEST' : 'PASS_CAREER_CURRENT_MANIFEST'),
            'stale' => $stale && ! $write,
            'changed' => $changed,
            'assets_sha256' => $built['summary']['assets_sha256'],
            'manifest_sha256' => hash('sha256', $expectedBytes),
            'career_count' => $built['summary']['career_count'],
            'locale_page_count' => $built['summary']['locale_page_count'],
            'components_per_page' => $built['summary']['components_per_page'],
            'database_writes' => 0,
            'cache_writes' => 0,
            'pointer_writes' => 0,
            'discoverability_writes' => 0,
            'search_submissions' => 0,
        ];
    }

    private function replaceAtomically(string $manifestPath, string $bytes): void
    {
        $directory = dirname($manifestPath);
        $temporaryPath = tempnam($directory, '.career-current-manifest-');
        if (! is_string($temporaryPath)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_MANIFEST_WRITE_FAILED');
        }

        try {
            $mode = fileperms($manifestPath);
            if (file_put_contents($temporaryPath, $bytes, LOCK_EX) !== strlen($bytes)
                || ($mode !== false && ! chmod($temporaryPath, $mode & 0777))
                || ! rename($temporaryPath, $manifestPath)) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_MANIFEST_WRITE_FAILED');
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }
}
