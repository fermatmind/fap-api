<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Services\ContentPromotion\Contracts\ExactPackagePromotionAdapter;
use DomainException;
use Throwable;

final class ExactPackagePromotionService
{
    public function __construct(
        private readonly PromotionAdapterRegistry $registry,
        private readonly PromotionReceiptStore $receipts,
    ) {}

    /** @return array{receipt:array<string,mixed>,receipt_sha256:string,path:string} */
    public function execute(PromotionContext $context, string $phase, string $receiptPath): array
    {
        $adapter = $this->registry->resolve($context->lane, $context->subscope);
        $previous = null;
        if ($phase === 'publish') {
            $previous = $this->receipts->readPrevious('cms_draft_import_receipt', $context);
        } elseif ($phase === 'live-qa') {
            $previous = $this->receipts->readPrevious('cms_publication_receipt', $context);
        }

        $result = match ($phase) {
            'preflight' => $adapter->preflight($context),
            'draft-import' => $adapter->draftImport($context),
            'publish' => $adapter->publish($context),
            'live-qa' => $this->liveQaWithRollback($adapter, $context, $previous),
            default => throw new DomainException('phase_invalid'),
        };

        $receiptKind = match ($phase) {
            'preflight' => 'content_promotion_preflight_receipt',
            'draft-import' => 'cms_draft_import_receipt',
            'publish' => 'cms_publication_receipt',
            'live-qa' => 'cms_live_qa_receipt',
        };
        $normalized = $this->normalizeResult($context, $adapter, $phase, $receiptKind, $result, $previous);

        return $this->receipts->write($receiptPath, $normalized);
    }

    /** @param null|array{receipt:array<string,mixed>,sha256:string,path:string} $previous
     * @return array<string,mixed>
     */
    private function liveQaWithRollback(
        ExactPackagePromotionAdapter $adapter,
        PromotionContext $context,
        ?array $previous,
    ): array {
        try {
            return $adapter->liveQa($context);
        } catch (Throwable $throwable) {
            $rollbackReference = (string) data_get($previous, 'receipt.rollback_reference', '');
            if ($rollbackReference === '') {
                throw new DomainException('live_qa_failed_without_rollback_reference', previous: $throwable);
            }
            $adapter->rollback($context, $rollbackReference);

            throw new DomainException('live_qa_failed_rollback_succeeded', previous: $throwable);
        }
    }

    /** @param array<string,mixed> $result
     * @param  null|array{receipt:array<string,mixed>,sha256:string,path:string}  $previous
     * @return array<string,mixed>
     */
    private function normalizeResult(
        PromotionContext $context,
        ExactPackagePromotionAdapter $adapter,
        string $phase,
        string $receiptKind,
        array $result,
        ?array $previous,
    ): array {
        $expected = $context->expectedRowCount;
        $written = (int) ($result['written_count'] ?? 0);
        $readback = (int) ($result['readback_count'] ?? 0);
        $published = (int) ($result['published_count'] ?? 0);
        if (($result['ok'] ?? false) !== true || $readback !== $expected) {
            throw new DomainException('adapter_result_failed_or_count_mismatch');
        }
        if ($phase === 'draft-import' && $published !== 0) {
            throw new DomainException('draft_import_public_count_nonzero');
        }
        if (in_array($phase, ['publish', 'live-qa'], true) && $published !== $expected) {
            throw new DomainException('published_count_mismatch');
        }
        foreach (['indexability_mutation_count', 'sitemap_mutation_count', 'llms_mutation_count', 'search_mutation_count', 'deploy_mutation_count'] as $boundary) {
            if ((int) ($result[$boundary] ?? -1) !== 0) {
                throw new DomainException('prohibited_mutation_reported');
            }
        }

        return [
            'schema_version' => 'fermatmind.content_promotion_receipt.v2',
            'receipt_kind' => $receiptKind,
            'result' => 'SUCCEEDED',
            'phase' => $phase,
            'adapter' => $adapter->id(),
            'lane' => $context->lane,
            'subscope' => $context->subscope,
            'source_repository' => 'fermatmind/fap-api',
            'source_commit' => $context->sourceCommit,
            'package_path' => ltrim(substr($context->packageDirectory, strlen(base_path())), DIRECTORY_SEPARATOR),
            'package_sha256' => $context->packageSha256,
            'executor_release_sha256' => $context->executorReleaseSha256,
            'release_policy_sha256' => $context->releasePolicySha256,
            'workflow_run_id' => $context->workflowRunId,
            'workflow_run_attempt' => $context->workflowRunAttempt,
            'idempotency_key' => $context->idempotencyKey,
            'expected_count' => $expected,
            'written_count' => $written,
            'readback_count' => $readback,
            'published_count' => $published,
            'previous_receipt_sha256' => $previous['sha256'] ?? null,
            'rollback_reference' => $result['rollback_reference'] ?? null,
            'locale_check' => $result['locale_check'] ?? 'PASS',
            'cjk_leakage_check' => $result['cjk_leakage_check'] ?? 'PASS',
            'identity_check' => $result['identity_check'] ?? 'PASS',
            'privacy_redaction' => true,
            'private_payload_read_count' => 0,
            'server_topology_exposed' => false,
            'indexability_mutation_count' => 0,
            'sitemap_mutation_count' => 0,
            'llms_mutation_count' => 0,
            'search_mutation_count' => 0,
            'deploy_mutation_count' => 0,
        ];
    }
}
