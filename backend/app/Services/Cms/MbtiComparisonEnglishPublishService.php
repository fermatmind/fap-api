<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\MbtiCrossTypeComparisonAuthority;
use App\Services\ContentImport\MbtiComparisonEnglishPackageImporter;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/** @review-surface mbti_cross_type_comparison_authority */
final class MbtiComparisonEnglishPublishService
{
    public const APPROVAL_SHA256 = '3a9a929dcfecc64a02d40453540a0d0ad71c002f10304d8d544c597f808a3f1c';

    public const TARGET_AUTHORITY_RECEIPT_SHA256 = 'b44e50a252f48d85ca93f572f4bf5ee7f334d6155a19713cfd3c8185359edae8';

    public const APPROVAL_REF = 'human-operator:w1-mbti-comparisons-publish-live:2026-08-01';

    /**
     * @return list<string>
     */
    public static function exactSlugs(): array
    {
        return MbtiComparisonEnglishPackageImporter::EXACT_SLUGS;
    }

    public static function defaultApprovalPath(): string
    {
        return base_path('content_assets/en-content-parity/CONTROL-approvals/W1-MBTI-COMPARISONS/publish-live-approval-2026-08-01.json');
    }

    /**
     * @return array<string, mixed>
     */
    public function publish(string $approvalPath, string $confirmedApprovalSha256): array
    {
        $approval = $this->validatedApproval($approvalPath, $confirmedApprovalSha256);

        return $this->publishAuthorized(['approval' => [
            'approval_ref' => $approval['approval_ref'],
            'approval_sha256' => self::APPROVAL_SHA256,
            'gate' => $approval['gate'],
            'verdict' => $approval['verdict'],
        ]], false);
    }

    /** @param array<string, mixed> $automationContext
     * @return array<string, mixed>
     */
    public function publishAutomated(array $automationContext): array
    {
        $authorization = $this->validatedAutomationContext($automationContext);

        return $this->publishAuthorized(['automation_authorization' => $authorization], true);
    }

    /** @param array<string, array<string, mixed>> $authorization
     * @return array<string, mixed>
     */
    private function publishAuthorized(array $authorization, bool $automated): array
    {

        return DB::transaction(function () use ($authorization, $automated): array {
            $rows = [];
            $writesCommitted = false;

            foreach (self::exactSlugs() as $slug) {
                $authority = MbtiCrossTypeComparisonAuthority::query()
                    ->withoutGlobalScopes()
                    ->where('org_id', 0)
                    ->where('locale', 'en')
                    ->where('slug', $slug)
                    ->lockForUpdate()
                    ->first();

                if (! $authority instanceof MbtiCrossTypeComparisonAuthority) {
                    $this->fail('exact_target_missing', 'An approved English draft target does not exist.');
                }

                $action = $this->publishExactDraft($authority, $automated);
                $writesCommitted = $writesCommitted || $action === 'published_exact_english_comparison';
                $rows[] = $this->receiptRow($authority, $action);
            }

            $this->assertNoExtraPublishedEnglishComparison();

            return [
                'artifact' => 'EN-PARITY-W1-MBTI-COMPARISON-PUBLISH-LIVE-QA-RECEIPT',
                'schema_version' => 'fermatmind.en_parity.comparison_publish_live_qa_receipt.v1',
                'status' => 'PASS',
                'ok' => true,
                'mode' => 'controlled_publish_live_qa',
                'writes_committed' => $writesCommitted,
                'publish_attempted' => true,
                'activation_attempted' => false,
                'active_pointer_changed' => false,
                'indexability_attempted' => false,
                'sitemap_attempted' => false,
                'llms_attempted' => false,
                'search_submission_attempted' => false,
                'deploy_attempted' => false,
                'private_authority_read_attempted' => false,
                'package_sha256' => MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256,
                'package_id' => MbtiComparisonEnglishPackageImporter::PACKAGE_ID,
                'target_authority_receipt_sha256' => self::TARGET_AUTHORITY_RECEIPT_SHA256,
                ...$authorization,
                'row_count' => count($rows),
                'rows' => $rows,
                'readback' => [
                    'english_only' => true,
                    'exact_slug_set_only' => true,
                    'published_public_row_count' => count($rows),
                    'indexable_row_count' => 0,
                    'sitemap_eligible_row_count' => 0,
                    'llms_eligible_row_count' => 0,
                    'search_submission_eligible_row_count' => 0,
                    'meta_robots' => 'noindex,follow',
                ],
            ];
        }, 3);
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function validatedAutomationContext(array $context): array
    {
        $expected = [
            'schema_version' => 'fermatmind.content_promotion_automation_context.v2',
            'lane' => 'W1',
            'subscope' => 'mbti-comparisons',
            'package_sha256' => MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256,
            'source_repository' => 'fermatmind/fap-api',
            'cms_draft_import_authorized' => true,
            'public_publish_authorized' => true,
            'indexability_authorized' => false,
            'sitemap_authorized' => false,
            'llms_authorized' => false,
            'search_submission_authorized' => false,
            'deploy_authorized' => false,
        ];
        foreach ($expected as $field => $value) {
            if (($context[$field] ?? null) !== $value) {
                $this->fail('automation_context_mismatch', 'The trusted promotion context is not exact or opens a prohibited boundary.');
            }
        }
        if (preg_match('/\A[a-f0-9]{40}\z/', (string) ($context['source_commit'] ?? '')) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', (string) ($context['executor_release_sha256'] ?? '')) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', (string) ($context['release_policy_sha256'] ?? '')) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', (string) ($context['idempotency_key'] ?? '')) !== 1) {
            $this->fail('automation_context_integrity_invalid', 'The trusted promotion context integrity binding is incomplete.');
        }
        $this->assertTrustedWorkflowAuthorization($context);

        return $context;
    }

