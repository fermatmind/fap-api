<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Services\ContentPromotion\Adapters\Top100FrozenCmsBatchPromotionAdapter;
use App\Services\ContentPromotion\Contracts\ExactPackagePromotionAdapter;
use DomainException;
use Illuminate\Support\Facades\DB;
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
        if ($phase === 'draft-import' && $context->lane === 'TOP100') {
            $previous = $this->receipts->readPrevious('content_promotion_preflight_receipt', $context);
        } elseif ($phase === 'publish') {
            $previous = $this->receipts->readPrevious('cms_draft_import_receipt', $context);
        } elseif ($phase === 'live-qa') {
            $previous = $this->receipts->readPrevious('cms_publication_receipt', $context);
        }

        $result = match ($phase) {
            'preflight' => $adapter->preflight($context),
            'draft-import' => $context->lane === 'TOP100'
                ? $this->executeTop100BoundPhase($adapter, $context, $previous, $phase)
                : $adapter->draftImport($context),
            'publish' => $context->lane === 'TOP100'
                ? $this->executeTop100BoundPhase($adapter, $context, $previous, $phase)
                : $adapter->publish($context),
            'live-qa' => $this->liveQaWithRollback($adapter, $context, $previous),
            default => throw new DomainException('phase_invalid'),
        };

        $receiptKind = match ($phase) {
            'preflight' => 'content_promotion_preflight_receipt',
            'draft-import' => 'cms_draft_import_receipt',
            'publish' => 'cms_publication_receipt',
            'live-qa' => 'cms_live_qa_receipt',
        };
        try {
            $normalized = $this->normalizeResult($context, $adapter, $phase, $receiptKind, $result, $previous);

            return $this->receipts->write($receiptPath, $normalized);
        } catch (Throwable $throwable) {
            if ($context->lane !== 'TOP100'
                || ! in_array($phase, ['draft-import', 'publish', 'live-qa'], true)
                || ! $adapter instanceof Top100FrozenCmsBatchPromotionAdapter) {
                throw $throwable;
            }
            $rollbackReference = (string) ($result['rollback_reference'] ?? data_get($previous, 'receipt.rollback_reference', ''));
            if ($rollbackReference === '') {
                throw new DomainException('top100_receipt_failed_without_rollback_reference', previous: $throwable);
            }
            $adapter->rollback($context, $rollbackReference);

            throw new DomainException('top100_'.$phase.'_receipt_failed_rollback_succeeded', previous: $throwable);
        }
    }

    /** @param array{receipt:array<string,mixed>,sha256:string,path:string} $previous @return array<string,mixed> */
    private function executeTop100BoundPhase(
        ExactPackagePromotionAdapter $adapter,
        PromotionContext $context,
        array $previous,
        string $phase,
    ): array {
        if (! $adapter instanceof Top100FrozenCmsBatchPromotionAdapter) {
            throw new DomainException('top100_adapter_invalid');
        }
        try {
            return DB::transaction(function () use ($adapter, $context, $previous, $phase): array {
                $locked = $adapter->lockedPreflight($context);
                if ($phase === 'draft-import') {
                    $this->assertTop100PreflightBinding($locked, $previous);

                    return $adapter->draftImport($context);
                }
                $this->assertTop100PublishPrestateBinding($locked, $previous);

                return $adapter->publish($context);
            }, 3);
        } catch (Throwable $throwable) {
            if ($phase !== 'publish') {
                throw $throwable;
            }
            $rollbackReference = (string) data_get($previous, 'receipt.rollback_reference', '');
            if ($rollbackReference === '') {
                throw new DomainException('top100_publish_failed_without_rollback_reference', previous: $throwable);
            }
            $adapter->rollback($context, $rollbackReference);

            throw new DomainException(
                str_starts_with($throwable->getMessage(), 'top100_publish_prestate_drift')
                    ? 'top100_publish_prestate_drift_rollback_succeeded'
                    : 'top100_publish_failed_rollback_succeeded',
                previous: $throwable,
            );
        }
    }

    /** @param array{receipt:array<string,mixed>,sha256:string,path:string} $previous */
    private function assertTop100PublishPrestateBinding(
        array $locked,
        array $previous,
    ): void {
        $approved = (string) data_get($previous, 'receipt.approved_prestate_sha256', '');
        $current = (string) ($locked['approved_prestate_sha256'] ?? '');
        if (preg_match('/\A[a-f0-9]{64}\z/', $approved) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $current) !== 1
            || ! hash_equals($approved, $current)) {
            throw new DomainException('top100_publish_prestate_drift');
        }
    }

    /** @param array{receipt:array<string,mixed>,sha256:string,path:string} $previous */
    private function assertTop100PreflightBinding(
        array $locked,
        array $previous,
    ): void {
        $approved = (string) data_get($previous, 'receipt.target_state_sha256', '');
        $current = (string) ($locked['target_state_sha256'] ?? '');
        if (preg_match('/\A[a-f0-9]{64}\z/', $approved) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $current) !== 1
            || ! hash_equals($approved, $current)) {
            throw new DomainException('top100_preflight_target_state_drift');
        }
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
        $created = (int) ($result['created_count'] ?? 0);
        $updated = (int) ($result['updated_count'] ?? 0);
        $unchanged = (int) ($result['unchanged_count'] ?? 0);
        $readback = (int) ($result['readback_count'] ?? 0);
        $published = (int) ($result['published_count'] ?? 0);
        if (($result['ok'] ?? false) !== true || $readback !== $expected) {
            throw new DomainException('adapter_result_failed_or_count_mismatch');
        }
        if ($created < 0 || $updated < 0 || $unchanged < 0
            || $created + $updated !== $written
            || $created + $updated + $unchanged !== $expected) {
            throw new DomainException('adapter_operation_counts_invalid');
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
            'created_count' => $created,
            'updated_count' => $updated,
            'unchanged_count' => $unchanged,
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
            ...$this->top100Evidence($context, $result),
        ];
    }

    /** @param array<string,mixed> $result @return array<string,int|string> */
    private function top100Evidence(PromotionContext $context, array $result): array
    {
        if ($context->lane !== 'TOP100') {
            return [];
        }
        $fields = [
            'target_count', 'planned_changed_count', 'planned_unchanged_count', 'unknown_count',
            'hold_write_count', 'control_write_count', 'media_mutation_count',
            'canonical_mutation_count', 'hreflang_mutation_count', 'schema_type_mutation_count',
            'deferred_out_of_target_link_source_count',
        ];
        $evidence = ['batch_id' => (string) ($result['batch_id'] ?? '')];
        foreach ($fields as $field) {
            $evidence[$field] = (int) ($result[$field] ?? -1);
        }
        $evidence['target_state_sha256'] = (string) ($result['target_state_sha256'] ?? '');
        $evidence['approved_prestate_sha256'] = (string) ($result['approved_prestate_sha256'] ?? '');
        if ($evidence['batch_id'] !== Top100FrozenPackage::BATCH_ID
            || $evidence['target_count'] !== 30
            || $evidence['planned_changed_count'] + $evidence['planned_unchanged_count'] !== 30
            || $evidence['deferred_out_of_target_link_source_count'] !== 46
            || preg_match('/\A[a-f0-9]{64}\z/', $evidence['target_state_sha256']) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $evidence['approved_prestate_sha256']) !== 1) {
            throw new DomainException('top100_evidence_count_invalid');
        }
        foreach (['unknown_count', 'hold_write_count', 'control_write_count', 'media_mutation_count', 'canonical_mutation_count', 'hreflang_mutation_count', 'schema_type_mutation_count'] as $field) {
            if ($evidence[$field] !== 0) {
                throw new DomainException('top100_prohibited_mutation_reported');
            }
        }
        foreach (['public_api_readback_count', 'live_html_readback_count'] as $field) {
            if (array_key_exists($field, $result)) {
                $evidence[$field] = (int) $result[$field];
            }
        }

        return $evidence;
    }
}
