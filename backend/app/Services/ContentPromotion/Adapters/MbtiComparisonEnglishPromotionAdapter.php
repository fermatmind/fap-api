<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion\Adapters;

use App\Models\MbtiCrossTypeComparisonAuthority;
use App\Services\Cms\Mbti64CrossTypeComparisonPublicReadModel;
use App\Services\Cms\MbtiComparisonEnglishPublishService;
use App\Services\ContentImport\MbtiComparisonEnglishPackageImporter;
use App\Services\ContentPromotion\Contracts\ExactPackagePromotionAdapter;
use App\Services\ContentPromotion\PromotionAdapterResultFactory;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionRollbackSnapshotService;
use App\Services\ContentPromotion\PromotionTargetSet;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

final class MbtiComparisonEnglishPromotionAdapter implements ExactPackagePromotionAdapter
{
    private const INDEPENDENT_W9_REPORT_SHA256 = '3b2fc2d61f5153a9e59a25da0d3c5de26e12bc3d44dd1c4c1e109628c455b51b';

    private const EXTERNAL_W9_ENVELOPE_SHA256 = '6eaf6b98ba0395c008414c346c02610717b595c4349eb10ca60456b474401221';

    private const EXTERNAL_W9_SOURCE_COMMIT = '262ca23388273d5388fe759ce240cc4c6c658c0a';

    private const EXTERNAL_W9_SOURCE_PATH = 'generated/en-content-parity/W9-independent-qa/W1-mbti-comparisons/deecc817-renderer-2f388c32/independent_qa_report.json';

    /** @var array<string, string> */
    private const EXPECTED_SOURCE_SHA256_BY_SLUG = [
        'enfp-vs-entp' => '64dbdc436327232d5152080f02b8142ceb7cde4e7658c61c88e730593135121a',
        'entj-vs-intj' => '6f1512fa1778a5bee3100188943439bac7fb6fe9a294257848b42e1051a9bea9',
        'estj-vs-entj' => 'ae1c150b66ef50e0d8494f22ae0535940f6a5dbba22262d6e3525d6998a5ec4b',
        'infj-vs-infp' => 'e2c426ffed25ac9b6780494ce35a626f1a1566dd6a4d0e050a577a86fddf256a',
        'intj-vs-intp' => 'cdd6ff50c8246b3c1271b056dfa2559ca65c7521fd53170b00167bd342136275',
        'isfp-vs-infp' => '963d82928f858a712799d7c9cc9ae8f6c86f24a5ece2e8850757b12abccb88d8',
        'istj-vs-isfj' => 'c1d4707c1e4826bf3ded3484c22909cbe866adc8a89efba726422cd2da027b18',
    ];

    public function __construct(
        private readonly MbtiComparisonEnglishPackageImporter $importer,
        private readonly MbtiComparisonEnglishPublishService $publisher,
        private readonly Mbti64CrossTypeComparisonPublicReadModel $readModel,
        private readonly PromotionRollbackSnapshotService $snapshots,
    ) {}

    public function id(): string
    {
        return 'w1_mbti_comparisons_v2';
    }

    public function capability(): string
    {
        return 'audit_compatible';
    }

    public function supports(string $lane, ?string $subscope): bool
    {
        return $lane === 'W1' && $subscope === 'mbti-comparisons';
    }

    public function preflight(PromotionContext $context): array
    {
        $this->assertContext($context);
        $plan = $this->importer->plan($context->packageDirectory, $context->packageSha256);
        $count = (int) ($plan['row_count'] ?? 0);
        if ($this->hasLegacyPublishedPackageRows()) {
            $this->assertLegacyPublishedCohortIsAdoptable();
        }

        return PromotionAdapterResultFactory::make($context, 0, $count, 0, null, $this->verifiedZeroBoundaryMutations());
    }

    public function draftImport(PromotionContext $context): array
    {
        $this->assertContext($context);
        if ($this->hasLegacyPublishedPackageRows()) {
            $this->assertLegacyPublishedCohortIsAdoptable();

            return $this->adoptionResult($context, 0);
        }
        $rollbackReference = $this->captureRollbackSnapshot($context, 'before_draft_import');
        $receipt = $this->importer->importDraft(
            $context->packageDirectory,
            $context->packageSha256,
            '',
            '',
            $this->automationContext($context),
        );
        $written = (int) ($receipt['created_count'] ?? 0) + (int) ($receipt['updated_count'] ?? 0);

        return PromotionAdapterResultFactory::make(
            $context,
            $written,
            (int) data_get($receipt, 'readback.exact_row_count', 0),
            (int) data_get($receipt, 'readback.public_row_count', -1),
            $rollbackReference,
            $this->verifiedZeroBoundaryMutations(),
            [
                'created_count' => (int) ($receipt['created_count'] ?? 0),
                'updated_count' => (int) ($receipt['updated_count'] ?? 0),
                'unchanged_count' => $context->expectedRowCount - $written,
            ],
        );
    }