    /** @param array<string, mixed> $context */
    private function assertTrustedWorkflowAuthorization(array $context): void
    {
        $key = (string) config('content_promotion.workflow_identity_key', '');
        $policySha256 = hash('sha256', \App\Services\ContentPromotion\PromotionContextFactory::canonicalJson(
            (array) config('content_promotion.release_policy', []),
        ));
        $expectedIdempotencyKey = hash('sha256', implode('|', [
            'content-promotion-v2',
            'W1',
            'mbti-comparisons',
            MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256,
            (string) ($context['source_commit'] ?? ''),
            $policySha256,
        ]));
        $signatureMaterial = implode('|', [
            'content-promotion-v2',
            (string) ($context['source_commit'] ?? ''),
            (string) ($context['workflow_run_id'] ?? ''),
            (string) ($context['workflow_run_attempt'] ?? ''),
            'W1',
            'mbti-comparisons',
            MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256,
            $policySha256,
            '7',
        ]);
        $signature = (string) ($context['workflow_signature'] ?? '');
        if (strlen($key) < 32
            || ($context['release_policy_sha256'] ?? null) !== $policySha256
            || ($context['expected_row_count'] ?? null) !== 7
            || preg_match('/\A[1-9][0-9]{0,19}\z/', (string) ($context['workflow_run_id'] ?? '')) !== 1
            || ! is_int($context['workflow_run_attempt'] ?? null)
            || (int) $context['workflow_run_attempt'] < 1
            || ($context['idempotency_key'] ?? null) !== $expectedIdempotencyKey
            || preg_match('/\A[a-f0-9]{64}\z/', $signature) !== 1
            || ! hash_equals(hash_hmac('sha256', $signatureMaterial, $key), $signature)) {
            $this->fail('automation_workflow_authorization_invalid', 'The automatic publish must originate from the trusted exact-package executor.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedApproval(string $approvalPath, string $confirmedApprovalSha256): array
    {
        $confirmedApprovalSha256 = strtolower(trim($confirmedApprovalSha256));
        if ($confirmedApprovalSha256 !== self::APPROVAL_SHA256 || ! is_file($approvalPath) || is_link($approvalPath)) {
            $this->fail('approval_sha256_mismatch', 'The exact human-operator publish approval is required.');
        }

        $bytes = File::get($approvalPath);
        if (! hash_equals(self::APPROVAL_SHA256, hash('sha256', $bytes))) {
            $this->fail('approval_file_sha256_mismatch', 'The approval artifact bytes are not the approved artifact.');
        }

        try {
            $approval = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->fail('approval_json_invalid', 'The approval artifact is not valid JSON.');
        }
        if (! is_array($approval)
            || ($approval['artifact_kind'] ?? null) !== 'controlled_transition_approval'
            || ($approval['schema_version'] ?? null) !== 'fermatmind.en_content_parity_controlled_transition_approval.v1'
            || ($approval['control_id'] ?? null) !== 'EN-PARITY-W1-MBTI-COMPARISON-PUBLISH-LIVE-QA-01'
            || ($approval['approval_owner'] ?? null) !== 'human_operator'
            || ($approval['approval_ref'] ?? null) !== self::APPROVAL_REF
            || ($approval['subscope_id'] ?? null) !== 'W1-MBTI-COMPARISONS'
            || ($approval['package_sha256'] ?? null) !== MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256
            || ($approval['target_authority_receipt_sha256'] ?? null) !== self::TARGET_AUTHORITY_RECEIPT_SHA256
            || ($approval['gate'] ?? null) !== 'published'
            || ($approval['verdict'] ?? null) !== 'APPROVED'
            || ($approval['exact_slugs'] ?? null) !== self::exactSlugs()) {
            $this->fail('approval_contract_mismatch', 'The approval artifact does not authorize this exact publish scope.');
        }

        $permissions = $approval['permissions'] ?? null;
        if (! is_array($permissions)
            || ($permissions['publish_authorized'] ?? null) !== true
            || ($permissions['target_authority_readback_authorized'] ?? null) !== true) {
            $this->fail('approval_publish_permission_missing', 'The approval lacks the exact publish and readback permissions.');
        }
        foreach (['activation_authorized', 'active_pointer_authorized', 'indexability_authorized', 'sitemap_authorized', 'hreflang_authorized', 'llms_authorized', 'json_ld_authorized', 'search_submission_authorized', 'deployment_authorized', 'private_result_read_authorized', 'attempt_report_order_payment_read_authorized'] as $denied) {
            if (($permissions[$denied] ?? null) !== false) {
                $this->fail('approval_permission_boundary_open', 'A prohibited authority permission is open.');
            }
        }

        return $approval;
    }

    private function publishExactDraft(MbtiCrossTypeComparisonAuthority $authority, bool $automated): string
    {
        if ($authority->comparison_type !== MbtiCrossTypeComparisonAuthority::COMPARISON_TYPE
            || $authority->source_package_id !== MbtiComparisonEnglishPackageImporter::PACKAGE_ID
            || ! preg_match('/\\A[a-f0-9]{64}\\z/', (string) $authority->source_sha256)) {
            $this->fail('target_package_or_identity_mismatch', 'The authority row is outside the frozen comparison package.');
        }

        $flagsAreHeld = ! $authority->is_indexable
            && ! $authority->sitemap_eligible
            && ! $authority->llms_eligible
            && ! $authority->search_submission_eligible
            && $authority->indexability_status === 'blocked';
        if (! $flagsAreHeld) {
            $this->fail('discoverability_boundary_open', 'A discoverability gate was already open.');
        }

        $draftReviewStatus = $automated ? 'w9_passed_automation_ready' : 'w9_passed_pending_editorial';
        $publishedReviewStatus = $automated ? 'automation_published' : 'operator_approved_published';
        if ($authority->publish_status === 'published' && $authority->is_public && $authority->review_status === $publishedReviewStatus) {
            return 'preserved_exact_published_english_comparison';
        }
        if ($authority->publish_status !== 'draft' || $authority->is_public || $authority->review_status !== $draftReviewStatus) {
            $this->fail('target_not_exact_english_draft', 'Only exact W9-passed English draft targets can be published.');
        }

        $authority->forceFill([
            'review_status' => $publishedReviewStatus,
            'publish_status' => 'published',
            'indexability_status' => 'blocked',
            'is_public' => true,
            'is_indexable' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'search_submission_eligible' => false,
            'published_at' => now(),
        ])->save();

        return 'published_exact_english_comparison';
    }

    /** @return array<string, mixed> */
    private function receiptRow(MbtiCrossTypeComparisonAuthority $authority, string $action): array
    {
        return [
            'identity' => ['org_id' => 0, 'locale' => 'en', 'slug' => $authority->slug],
            'source_sha256' => $authority->source_sha256,
            'action' => $action,
            'publish_status' => $authority->publish_status,
            'is_public' => $authority->is_public,
            'is_indexable' => $authority->is_indexable,
            'sitemap_eligible' => $authority->sitemap_eligible,
            'llms_eligible' => $authority->llms_eligible,
            'search_submission_eligible' => $authority->search_submission_eligible,
        ];
    }

    private function assertNoExtraPublishedEnglishComparison(): void
    {
        $extra = MbtiCrossTypeComparisonAuthority::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', 'en')
            ->where('comparison_type', MbtiCrossTypeComparisonAuthority::COMPARISON_TYPE)
            ->where('source_package_id', MbtiComparisonEnglishPackageImporter::PACKAGE_ID)
            ->where('publish_status', 'published')
            ->whereNotIn('slug', self::exactSlugs())
            ->exists();
        if ($extra) {
            $this->fail('extra_english_comparison_in_frozen_package', 'The frozen package has an unapproved published English comparison.');
        }
    }

    private function fail(string $code, string $message): never
    {
        throw new DomainException($code.': '.$message);
    }
}
