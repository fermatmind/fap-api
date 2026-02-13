<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

final class MiddlewareNoThrowableReturnTrueTest extends TestCase
{
    public function test_middleware_does_not_return_true_from_throwable_catch(): void
    {
        $root = app_path('Http/Middleware');
        $offenders = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $source = file_get_contents($path);
            if (!is_string($source)) {
                continue;
            }

            if (preg_match('/catch\\s*\\(\\s*\\\\?Throwable(?:\\s+\\$[A-Za-z_][A-Za-z0-9_]*)?\\s*\\)\\s*\\{[^{}]*return\\s+true\\s*;/s', $source) === 1) {
                $offenders[] = ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR);
            }
        }

        sort($offenders);

        $this->assertSame([], $offenders, 'Throwable catch blocks must not return true in middleware.');
    }
}

