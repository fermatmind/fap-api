<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

final class NoEnvUsageOutsideConfigTest extends TestCase
{
    /**
     * Existing compatibility paths that predate this forward-only gate.
     *
     * @var list<string>
     */
    private const BASELINE_ALLOWED_FILES = [
        'app/Console/Commands/BigFiveExportProductionEquivalentCandidatePayloads.php',
        'app/Console/Commands/BigFiveImportInactiveCandidateRelease.php',
        'app/Console/Commands/EnneagramActivateInactiveCandidateRelease.php',
        'app/Console/Commands/EnneagramActivateRegistryRelease.php',
        'app/Console/Commands/EnneagramExportProductionEquivalentCandidatePayloads.php',
        'app/Console/Commands/EnneagramImportInactiveCandidateRelease.php',
        'app/Console/Commands/EnneagramRollbackInactiveCandidateRelease.php',
        'app/Console/Commands/EnneagramRollbackRegistryRelease.php',
        'app/Console/Commands/ReleaseVerifyPublicContent.php',
        'app/Http/Controllers/API/V0_3/AuthPhoneController.php',
    ];

    #[Test]
    public function app_and_routes_do_not_use_env_or_getenv(): void
    {
        $roots = [base_path('app'), base_path('routes')];
        $offenders = [];

        foreach ($roots as $root) {
            foreach ($this->phpFiles($root) as $filePath) {
                $relative = ltrim(str_replace(base_path().DIRECTORY_SEPARATOR, '', $filePath), DIRECTORY_SEPARATOR);
                if (in_array($relative, self::BASELINE_ALLOWED_FILES, true)) {
                    continue;
                }

                $source = (string) file_get_contents($filePath);
                if (str_contains($source, 'env(') || str_contains($source, 'getenv(')) {
                    $offenders[] = $relative;
                }
            }
        }

        if ($offenders !== []) {
            sort($offenders);
            self::fail("env/getenv usage is forbidden outside config/bootstrap:\n".implode("\n", $offenders));
        }

        self::assertTrue(true);
    }

    /**
     * @return array<int, string>
     */
    private function phpFiles(string $root): array
    {
        $files = [];
        if (! is_dir($root)) {
            return $files;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }
}
