<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion\Concerns;

use App\Services\ContentPromotion\PromotionContext;
use DomainException;

trait AssertsExactPackagePromotionConformance
{
    /** @param array<string, mixed> $result */
    protected function assertExactPhaseResult(array $result, PromotionContext $context, string $phase): void
    {
        self::assertTrue($result['ok'] ?? false);
        self::assertSame($context->expectedRowCount, $result['readback_count'] ?? null);
        self::assertSame(0, $result['indexability_mutation_count'] ?? null);
        self::assertSame(0, $result['sitemap_mutation_count'] ?? null);
        self::assertSame(0, $result['llms_mutation_count'] ?? null);
        self::assertSame(0, $result['search_mutation_count'] ?? null);
        self::assertSame(0, $result['deploy_mutation_count'] ?? null);
        if ($phase === 'draft-import') {
            self::assertSame(0, $result['published_count'] ?? null);
        }
        if (in_array($phase, ['publish', 'live-qa'], true)) {
            self::assertSame($context->expectedRowCount, $result['published_count'] ?? null);
        }
    }

    protected function assertAdapterFailsClosed(callable $operation): void
    {
        try {
            $operation();
            self::fail('The adapter operation must fail closed.');
        } catch (DomainException $exception) {
            self::assertSame('adapter_audit_metadata_incompatible', $exception->getMessage());
        }
    }

    /** @param array<string, mixed> $receipt */
    protected function assertReceiptChainsFrom(string $previousReceiptPath, array $receipt, string $previousReceiptKind): void
    {
        $previous = json_decode((string) file_get_contents($previousReceiptPath), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($previous);
        self::assertSame($previousReceiptKind, $previous['receipt_kind'] ?? null);
        self::assertSame('SUCCEEDED', $previous['result'] ?? null);
        self::assertSame(hash_file('sha256', $previousReceiptPath), $receipt['previous_receipt_sha256'] ?? null);
    }
}
