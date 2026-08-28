<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerCurrentAuthorityCompatibilityReader;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use PHPUnit\Framework\TestCase;

final class CareerCurrentAuthorityCompatibilityReaderTest extends TestCase
{
    public function test_it_batches_101_careers_as_50_50_1(): void
    {
        $reader = new CareerCurrentAuthorityCompatibilityReader(new CareerCurrentAuthorityPackage);
        $slugs = array_map(static fn (int $index): string => sprintf('career-%03d', $index), range(1, 101));
        $batches = iterator_to_array($reader->batches($slugs), false);

        self::assertSame([50, 50, 1], array_map('count', $batches));
        self::assertSame($slugs, array_merge(...$batches));
        self::assertLessThanOrEqual(
            CareerCurrentAuthorityCompatibilityReader::BATCH_SIZE,
            max(array_map('count', $batches)),
        );
    }

    public function test_runtime_row_reads_reject_more_than_50_slugs_before_querying(): void
    {
        $reader = new CareerCurrentAuthorityCompatibilityReader(new CareerCurrentAuthorityPackage);
        $slugs = array_map(static fn (int $index): string => sprintf('career-%03d', $index), range(1, 51));

        $this->expectException(CareerCurrentAuthorityPackageFailure::class);
        $this->expectExceptionMessage('CURRENT_COMPATIBILITY_BATCH_INVALID');

        $reader->rowsForSlugs(['entries' => []], $slugs);
    }
}
