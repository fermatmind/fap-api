<?php

declare(strict_types=1);

namespace App\Services\Content\Drivers;

use App\Contracts\ContentSourceDriver;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class LocalDriver implements ContentSourceDriver
{
    private string $rootDir;

    public function __construct(string $rootDir)
    {
        $this->rootDir = rtrim($rootDir, '/');
    }

    public function get(string $key): string
    {
        $key = $this->normalizeKey($key);
        $path = $this->absPath($key);

        if (!is_file($path)) {
            throw new RuntimeException("Content key not found: {$key}");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Failed to read content key: {$key}");
        }

        return $contents;
    }

    public function exists(string $key): bool
    {
        $key = $this->normalizeKey($key);
        return is_file($this->absPath($key));
    }

    public function list(string $prefix): array
    {
        $prefix = $this->normalizeKey($prefix);
        $baseDir = $this->rootDir;
        $scanDir = $prefix === '' ? $baseDir : $baseDir . '/' . $prefix;

        if (!is_dir($scanDir)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($scanDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $path = $fileInfo->getPathname();
            $relative = ltrim(substr($path, strlen($baseDir)), DIRECTORY_SEPARATOR);
            $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        }

        return $files;
    }

    public function etag(string $key): ?string
    {
        $key = $this->normalizeKey($key);
        $path = $this->absPath($key);

        if (!is_file($path)) {
            return null;
        }

        $hash = sha1_file($path);
        return $hash === false ? null : $hash;
    }

    private function absPath(string $key): string
    {
        return $this->rootDir . '/' . $key;
    }

    private function normalizeKey(string $key): string
    {
        $key = ltrim($key, '/');

        if (str_contains($key, '..')) {
            throw new RuntimeException('Invalid content key (.. not allowed).');
        }

        return $key;
    }
}
