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
    #[Test]
    public function app_and_routes_do_not_use_env_or_getenv(): void
    {
        $roots = [base_path('app'), base_path('routes')];
        $offenders = [];

        foreach ($roots as $root) {
            foreach ($this->phpFiles($root) as $filePath) {
                $source = (string) file_get_contents($filePath);
                if (str_contains($source, 'env(') || str_contains($source, 'getenv(')) {
                    $offenders[] = ltrim(str_replace(base_path() . DIRECTORY_SEPARATOR, '', $filePath), DIRECTORY_SEPARATOR);
                }
            }
        }

        if ($offenders !== []) {
            sort($offenders);
            self::fail("env/getenv usage is forbidden outside config/bootstrap:\n" . implode("\n", $offenders));
        }

        self::assertTrue(true);
    }

    /**
     * @return array<int, string>
     */
    private function phpFiles(string $root): array
    {
        $files = [];
        if (!is_dir($root)) {
            return $files;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }
}
