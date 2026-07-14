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
