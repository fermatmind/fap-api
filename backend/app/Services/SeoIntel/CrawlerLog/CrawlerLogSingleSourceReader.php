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
     * @return array{basename: string, path_hash: string}
     */
    public function descriptor(string $sourcePath): array
    {
        return [
            'basename' => basename($sourcePath),
            'path_hash' => hash('sha256', $sourcePath),
        ];
    }
}
