<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

require_once __DIR__.'/Concerns/AssertsExactPackagePromotionConformance.php';

use App\Services\ContentPromotion\PromotionAdapterRegistry;
use App\Services\ContentPromotion\PromotionContext;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\ContentPromotion\Concerns\AssertsExactPackagePromotionConformance;

final class PromotionAdapterCapabilityTest extends TestCase
{
    use AssertsExactPackagePromotionConformance;

    #[DataProvider('legacyAdapters')]
    public function test_legacy_adapters_fail_closed_for_every_phase_until_audit_and_rollback_are_compatible(string $lane, string $subscope): void
    {
        $adapter = app(PromotionAdapterRegistry::class)->resolve($lane, $subscope);
        $context = new PromotionContext(
            packageDirectory: base_path('content_assets/en-content-parity'),
            packageSha256: str_repeat('a', 64),
            lane: $lane,
            subscope: $subscope,
            sourceCommit: str_repeat('b', 40),
            executorReleaseSha256: str_repeat('c', 64),
            releasePolicySha256: str_repeat('d', 64),
            workflowRunId: '1',
            workflowRunAttempt: 1,
            workflowSignature: str_repeat('f', 64),
            expectedRowCount: 1,
            idempotencyKey: str_repeat('e', 64),
        );

        foreach (['preflight', 'draftImport', 'publish', 'liveQa'] as $method) {
            $this->assertAdapterFailsClosed(fn (): array => $adapter->{$method}($context));
        }
        $this->assertAdapterFailsClosed(function () use ($adapter, $context): void {
            $adapter->rollback($context, 'none');
        });
    }

    public function test_registry_capabilities_are_proven_by_concrete_adapters_and_match_config(): void
    {
        $registry = app(PromotionAdapterRegistry::class);
        $capabilities = $registry->capabilitiesByLaneSubscope();

        self::assertCount(10, $capabilities);
        self::assertCount(10, $registry->capabilities());
        self::assertSame('audit_compatible', $capabilities['W1/mbti-comparisons']);
        self::assertSame('audit_compatible', $capabilities['W1/mbti-results']);
        self::assertSame('audit_compatible', $capabilities['W2/big-five']);
        self::assertSame('audit_compatible', $capabilities['W5/enneagram']);
        self::assertSame(4, count(array_filter($capabilities, static fn (string $capability): bool => $capability === 'audit_compatible')));
        self::assertSame(6, count(array_filter($capabilities, static fn (string $capability): bool => $capability === 'fail_closed_legacy_audit')));
    }

    /** @return iterable<string, array{string,string}> */
    public static function legacyAdapters(): iterable
    {
        yield 'W3 Articles' => ['W3', 'articles'];
        yield 'W3 Career Guides' => ['W3', 'career-guides'];
        yield 'W4 RIASEC' => ['W4', 'riasec'];
        yield 'W6 IQ' => ['W6', 'iq'];
        yield 'W7 EQ' => ['W7', 'eq'];
        yield 'W8 Career Jobs' => ['W8', 'career-jobs'];
    }
}
