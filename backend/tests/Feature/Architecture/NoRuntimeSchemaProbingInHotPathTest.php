<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

final class NoRuntimeSchemaProbingInHotPathTest extends TestCase
{
    public function test_hot_path_has_no_runtime_schema_probing(): void
    {
        $scanRoots = [
            app_path('Services/Commerce'),
            app_path('Services/Report'),
            app_path('Internal/Commerce'),
            app_path('Http/Controllers/API/V0_3'),
        ];

        $forbiddenTokens = [
            'Schema::hasTable',
            'Schema::hasColumn',
        ];

        $violations = [];

        foreach ($scanRoots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                    continue;
                }

                $path = (string) $fileInfo->getPathname();
                $lines = @file($path);
                if (!is_array($lines)) {
                    continue;
                }

                foreach ($lines as $index => $line) {
                    foreach ($forbiddenTokens as $token) {
                        if (str_contains($line, $token)) {
                            $violations[] = sprintf('%s:%d => %s', $path, $index + 1, $token);
                        }
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Hot path must not perform runtime schema probing.\n".implode("\n", $violations)
        );
    }
}
