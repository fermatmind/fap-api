<?php

declare(strict_types=1);

namespace App\Services\Career;

use Illuminate\Support\Facades\File;

/** Only this command's completed, dated backup directories are eligible for rotation. */
final class CareerCacheBackupRetention
{
    public const MAX_BYTES = 10737418240;

    public function rotateAndMeasure(string $root, int $now, bool $apply = true): int
    {
        File::ensureDirectoryExists($root, 0700);
        $total = 0;
        foreach (new \DirectoryIterator($root) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if ($entry->isLink() || ! $entry->isDir() || ! preg_match('/^\d{8}-\d{6}-[a-f0-9]{8}$/D', $entry->getFilename())) {
                throw new \RuntimeException('Unexpected Career backup entry; rotation stopped.');
            }
            $path = $entry->getPathname();
            $files = [];
            foreach (new \DirectoryIterator($path) as $file) {
                if ($file->isDot()) {
                    continue;
                }
                if ($file->isLink() || ! $file->isFile() || ! in_array($file->getFilename(), ['plan.jsonl', 'removed.jsonl', 'complete'], true)) {
                    throw new \RuntimeException('Unexpected Career backup file; rotation stopped.');
                }
                $files[] = [$file->getPathname(), $file->getSize()];
            }
            if ($apply && is_file($path.'/complete') && filemtime($path.'/complete') < $now - 7 * 86400) {
                foreach ($files as [$file]) {
                    if (! unlink($file)) {
                        throw new \RuntimeException('Career backup rotation failed.');
                    }
                }
                if (! rmdir($path)) {
                    throw new \RuntimeException('Career backup directory rotation failed.');
                }
            } else {
                $total += array_sum(array_column($files, 1));
            }
        }

        return $total;
    }

    public function assertHeadroom(int $existingBytes, int $additionalBytes): void
    {
        if ($existingBytes < 0 || $additionalBytes < 0 || $existingBytes + $additionalBytes > self::MAX_BYTES) {
            throw new \RuntimeException('Career backup capacity exhausted; retention stopped.');
        }
    }
}
