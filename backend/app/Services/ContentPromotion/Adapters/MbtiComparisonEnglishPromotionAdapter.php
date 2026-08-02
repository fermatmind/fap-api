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

final class MbtiComparisonEnglishPromotionAdapter implements ExactPackagePromotionAdapter
{
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

        return PromotionAdapterResultFactory::make($context, 0, $count, 0, null);
    }

    public function draftImport(PromotionContext $context): array
    {
        $this->assertContext($context);
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
        );
    }

    public function publish(PromotionContext $context): array
    {
        $this->assertContext($context);
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
        );
    }

    public function liveQa(PromotionContext $context): array
    {
        $this->assertContext($context);
        $rows = [];
        foreach (MbtiComparisonEnglishPackageImporter::EXACT_SLUGS as $slug) {
            $projection = $this->readModel->find($slug, 'en');
            if (! is_array($projection)
                || ($projection['comparison_slug'] ?? null) !== $slug
                || ($projection['locale'] ?? null) !== 'en'
                || ($projection['is_public'] ?? null) !== true
                || ($projection['is_indexable'] ?? null) !== false
                || ($projection['sitemap_eligible'] ?? null) !== false
                || ($projection['llms_eligible'] ?? null) !== false
                || preg_match('/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u', (string) json_encode($projection, JSON_UNESCAPED_UNICODE)) === 1) {
                throw new DomainException('mbti_comparison_live_qa_failed');
            }
            $rows[] = $projection;
        }

        return PromotionAdapterResultFactory::make($context, 0, count($rows), count($rows), null);
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
        );
    }

    private function targets(): PromotionTargetSet
    {
        return PromotionTargetSet::fromIdentities(array_map(
            static fn (string $slug): array => ['locale' => 'en', 'org_id' => 0, 'slug' => $slug],
            MbtiComparisonEnglishPackageImporter::EXACT_SLUGS,
        ));
    }
}
