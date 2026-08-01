<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Services\ContentPromotion\PromotionAdapterRegistry;
use App\Services\ContentPromotion\PromotionContext;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PromotionAdapterCapabilityTest extends TestCase
{
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
            try {
                $adapter->{$method}($context);
                self::fail($method.' must fail closed.');
            } catch (DomainException $exception) {
                self::assertSame('adapter_audit_metadata_incompatible', $exception->getMessage());
            }
        }
        try {
            $adapter->rollback($context, 'none');
            self::fail('rollback must fail closed.');
        } catch (DomainException $exception) {
            self::assertSame('adapter_audit_metadata_incompatible', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string,string}> */
    public static function legacyAdapters(): iterable
    {
        yield 'W1 MBTI results' => ['W1', 'mbti-results'];
        yield 'W2 Big Five' => ['W2', 'big-five'];
        yield 'W3 Articles' => ['W3', 'articles'];
        yield 'W3 Career Guides' => ['W3', 'career-guides'];
        yield 'W4 RIASEC' => ['W4', 'riasec'];
        yield 'W5 Enneagram' => ['W5', 'enneagram'];
        yield 'W6 IQ' => ['W6', 'iq'];
        yield 'W7 EQ' => ['W7', 'eq'];
        yield 'W8 Career Jobs' => ['W8', 'career-jobs'];
    }
}
