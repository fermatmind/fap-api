<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class ControlledReceiptWriter
{
    /**
     * @param  null|callable(string, string): int|false  $write
     * @param  null|callable(string, string): bool  $move
     * @param  null|callable(string): string|false  $read
     */
    public function __construct(
        private readonly mixed $write = null,
        private readonly mixed $move = null,
        private readonly mixed $read = null,
    ) {}

    public function write(string $directory, string $filename, string $bytes): string
    {
        if ($filename === '' || basename($filename) !== $filename || str_contains($filename, '..')) {
            throw new RuntimeException('receipt_write_failed');
        }

        $destination = rtrim($directory, '/').'/'.$filename;
        $temporary = $destination.'.tmp.'.bin2hex(random_bytes(12));
        $expectedLength = strlen($bytes);
        $expectedSha256 = hash('sha256', $bytes);
        $write = $this->write ?? static fn (string $path, string $contents): int|false => file_put_contents($path, $contents, LOCK_EX);
        $move = $this->move ?? static fn (string $from, string $to): bool => rename($from, $to);
        $read = $this->read ?? static fn (string $path): string|false => file_get_contents($path);

        try {
            if ($write($temporary, $bytes) !== $expectedLength || ! $move($temporary, $destination)) {
                throw new RuntimeException('receipt_write_failed');
            }
            $persisted = $read($destination);
            if (! is_string($persisted)
                || ! hash_equals($bytes, $persisted)
                || ! hash_equals($expectedSha256, hash('sha256', $persisted))) {
                throw new RuntimeException('receipt_integrity_failed');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return $expectedSha256;
    }
}
