<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\Career1046DisplayAssetReplacement;
use App\Domain\Career\Display\Career1046DisplayAssetReplacementFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class Career1046DisplayAssetReplacementStateTest extends TestCase
{
    public function test_only_the_exact_initial_and_exact_applied_states_are_accepted(): void
    {
        $method = $this->classifier();
        $service = (new ReflectionClass(Career1046DisplayAssetReplacement::class))->newInstanceWithoutConstructor();
        $missing = array_map(static fn (int $index): string => 'missing-'.$index, range(1, 12));

        self::assertSame('initial', $method->invoke($service, 1034, $missing, $missing, 1034, 0));
        self::assertSame('applied', $method->invoke($service, 1046, [], $missing, 0, 1046));
    }

    /** @param list<string> $missing @param list<string> $authorized */
    #[DataProvider('invalidStateProvider')]
    public function test_mixed_missing_partial_and_wrong_order_states_fail_before_writes(
        int $assets,
        array $missing,
        array $authorized,
        int $legacy,
        int $current,
    ): void {
        $service = (new ReflectionClass(Career1046DisplayAssetReplacement::class))->newInstanceWithoutConstructor();
        $this->expectException(Career1046DisplayAssetReplacementFailure::class);
        $this->expectExceptionMessage('DISPLAY_ASSET_TARGET_STATE_INVALID');

        $this->classifier()->invoke($service, $assets, $missing, $authorized, $legacy, $current);
    }

    /** @return iterable<string, array{int, list<string>, list<string>, int, int}> */
    public static function invalidStateProvider(): iterable
    {
        $twelve = array_map(static fn (int $index): string => 'missing-'.$index, range(1, 12));

        yield 'one unauthorized missing slug' => [1034, [...array_slice($twelve, 0, 11), 'wrong'], $twelve, 1034, 0];
        yield 'only eleven missing' => [1035, array_slice($twelve, 0, 11), $twelve, 1035, 0];
        yield 'mixed 24 and 26 component rows' => [1034, $twelve, $twelve, 1033, 1];
        yield '25 component row is neither supported order' => [1034, $twelve, $twelve, 1033, 0];
        yield 'partial applied state' => [1046, [], $twelve, 0, 1045];
        yield 'duplicate target inflates row count' => [1047, [], $twelve, 0, 1047];
    }

    private function classifier(): ReflectionMethod
    {
        return new ReflectionMethod(Career1046DisplayAssetReplacement::class, 'classifyAuthorityState');
    }
}
