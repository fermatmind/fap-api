<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Scale;

use App\Services\Scale\ScaleDiscoverabilityPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScaleDiscoverabilityPolicyTest extends TestCase
{
    #[Test]
    #[DataProvider('heldScaleProvider')]
    public function clinical_screening_hold_overrides_indexable_registry_state(string $code): void
    {
        $policy = new ScaleDiscoverabilityPolicy;
        $row = [
            'code' => $code,
            'is_public' => true,
            'is_active' => true,
            'is_indexable' => true,
            'view_policy_json' => ['indexable' => true, 'robots' => 'index,follow'],
        ];

        self::assertTrue($policy->isClinicalScreeningHold($row));
        self::assertFalse($policy->isIndexable($row));
        self::assertFalse($policy->isPubliclyDiscoverable($row));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function heldScaleProvider(): iterable
    {
        yield 'clinical combo' => ['CLINICAL_COMBO_68'];
        yield 'depression screening' => ['SDS_20'];
    }

    #[Test]
    #[DataProvider('pr24FixtureHoldProvider')]
    public function exact_pr24_fixture_hold_overrides_public_indexable_registry_state(string $code): void
    {
        $policy = new ScaleDiscoverabilityPolicy;
        $row = [
            'code' => $code,
            'is_public' => true,
            'is_active' => true,
            'is_indexable' => true,
            'view_policy_json' => ['indexable' => true, 'robots' => 'index,follow'],
        ];

        self::assertTrue($policy->isPr24FixtureHold($row));
        self::assertFalse($policy->isIndexable($row));
        self::assertFalse($policy->isPubliclyDiscoverable($row));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pr24FixtureHoldProvider(): iterable
    {
        yield 'first' => ['PR24_01'];
        yield 'last single digit' => ['PR24_09'];
        yield 'first double digit' => ['PR24_10'];
        yield 'penultimate' => ['PR24_49'];
        yield 'last' => ['PR24_50'];
        yield 'normalized lowercase' => ['pr24_25'];
    }

    #[Test]
    #[DataProvider('nearMissPr24CodeProvider')]
    public function nearby_codes_are_not_in_the_pr24_fixture_hold(string $code): void
    {
        $policy = new ScaleDiscoverabilityPolicy;
        $row = [
            'scale_code' => $code,
            'is_public' => true,
            'is_active' => true,
            'is_indexable' => true,
        ];

        self::assertFalse($policy->isPr24FixtureHold($row));
        self::assertTrue($policy->isIndexable($row));
        self::assertTrue($policy->isPubliclyDiscoverable($row));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nearMissPr24CodeProvider(): iterable
    {
        yield 'zero' => ['PR24_00'];
        yield 'above range' => ['PR24_51'];
        yield 'not zero padded' => ['PR24_1'];
        yield 'three digits' => ['PR24_050'];
        yield 'prefixed' => ['XPR24_01'];
    }

    #[Test]
    public function ordinary_scale_respects_registry_and_view_policy_gates(): void
    {
        $policy = new ScaleDiscoverabilityPolicy;

        self::assertTrue($policy->isPubliclyDiscoverable([
            'code' => 'MBTI',
            'is_public' => true,
            'is_active' => true,
            'is_indexable' => true,
        ]));
        self::assertFalse($policy->isPubliclyDiscoverable([
            'code' => 'MBTI',
            'is_public' => true,
            'is_active' => true,
            'is_indexable' => true,
            'view_policy_json' => ['visibility' => 'hidden'],
        ]));
        self::assertFalse($policy->isIndexable([
            'code' => 'MBTI',
            'is_indexable' => true,
            'view_policy_json' => ['robots' => 'noindex,follow'],
        ]));
    }
}
