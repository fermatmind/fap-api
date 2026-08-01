<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\MbtiCrossTypeComparisonAuthority;
use App\Services\ContentImport\MbtiComparisonEnglishPackageImporter;
use DomainException;
use Illuminate\Support\Facades\File;

/** @review-surface mbti_cross_type_comparison_authority */
final class MbtiComparisonEnglishPublishDiagnosticService
{
    public const APPROVAL_SHA256 = '78b5cbe1bc0a26f41a183996f62808233800c3ffb677397ebbe46d8f8dcf232a';

    private const PRIOR_PUBLISH_APPROVAL_SHA256 = '3a9a929dcfecc64a02d40453540a0d0ad71c002f10304d8d544c597f808a3f1c';

    private const TARGET_AUTHORITY_RECEIPT_SHA256 = 'b44e50a252f48d85ca93f572f4bf5ee7f334d6155a19713cfd3c8185359edae8';

    /** @var array<string, string> */
    private const FROZEN_SOURCE_SHA256_BY_SLUG = [
        'enfp-vs-entp' => '64dbdc436327232d5152080f02b8142ceb7cde4e7658c61c88e730593135121a',
        'entj-vs-intj' => '6f1512fa1778a5bee3100188943439bac7fb6fe9a294257848b42e1051a9bea9',
        'estj-vs-entj' => 'ae1c150b66ef50e0d8494f22ae0535940f6a5dbba22262d6e3525d6998a5ec4b',
        'infj-vs-infp' => 'e2c426ffed25ac9b6780494ce35a626f1a1566dd6a4d0e050a577a86fddf256a',
        'intj-vs-intp' => 'cdd6ff50c8246b3c1271b056dfa2559ca65c7521fd53170b00167bd342136275',
        'isfp-vs-infp' => '963d82928f858a712799d7c9cc9ae8f6c86f24a5ece2e8850757b12abccb88d8',
        'istj-vs-isfj' => 'c1d4707c1e4826bf3ded3484c22909cbe866adc8a89efba726422cd2da027b18',
    ];

    public function __construct(private readonly Mbti64CrossTypeComparisonPublicReadModel $readModel) {}

