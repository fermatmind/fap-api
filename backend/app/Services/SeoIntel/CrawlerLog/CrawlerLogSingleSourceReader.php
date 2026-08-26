<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\CrawlerLog;

use RuntimeException;
use SplFileObject;

final class CrawlerLogSingleSourceReader
{
    /**
     * @return list<string>
     */
    public function read(string $sourcePath, int $limit): array
    {
        $normalizedPath = trim($sourcePath);

        if ($normalizedPath === '' || ! str_starts_with($normalizedPath, '/')) {
            throw new RuntimeException('source_path_must_be_absolute');
        }

        if (! is_file($normalizedPath)) {
            throw new RuntimeException('source_path_not_found');
        }

        if (! is_readable($normalizedPath)) {
            throw new RuntimeException('source_path_not_readable');
        }

        $lines = [];
        $file = new SplFileObject($normalizedPath, 'r');

        while (! $file->eof() && count($lines) < $limit) {
            $line = trim((string) $file->fgets());

            if ($line === '') {
                continue;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Read only the newest bounded lines so a scheduled observation cannot
     * replay the oldest part of a long-lived access log as fresh evidence.
     *
     * @return list<string>
     */
    public function readTail(string $sourcePath, int $limit): array
    {
        $normalizedPath = $this->validatedPath($sourcePath);
        $limit = max(1, min($limit, CrawlerLogAggregateDryRun::MAX_LIMIT));
        $handle = fopen($normalizedPath, 'rb');

        if ($handle === false || fseek($handle, 0, SEEK_END) !== 0) {
            throw new RuntimeException('source_path_not_readable');
        }

        $position = ftell($handle);
        $buffer = '';
        $maximumBytes = 2 * 1024 * 1024;

        while (is_int($position) && $position > 0 && strlen($buffer) < $maximumBytes) {
            $chunkSize = min(8192, $position, $maximumBytes - strlen($buffer));
            $position -= $chunkSize;
            if (fseek($handle, $position) !== 0) {
                fclose($handle);
                throw new RuntimeException('source_tail_read_failed');
            }
            $chunk = fread($handle, $chunkSize);
            if (! is_string($chunk) || strlen($chunk) !== $chunkSize) {
                fclose($handle);
                throw new RuntimeException('source_tail_read_failed');
            }
            $buffer = $chunk.$buffer;
            if (substr_count($buffer, "\n") > $limit) {
                break;
            }
        }

        fclose($handle);
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/', $buffer) ?: []),
            static fn (string $line): bool => $line !== '',
        ));

        return array_slice($lines, -$limit);
    }

    /**
     * @return array{basename: string, path_hash: string}
     */
    public function descriptor(string $sourcePath): array
    {
        return [
            'basename' => basename($sourcePath),
            'path_hash' => hash('sha256', $sourcePath),
        ];
    }

    private function validatedPath(string $sourcePath): string
    {
        $normalizedPath = trim($sourcePath);

        if ($normalizedPath === '' || ! str_starts_with($normalizedPath, '/')) {
            throw new RuntimeException('source_path_must_be_absolute');
        }

        if (! is_file($normalizedPath)) {
            throw new RuntimeException('source_path_not_found');
        }

        if (! is_readable($normalizedPath)) {
            throw new RuntimeException('source_path_not_readable');
        }

        return $normalizedPath;
    }
}