    public function publish(PromotionContext $context): array
    {
        $this->assertContext($context);
        if ($this->hasLegacyPublishedPackageRows()) {
            $this->assertLegacyPublishedCohortIsAdoptable();

            return $this->adoptionResult($context, $context->expectedRowCount);
        }
        $rollbackReference = $this->captureRollbackSnapshot($context, 'before_publication');
        $receipt = $this->publisher->publishAutomated($this->automationContext($context));
        $written = count(array_filter(
            (array) ($receipt['rows'] ?? []),
            static fn (mixed $row): bool => is_array($row) && ($row['action'] ?? null) === 'published_exact_english_comparison',
        ));

        return PromotionAdapterResultFactory::make(
            $context,
            $written,
            (int) ($receipt['row_count'] ?? 0),
            (int) data_get($receipt, 'readback.published_public_row_count', 0),
            $rollbackReference,
            $this->verifiedZeroBoundaryMutations(),
            ['created_count' => 0, 'updated_count' => $written, 'unchanged_count' => $context->expectedRowCount - $written],
        );
    }

    public function liveQa(PromotionContext $context): array
    {
        $this->assertContext($context);
        $this->assertPublishedCohortIntegrity();
        $rows = [];
        foreach (self::EXPECTED_SOURCE_SHA256_BY_SLUG as $slug => $sourceSha256) {
            $projection = $this->readModel->find($slug, 'en');
            if (! is_array($projection)
                || ($projection['comparison_slug'] ?? null) !== $slug
                || ($projection['locale'] ?? null) !== 'en'
                || ($projection['source_package_id'] ?? null) !== MbtiComparisonEnglishPackageImporter::PACKAGE_ID
                || ($projection['source_sha256'] ?? null) !== $sourceSha256
                || ($projection['is_public'] ?? null) !== true
                || ($projection['is_indexable'] ?? null) !== false
                || ($projection['sitemap_eligible'] ?? null) !== false
                || ($projection['llms_eligible'] ?? null) !== false
                || preg_match('/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u', (string) json_encode($projection, JSON_UNESCAPED_UNICODE)) === 1) {
                throw new DomainException('mbti_comparison_live_qa_failed');
            }
            $rows[] = $projection;
        }

        return PromotionAdapterResultFactory::make(
            $context,
            0,
            count($rows),
            count($rows),
            null,
            $this->verifiedZeroBoundaryMutations(),
            ['created_count' => 0, 'updated_count' => 0, 'unchanged_count' => count($rows)],
        );
    }

    public function rollback(PromotionContext $context, string $rollbackReference): void
    {
        $this->assertContext($context);
        $snapshot = $this->snapshots->resolve(
            $context,
            $this->targets(),
            'ENPARITY-W1-MBTI-COMPARISONS',
            'before_publication',
            $rollbackReference,
        );
        $rows = data_get($snapshot->meta_json, 'rows');
        if (! is_array($rows)) {
            throw new DomainException('rollback_snapshot_rows_invalid');
        }

        DB::transaction(function () use ($rows): void {
            MbtiCrossTypeComparisonAuthority::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->where('locale', 'en')
                ->whereIn('slug', MbtiComparisonEnglishPackageImporter::EXACT_SLUGS)
                ->lockForUpdate()
                ->get();
            MbtiCrossTypeComparisonAuthority::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->where('locale', 'en')
                ->whereIn('slug', MbtiComparisonEnglishPackageImporter::EXACT_SLUGS)
                ->delete();
            if ($rows !== []) {
                DB::table('mbti_cross_type_comparison_authorities')->insert($rows);
            }
        }, 3);
    }

    private function assertContext(PromotionContext $context): void
    {
        if (! hash_equals(MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256, $context->packageSha256)
            || $context->expectedRowCount !== 7
            || realpath($context->packageDirectory) !== realpath(MbtiComparisonEnglishPackageImporter::defaultPackageDirectory())) {
            throw new DomainException('mbti_comparison_exact_package_mismatch');
        }
    }