    public static function defaultApprovalPath(): string
    {
        return base_path('content_assets/en-content-parity/CONTROL-approvals/W1-MBTI-COMPARISONS/publish-diagnostic-readback-approval-2026-08-02.json');
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnose(string $approvalPath, string $confirmedApprovalSha256): array
    {
        $approval = $this->validatedApproval($approvalPath, $confirmedApprovalSha256);
        $authorities = MbtiCrossTypeComparisonAuthority::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', 'en')
            ->whereIn('slug', MbtiComparisonEnglishPackageImporter::EXACT_SLUGS)
            ->get()
            ->keyBy('slug');

        if ($authorities->count() !== count(MbtiComparisonEnglishPackageImporter::EXACT_SLUGS)) {
            $this->fail('exact_target_set_mismatch');
        }
        $extraTargetExists = MbtiCrossTypeComparisonAuthority::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', 'en')
            ->where('comparison_type', MbtiCrossTypeComparisonAuthority::COMPARISON_TYPE)
            ->where('source_package_id', MbtiComparisonEnglishPackageImporter::PACKAGE_ID)
            ->whereNotIn('slug', MbtiComparisonEnglishPackageImporter::EXACT_SLUGS)
            ->exists();
        if ($extraTargetExists) {
            $this->fail('extra_english_comparison_in_frozen_package');
        }

        $rows = [];
        $states = [];
        foreach (MbtiComparisonEnglishPackageImporter::EXACT_SLUGS as $slug) {
            $authority = $authorities->get($slug);
            if (! $authority instanceof MbtiCrossTypeComparisonAuthority) {
                $this->fail('exact_target_missing');
            }

            $this->assertExactAuthority($authority, $slug, self::FROZEN_SOURCE_SHA256_BY_SLUG[$slug] ?? null);
            $state = $this->authorityState($authority);
            $states[] = $state;
            $rows[] = [
                'identity' => ['org_id' => 0, 'locale' => 'en', 'slug' => $slug],
                'source_sha256' => (string) $authority->source_sha256,
                'authority_state' => $state,
                'publish_status' => (string) $authority->publish_status,
                'is_public' => (bool) $authority->is_public,
                'is_indexable' => (bool) $authority->is_indexable,
                'sitemap_eligible' => (bool) $authority->sitemap_eligible,
                'llms_eligible' => (bool) $authority->llms_eligible,
                'search_submission_eligible' => (bool) $authority->search_submission_eligible,
            ];
        }

        if (array_values(array_unique($states)) === []) {
            $this->fail('exact_target_set_mismatch');
        }
        if (count(array_unique($states)) !== 1) {
            $this->fail('diagnostic_mixed_authority_state');
        }

        $authorityState = $states[0];
        $projectionCount = 0;
        foreach ($rows as $row) {
            $projectionCount += $this->assertProjection(
                (string) $row['identity']['slug'],
                $authorityState,
                (string) $row['source_sha256'],
            );
        }

        return [
            'artifact' => 'EN-PARITY-W1-MBTI-COMPARISON-PUBLISH-DIAGNOSTIC-RECEIPT',
            'schema_version' => 'fermatmind.en_parity.comparison_publish_diagnostic_receipt.v1',
            'status' => 'PASS',
            'ok' => true,
            'mode' => 'controlled_read_only_publish_diagnostic',
            'read_only' => true,
            'cms_write_attempted' => false,
            'publish_attempted' => false,
            'activation_attempted' => false,
            'active_pointer_changed' => false,
            'indexability_attempted' => false,
            'sitemap_attempted' => false,
            'llms_attempted' => false,
            'search_submission_attempted' => false,
            'deploy_attempted' => false,
            'private_authority_read_attempted' => false,
            'attempt_report_order_payment_read_attempted' => false,
            'package_sha256' => MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256,
            'package_id' => MbtiComparisonEnglishPackageImporter::PACKAGE_ID,
            'target_authority_receipt_sha256' => self::TARGET_AUTHORITY_RECEIPT_SHA256,
            'approval' => [
                'approval_ref' => $approval['approval_ref'],
                'approval_sha256' => self::APPROVAL_SHA256,
                'gate' => $approval['gate'],
                'verdict' => $approval['verdict'],
            ],
            'authority_state' => $authorityState,
            'row_count' => count($rows),
            'rows' => $rows,
            'runtime_projection' => [
                'visible_row_count' => $projectionCount,
                'expected_visible_row_count' => $authorityState === 'published_exact_english_comparison' ? 7 : 0,
                'meta_robots' => 'noindex,follow',
                'sitemap_excluded' => true,
                'llms_withheld' => true,
                'search_submission_held' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedApproval(string $approvalPath, string $confirmedApprovalSha256): array
    {
        if (strtolower(trim($confirmedApprovalSha256)) !== self::APPROVAL_SHA256 || ! is_file($approvalPath) || is_link($approvalPath)) {
            $this->fail('diagnostic_approval_sha256_mismatch');
        }

        $bytes = File::get($approvalPath);
        if (! hash_equals(self::APPROVAL_SHA256, hash('sha256', $bytes))) {
            $this->fail('diagnostic_approval_file_sha256_mismatch');
        }

        try {
            $approval = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->fail('diagnostic_approval_json_invalid');
        }

        if (! is_array($approval)
            || ($approval['artifact_kind'] ?? null) !== 'controlled_transition_approval'
            || ($approval['schema_version'] ?? null) !== 'fermatmind.en_content_parity_controlled_transition_approval.v1'
            || ($approval['control_id'] ?? null) !== 'EN-PARITY-W1-MBTI-COMPARISON-PUBLISH-DIAGNOSTIC-01'
            || ($approval['approval_owner'] ?? null) !== 'human_operator'
            || ($approval['approval_ref'] ?? null) !== 'human-operator:w1-mbti-comparisons-publish-diagnostic:2026-08-02'
            || ($approval['subscope_id'] ?? null) !== 'W1-MBTI-COMPARISONS'
            || ($approval['package_sha256'] ?? null) !== MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256
            || ($approval['target_authority_receipt_sha256'] ?? null) !== self::TARGET_AUTHORITY_RECEIPT_SHA256
            || ($approval['prior_publish_approval_sha256'] ?? null) !== self::PRIOR_PUBLISH_APPROVAL_SHA256
            || ($approval['gate'] ?? null) !== 'publish_diagnostic_readback'
            || ($approval['verdict'] ?? null) !== 'APPROVED'
            || ($approval['exact_slugs'] ?? null) !== MbtiComparisonEnglishPackageImporter::EXACT_SLUGS) {
            $this->fail('diagnostic_approval_contract_mismatch');
        }

        $expectedPermissions = [
            'target_authority_readback_authorized' => true,
            'publish_authorized' => false,
            'activation_authorized' => false,
            'active_pointer_authorized' => false,
            'indexability_authorized' => false,
            'sitemap_authorized' => false,
            'hreflang_authorized' => false,
            'llms_authorized' => false,
            'json_ld_authorized' => false,
            'search_submission_authorized' => false,
            'deployment_authorized' => false,
            'private_result_read_authorized' => false,
            'attempt_report_order_payment_read_authorized' => false,
        ];
        if (($approval['permissions'] ?? null) !== $expectedPermissions) {
            $this->fail('diagnostic_permission_boundary_open');
        }

        return $approval;
    }

    private function assertExactAuthority(MbtiCrossTypeComparisonAuthority $authority, string $slug, ?string $expectedSourceSha256): void
    {
        if ($authority->comparison_type !== MbtiCrossTypeComparisonAuthority::COMPARISON_TYPE
            || $authority->source_package_id !== MbtiComparisonEnglishPackageImporter::PACKAGE_ID
            || ! is_string($expectedSourceSha256)
            || ! hash_equals($expectedSourceSha256, (string) $authority->source_sha256)) {
            $this->fail('target_package_or_source_sha256_mismatch');
        }
        if ($authority->slug !== $slug
            || (int) $authority->org_id !== 0
            || $authority->locale !== 'en') {
            $this->fail('target_identity_mismatch');
        }
        if ((bool) $authority->is_indexable
            || (bool) $authority->sitemap_eligible
            || (bool) $authority->llms_eligible
            || (bool) $authority->search_submission_eligible
            || $authority->indexability_status !== 'blocked') {
            $this->fail('discoverability_boundary_open');
        }
    }

    private function authorityState(MbtiCrossTypeComparisonAuthority $authority): string
    {
        if ($authority->review_status === 'w9_passed_pending_editorial'
            && $authority->publish_status === 'draft'
            && ! $authority->is_public
            && $authority->published_at === null) {
            return 'draft_exact_english_comparison';
        }
        if ($authority->review_status === 'operator_approved_published'
            && $authority->publish_status === 'published'
            && $authority->is_public
            && $authority->published_at !== null) {
            return 'published_exact_english_comparison';
        }

        $this->fail('target_lifecycle_state_mismatch');
    }

    private function assertProjection(string $slug, string $state, string $sourceSha256): int
    {
        $projection = $this->readModel->find($slug, 'en');
        if ($state === 'draft_exact_english_comparison') {
            if ($projection !== null) {
                $this->fail('draft_runtime_projection_visible');
            }

            return 0;
        }
        if (! is_array($projection)
            || ($projection['comparison_slug'] ?? null) !== $slug
            || ($projection['locale'] ?? null) !== 'en'
            || ! in_array(MbtiComparisonEnglishPackageImporter::PACKAGE_ID, (array) ($projection['source_refs'] ?? []), true)
            || ($projection['source_sha256'] ?? null) !== $sourceSha256
            || ($projection['is_public'] ?? null) !== true
            || ($projection['is_indexable'] ?? null) !== false
            || ($projection['sitemap_eligible'] ?? null) !== false
            || ($projection['llms_eligible'] ?? null) !== false
            || ($projection['indexability_status'] ?? null) !== 'blocked') {
            $this->fail('runtime_projection_contract_mismatch');
        }

        return 1;
    }

    private function fail(string $code): never
    {
        throw new DomainException($code.': controlled MBTI comparison diagnostic failed.');
    }
}
