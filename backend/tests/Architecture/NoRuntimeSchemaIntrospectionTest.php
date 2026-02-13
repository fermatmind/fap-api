<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

final class NoRuntimeSchemaIntrospectionTest extends TestCase
{
    #[Test]
    public function app_runtime_code_does_not_use_schema_has_table_or_column(): void
    {
        $offenders = [];

        foreach ($this->appPhpFiles() as $filePath) {
            $relative = ltrim(str_replace(base_path() . DIRECTORY_SEPARATOR, '', $filePath), DIRECTORY_SEPARATOR);
            if (str_starts_with($relative, 'app/Console/Commands/')) {
                continue;
            }
            if (str_starts_with($relative, 'app/Services/SelfCheck/')) {
                continue;
            }

            $source = (string) file_get_contents($filePath);
            if (str_contains($source, 'Schema::hasTable') || str_contains($source, 'Schema::hasColumn')) {
                $offenders[] = $relative;
            }
        }

        if ($offenders !== []) {
            sort($offenders);
            self::fail("Runtime schema introspection is forbidden:\n" . implode("\n", $offenders));
        }

        self::assertTrue(true);
    }

    /**
     * @return array<int, string>
     */
    private function appPhpFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));

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
