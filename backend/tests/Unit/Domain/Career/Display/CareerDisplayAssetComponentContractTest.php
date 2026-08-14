<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerDisplayAssetComponentContract;
use PHPUnit\Framework\TestCase;

final class CareerDisplayAssetComponentContractTest extends TestCase
{
    public function test_it_accepts_only_the_exact_24_and_26_component_orders(): void
    {
        self::assertTrue(CareerDisplayAssetComponentContract::supports(
            CareerDisplayAssetComponentContract::LEGACY_V4_2_ORDER,
        ));
        self::assertTrue(CareerDisplayAssetComponentContract::supports(
            CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER,
        ));
        self::assertFalse(CareerDisplayAssetComponentContract::supports(
            array_slice(CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER, 0, 25),
        ));

        $unknown = CareerDisplayAssetComponentContract::LEGACY_V4_2_ORDER;
        $unknown[5] = 'unknown_component';
        self::assertFalse(CareerDisplayAssetComponentContract::supports($unknown));

        $duplicate = CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER;
        $duplicate[11] = $duplicate[10];
        self::assertFalse(CareerDisplayAssetComponentContract::supports($duplicate));

        $reordered = CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER;
        [$reordered[10], $reordered[11]] = [$reordered[11], $reordered[10]];
        self::assertFalse(CareerDisplayAssetComponentContract::supports($reordered));
    }
}
