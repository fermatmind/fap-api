<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ControlledReceiptWriter;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

final class ControlledReceiptWriterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/controlled-receipt-writer-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_atomically_writes_the_exact_bytes_and_returns_a_stable_sha256(): void
    {
        $bytes = "{\"status\":\"PASS\"}\n";

        $firstSha256 = (new ControlledReceiptWriter)->write($this->directory, 'receipt.json', $bytes);
        $secondSha256 = (new ControlledReceiptWriter)->write($this->directory, 'receipt.json', $bytes);

        self::assertSame(hash('sha256', $bytes), $firstSha256);
        self::assertSame($firstSha256, $secondSha256);
        self::assertSame($bytes, File::get($this->directory.'/receipt.json'));
        self::assertSame([], glob($this->directory.'/receipt.json.tmp.*') ?: []);
    }

    public function test_it_fails_closed_when_the_receipt_cannot_be_written(): void
    {
        $writer = new ControlledReceiptWriter(
            static fn (string $path, string $bytes): int|false => false,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('receipt_write_failed');

        $writer->write($this->directory, 'receipt.json', 'receipt');
    }

    public function test_it_fails_closed_when_the_receipt_write_is_short(): void
    {
        $writer = new ControlledReceiptWriter(
            static function (string $path, string $bytes): int|false {
                $written = file_put_contents($path, $bytes);

                return is_int($written) ? max(0, $written - 1) : false;
            },
        );

        try {
            $writer->write($this->directory, 'receipt.json', 'receipt');
            self::fail('Expected the short receipt write to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('receipt_write_failed', $exception->getMessage());
        }

        self::assertFileDoesNotExist($this->directory.'/receipt.json');
        self::assertSame([], glob($this->directory.'/receipt.json.tmp.*') ?: []);
    }

    public function test_it_fails_closed_when_the_atomic_replace_fails(): void
    {
        $writer = new ControlledReceiptWriter(
            static fn (string $path, string $bytes): int|false => file_put_contents($path, $bytes),
            static fn (string $from, string $to): bool => false,
        );

        try {
            $writer->write($this->directory, 'receipt.json', 'receipt');
            self::fail('Expected the atomic replacement to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('receipt_write_failed', $exception->getMessage());
        }

        self::assertFileDoesNotExist($this->directory.'/receipt.json');
        self::assertSame([], glob($this->directory.'/receipt.json.tmp.*') ?: []);
    }

    public function test_it_fails_closed_when_final_readback_or_sha256_does_not_match(): void
    {
        $writer = new ControlledReceiptWriter(
            static fn (string $path, string $bytes): int|false => file_put_contents($path, $bytes),
            static fn (string $from, string $to): bool => rename($from, $to),
            static fn (string $path): string|false => 'tampered',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('receipt_integrity_failed');

        $writer->write($this->directory, 'receipt.json', 'receipt');
    }
}
