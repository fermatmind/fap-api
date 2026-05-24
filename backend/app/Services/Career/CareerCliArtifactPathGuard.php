<?php

declare(strict_types=1);

namespace App\Services\Career;

use Illuminate\Support\Facades\File;
use RuntimeException;

final class CareerCliArtifactPathGuard
{
    public static function outputPath(mixed $value, string $option = '--output'): ?string
    {
        $path = trim((string) ($value ?? ''));
        if ($path === '') {
            return null;
        }

        if (str_contains($path, "\0")) {
            throw new RuntimeException($option.' contains a null byte.');
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:\/\//', $path) === 1 || str_starts_with($path, 'php://')) {
            throw new RuntimeException($option.' must be a local filesystem path.');
        }

        $basename = basename($path);
        if ($basename === '' || $basename === '.' || $basename === '..') {
            throw new RuntimeException($option.' must include a file name.');
        }

        if (is_link($path)) {
            throw new RuntimeException($option.' must not point to a symlink.');
        }

        $parent = dirname($path);
        if ($parent === '' || $parent === '.') {
            $parent = getcwd() ?: '.';
        }

        if (is_link($parent)) {
            throw new RuntimeException($option.' parent directory must not be a symlink.');
        }

        $parentRealPath = realpath($parent);
        if (! is_string($parentRealPath) || ! is_dir($parentRealPath)) {
            throw new RuntimeException($option.' parent directory must exist.');
        }

        return $parentRealPath.DIRECTORY_SEPARATOR.$basename;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function writeJsonOutput(mixed $value, array $payload, string $option = '--output'): ?string
    {
        $path = self::outputPath($value, $option);
        if ($path === null) {
            return null;
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json)) {
            throw new RuntimeException('Unable to encode JSON output.');
        }

        File::put($path, $json.PHP_EOL, true);

        return $path;
    }

    public static function writeTextOutput(mixed $value, string $contents, string $option = '--output'): ?string
    {
        $path = self::outputPath($value, $option);
        if ($path === null) {
            return null;
        }

        File::put($path, $contents, true);

        return $path;
    }
}