    private function hasLegacyPublishedPackageRows(): bool
    {
        return MbtiCrossTypeComparisonAuthority::query()
            ->withoutGlobalScopes()
            ->where('source_package_id', MbtiComparisonEnglishPackageImporter::PACKAGE_ID)
            ->where('publish_status', 'published')
            ->exists();
    }

    private function assertLegacyPublishedCohortIsAdoptable(): void
    {
        $this->assertImmutableExternalW9Evidence();
        $this->assertPublishedCohortIntegrity(true);
    }

    private function assertPublishedCohortIntegrity(bool $requireLegacyReviewStatus = false): void
    {
        $rows = MbtiCrossTypeComparisonAuthority::query()
            ->withoutGlobalScopes()
            ->where('source_package_id', MbtiComparisonEnglishPackageImporter::PACKAGE_ID)
            ->orderBy('slug')
            ->get();

        if ($rows->count() !== count(self::EXPECTED_SOURCE_SHA256_BY_SLUG)) {
            throw new DomainException('mbti_comparison_adoption_cohort_mismatch');
        }
        foreach ($rows as $row) {
            if (! $row instanceof MbtiCrossTypeComparisonAuthority
                || (int) $row->org_id !== 0
                || (string) $row->locale !== 'en'
                || (string) $row->comparison_type !== MbtiCrossTypeComparisonAuthority::COMPARISON_TYPE
                || ! array_key_exists((string) $row->slug, self::EXPECTED_SOURCE_SHA256_BY_SLUG)
                || ! hash_equals(self::EXPECTED_SOURCE_SHA256_BY_SLUG[(string) $row->slug], (string) $row->source_sha256)
                || ($requireLegacyReviewStatus
                    ? (string) $row->review_status !== 'operator_approved_published'
                    : ! in_array((string) $row->review_status, ['operator_approved_published', 'automation_published'], true))
                || (string) $row->publish_status !== 'published'
                || ! (bool) $row->is_public
                || (bool) $row->is_indexable
                || (bool) $row->sitemap_eligible
                || (bool) $row->llms_eligible
                || (bool) $row->search_submission_eligible
                || (string) $row->indexability_status !== 'blocked'
                || $row->published_at === null
                || preg_match('/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u', (string) json_encode($row->content_payload_json, JSON_UNESCAPED_UNICODE)) === 1) {
                throw new DomainException('mbti_comparison_adoption_cohort_mismatch');
            }
        }
    }

