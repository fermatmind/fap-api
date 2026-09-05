<?php

declare(strict_types=1);

namespace App\Services\Career\Review;

use App\Models\CareerSearchEntryQualityBatchOperation;
use App\Services\ReviewGovernance\ReviewAttestationCanonicalizer;
use App\Support\SchemaBaseline;
use Throwable;

/**
 * Fail-closed runtime lookup for an exact, unrolled-back batch apply receipt.
 *
 * @review-surface career_trust_manifest
 */
final class CareerSearchEntryQualityBatchApplyAuthority
{
    /** @var array<string,string|null> */
    private array $requestMemo = [];

    private ?bool $tableAvailable = null;

    public function __construct(
        private ReviewAttestationCanonicalizer $canonicalizer,
    ) {}

    /** @param array<string,mixed> $projection */
    public function contentQualityTier(array $projection): ?string
    {
        $memoKey = implode(':', [
            (string) ($projection['review_attestation_id'] ?? ''),
            (string) ($projection['review_evidence_sha256'] ?? ''),
            (string) ($projection['package_sha256'] ?? ''),
            (string) ($projection['target_set_sha256'] ?? ''),
            (string) ($projection['target_count'] ?? ''),
        ]);
        if (array_key_exists($memoKey, $this->requestMemo)) {
            return $this->requestMemo[$memoKey];
        }

        try {
            $this->tableAvailable ??= SchemaBaseline::tableExists(
                'career_search_entry_quality_batch_operations'
            );
            if (! $this->tableAvailable
                || (int) ($projection['target_count'] ?? 0) !== 300) {
                return $this->requestMemo[$memoKey] = null;
            }
            $apply = CareerSearchEntryQualityBatchOperation::query()
                ->where('operation_type', CareerSearchEntryQualityBatchControlService::OPERATION_APPLY)
                ->where('review_attestation_id', (int) ($projection['review_attestation_id'] ?? 0))
                ->where('review_evidence_sha256', (string) ($projection['review_evidence_sha256'] ?? ''))
                ->where('review_package_sha256', (string) ($projection['package_sha256'] ?? ''))
                ->where('target_set_sha256', (string) ($projection['target_set_sha256'] ?? ''))
                ->where('candidate_count', 50)
                ->where('bilingual_url_count', 100)
                ->latest('id')
                ->first();
            if (! $apply instanceof CareerSearchEntryQualityBatchOperation
                || CareerSearchEntryQualityBatchOperation::query()
                    ->where('operation_type', CareerSearchEntryQualityBatchControlService::OPERATION_ROLLBACK)
                    ->where('apply_receipt_sha256', (string) $apply->receipt_sha256)
                    ->exists()
                || ! $this->receiptIsAuthentic($apply)) {
                return $this->requestMemo[$memoKey] = null;
            }

            return $this->requestMemo[$memoKey]
                = CareerSearchEntryTierResolver::CONTENT_QUALITY_TIER_CONTROLLED_CANDIDATE;
        } catch (Throwable) {
            return $this->requestMemo[$memoKey] = null;
        }
    }

    private function receiptIsAuthentic(
        CareerSearchEntryQualityBatchOperation $operation,
    ): bool {
        $receipt = $operation->canonical_receipt_json;
        if (! is_array($receipt)) {
            return false;
        }
        $claimed = $receipt['receipt_sha256'] ?? null;
        unset($receipt['receipt_sha256']);

        return is_string($claimed)
            && hash_equals((string) $operation->receipt_sha256, $claimed)
            && hash_equals(
                hash('sha256', $this->canonicalizer->encode($receipt)),
                $claimed,
            )
            && ($receipt['operation_id'] ?? null) === $operation->operation_id
            && ($receipt['operation_type'] ?? null) === $operation->operation_type
            && ($receipt['review_evidence_sha256'] ?? null) === $operation->review_evidence_sha256
            && ($receipt['review_package_sha256'] ?? null) === $operation->review_package_sha256
            && ($receipt['target_set_sha256'] ?? null) === $operation->target_set_sha256;
    }
}
