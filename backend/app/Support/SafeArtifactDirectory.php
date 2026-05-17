<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\File;
use RuntimeException;

final class SafeArtifactDirectory
{
    public static function createTemporaryDirectory(string $rootDir, string $finalDir): string
    {
        self::assertSafeRoot($rootDir);
        self::assertFinalDoesNotExist($finalDir);

        $baseName = basename($finalDir);
        for ($attempt = 0; $attempt < 16; $attempt++) {
            $tmpDir = $rootDir.DIRECTORY_SEPARATOR.'.'.$baseName.'.tmp.'.bin2hex(random_bytes(8));

            if (file_exists($tmpDir) || is_link($tmpDir)) {
                continue;
            }

            if (@mkdir($tmpDir, 0700)) {
                return $tmpDir;
            }
        }

        throw new RuntimeException('failed to create safe temporary artifact directory: '.$finalDir);
    }

    public static function finalize(string $tmpDir, string $finalDir): void
    {
        if (is_link($tmpDir) || ! is_dir($tmpDir)) {
            throw new RuntimeException('temporary artifact directory is not safe: '.$tmpDir);
        }

        self::assertFinalDoesNotExist($finalDir);

        if (! @rename($tmpDir, $finalDir)) {
            throw new RuntimeException('failed to finalize artifact output dir: '.$finalDir);
        }
    }

    private static function assertSafeRoot(string $rootDir): void
    {
        if (is_link($rootDir)) {
            throw new RuntimeException('artifact root directory must not be a symlink: '.$rootDir);
        }

        if (! is_dir($rootDir)) {
            File::ensureDirectoryExists($rootDir, 0750);
        }

        if (is_link($rootDir) || ! is_dir($rootDir)) {
            throw new RuntimeException('artifact root directory is not safe: '.$rootDir);
        }
    }

    private static function assertFinalDoesNotExist(string $finalDir): void
    {
        if (file_exists($finalDir) || is_link($finalDir)) {
            throw new RuntimeException('artifact output dir already exists or is a symlink: '.$finalDir);
        }
    }
}