    private function assertImmutableExternalW9Evidence(): void
    {
        $root = base_path('content_assets/en-content-parity/W9/mbti-comparisons/deecc817-renderer-2f388c32');
        $envelopePath = $root.'/external_evidence_envelope.json';
        $reportPath = $root.'/independent_qa_report.json';
        $resolvedRoot = realpath($root);
        $resolvedEnvelope = realpath($envelopePath);
        $resolvedReport = realpath($reportPath);
        if ($resolvedRoot === false
            || ! is_file($envelopePath) || is_link($envelopePath) || $resolvedEnvelope === false || dirname($resolvedEnvelope) !== $resolvedRoot
            || ! is_file($reportPath) || is_link($reportPath) || $resolvedReport === false || dirname($resolvedReport) !== $resolvedRoot) {
            throw new DomainException('mbti_comparison_w9_evidence_incomplete');
        }
        $envelopeBytes = File::get($envelopePath);
        $reportBytes = File::get($reportPath);
        if (! hash_equals(self::EXTERNAL_W9_ENVELOPE_SHA256, hash('sha256', $envelopeBytes))
            || ! hash_equals(self::INDEPENDENT_W9_REPORT_SHA256, hash('sha256', $reportBytes))) {
            throw new DomainException('mbti_comparison_w9_evidence_incomplete');
        }
        try {
            $envelope = json_decode($envelopeBytes, true, 512, JSON_THROW_ON_ERROR);
            $report = json_decode($reportBytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DomainException('mbti_comparison_w9_evidence_incomplete');
        }
        if (! is_array($envelope) || ! is_array($report)
            || ($envelope['schema_version'] ?? null) !== 'fermatmind.content_promotion.external_w9_evidence_envelope.v1'
            || ($envelope['source_repository'] ?? null) !== 'fermatmind/fap-web'
            || ($envelope['source_commit'] ?? null) !== self::EXTERNAL_W9_SOURCE_COMMIT
            || ($envelope['source_path'] ?? null) !== self::EXTERNAL_W9_SOURCE_PATH
            || ($envelope['report_sha256'] ?? null) !== self::INDEPENDENT_W9_REPORT_SHA256
            || ($envelope['package_sha256'] ?? null) !== MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256
            || ($envelope['producer_lane_id'] ?? null) !== 'W1'
            || ($envelope['subscope_id'] ?? null) !== 'W1-MBTI-COMPARISONS'
            || ($envelope['reviewed_row_count'] ?? null) !== 7
            || ($envelope['verdict'] ?? null) !== 'PASS'
            || ($report['schema_version'] ?? null) !== 'fermatmind.en_content_parity_independent_qa_report.v1'
            || ($report['artifact_kind'] ?? null) !== 'independent_qa_report'
            || ($report['qa_lane_id'] ?? null) !== 'W9'
            || ($report['producer_lane_id'] ?? null) !== 'W1'
            || ($report['subscope_id'] ?? null) !== 'W1-MBTI-COMPARISONS'
            || ($report['package_sha256'] ?? null) !== MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256
            || ($report['reviewed_row_count'] ?? null) !== 7
            || ($report['verdict'] ?? null) !== 'PASS') {
            throw new DomainException('mbti_comparison_w9_evidence_incomplete');
        }
    }

    /** @return array<string, mixed> */
    private function adoptionResult(PromotionContext $context, int $publishedCount): array
    {
        return PromotionAdapterResultFactory::make(
            $context,
            0,
            $context->expectedRowCount,
            $publishedCount,
            null,
            $this->verifiedZeroBoundaryMutations(),
            ['created_count' => 0, 'updated_count' => 0, 'unchanged_count' => $context->expectedRowCount],
        );
    }

    /** @return array<string, mixed> */
    private function automationContext(PromotionContext $context): array
    {
        return [
            'schema_version' => 'fermatmind.content_promotion_automation_context.v2',
            'lane' => $context->lane,
            'subscope' => $context->subscope,
            'package_sha256' => $context->packageSha256,
            'source_repository' => 'fermatmind/fap-api',
            'source_commit' => $context->sourceCommit,
            'executor_release_sha256' => $context->executorReleaseSha256,
            'release_policy_sha256' => $context->releasePolicySha256,
            'workflow_run_id' => $context->workflowRunId,
            'workflow_run_attempt' => $context->workflowRunAttempt,
            'workflow_signature' => $context->workflowSignature,
            'expected_row_count' => $context->expectedRowCount,
            'idempotency_key' => $context->idempotencyKey,
            'cms_draft_import_authorized' => true,
            'public_publish_authorized' => true,
            'indexability_authorized' => false,
            'sitemap_authorized' => false,
            'llms_authorized' => false,
            'search_submission_authorized' => false,
            'deploy_authorized' => false,
        ];
    }

    private function captureRollbackSnapshot(PromotionContext $context, string $reason): string
    {
        $rows = MbtiCrossTypeComparisonAuthority::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', 'en')
            ->whereIn('slug', MbtiComparisonEnglishPackageImporter::EXACT_SLUGS)
            ->orderBy('slug')
            ->get()
            ->map(static fn (MbtiCrossTypeComparisonAuthority $row): array => $row->getAttributes())
            ->all();

        return $this->snapshots->capture(
            $context,
            $this->targets(),
            'ENPARITY-W1-MBTI-COMPARISONS',
            $reason,
            $rows,
            array_map(static fn (array $row): array => ['locale' => (string) $row['locale'], 'org_id' => (int) $row['org_id'], 'slug' => (string) $row['slug']], $rows),
        );
    }

    private function targets(): PromotionTargetSet
    {
        return PromotionTargetSet::fromIdentities(array_map(
            static fn (string $slug): array => ['locale' => 'en', 'org_id' => 0, 'slug' => $slug],
            MbtiComparisonEnglishPackageImporter::EXACT_SLUGS,
        ));
    }

    /** @return array<string, int> */
    private function verifiedZeroBoundaryMutations(): array
    {
        return [
            'indexability_mutation_count' => 0,
            'sitemap_mutation_count' => 0,
            'llms_mutation_count' => 0,
            'search_mutation_count' => 0,
            'deploy_mutation_count' => 0,
        ];
    }
}
