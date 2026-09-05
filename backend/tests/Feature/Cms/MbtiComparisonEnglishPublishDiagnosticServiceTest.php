<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Models\MbtiCrossTypeComparisonAuthority;
use App\Services\Cms\MbtiComparisonEnglishPublishDiagnosticService;
use App\Services\Cms\MbtiComparisonEnglishPublishService;
use App\Services\ContentImport\MbtiComparisonEnglishPackageImporter;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class MbtiComparisonEnglishPublishDiagnosticServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_the_complete_exact_english_draft_cohort_without_writing(): void
    {
        $this->seedExactDrafts();

        $receipt = $this->diagnose();

        self::assertSame('PASS', $receipt['status']);
        self::assertTrue($receipt['read_only']);
        self::assertSame('draft_exact_english_comparison', $receipt['authority_state']);
        self::assertSame(7, $receipt['row_count']);
        self::assertSame(0, $receipt['runtime_projection']['visible_row_count']);
        self::assertFalse($receipt['cms_write_attempted']);
        self::assertFalse($receipt['publish_attempted']);
        self::assertSame(0, $this->publishedExactCount());
    }

    public function test_it_reports_the_complete_exact_published_cohort_with_all_discoverability_gates_held(): void
    {
        $this->seedExactDrafts();
        app(MbtiComparisonEnglishPublishService::class)->publish(
            MbtiComparisonEnglishPublishService::defaultApprovalPath(),
            MbtiComparisonEnglishPublishService::APPROVAL_SHA256,
        );

        $receipt = $this->diagnose();

        self::assertSame('published_exact_english_comparison', $receipt['authority_state']);
        self::assertSame(7, $receipt['runtime_projection']['visible_row_count']);
        self::assertSame('noindex,follow', $receipt['runtime_projection']['meta_robots']);
        self::assertTrue($receipt['runtime_projection']['sitemap_excluded']);
        self::assertTrue($receipt['runtime_projection']['llms_withheld']);
        self::assertTrue($receipt['runtime_projection']['search_submission_held']);
        self::assertFalse($receipt['publish_attempted']);
    }

    public function test_it_fails_closed_for_mixed_lifecycle_state_or_source_hash_drift(): void
    {
        $this->seedExactDrafts();
        $first = $this->exactAuthority(MbtiComparisonEnglishPackageImporter::EXACT_SLUGS[0]);
        $first->forceFill([
            'review_status' => 'operator_approved_published',
            'publish_status' => 'published',
            'is_public' => true,
            'published_at' => now(),
        ])->save();

        $this->expectDiagnosticFailure('diagnostic_mixed_authority_state');

        $this->diagnose();
    }

    public function test_it_fails_closed_for_source_hash_or_discoverability_drift(): void
    {
        $this->seedExactDrafts();
        $first = $this->exactAuthority(MbtiComparisonEnglishPackageImporter::EXACT_SLUGS[0]);
        $first->forceFill(['source_sha256' => str_repeat('0', 64)])->save();

        $this->expectDiagnosticFailure('target_package_or_source_sha256_mismatch');

        $this->diagnose();
    }

    public function test_it_fails_closed_when_a_discoverability_gate_is_open(): void
    {
        $this->seedExactDrafts();
        $first = $this->exactAuthority(MbtiComparisonEnglishPackageImporter::EXACT_SLUGS[0]);
        $first->forceFill(['is_indexable' => true])->save();

        $this->expectDiagnosticFailure('discoverability_boundary_open');

        $this->diagnose();
    }

    public function test_it_fails_closed_for_an_extra_english_slug_in_the_frozen_package(): void
    {
        $this->seedExactDrafts();
        $extra = $this->exactAuthority(MbtiComparisonEnglishPackageImporter::EXACT_SLUGS[0])->replicate();
        $extra->forceFill([
            'slug' => 'extra-english-comparison',
            'publish_status' => 'draft',
            'review_status' => 'w9_passed_pending_editorial',
            'is_public' => false,
            'published_at' => null,
        ])->save();

        $this->expectDiagnosticFailure('extra_english_comparison_in_frozen_package');

        $this->diagnose();
    }

    public function test_the_diagnostic_approval_executor_and_workflow_remain_read_only_and_use_runner_side_receipts(): void
    {
        $approvalPath = MbtiComparisonEnglishPublishDiagnosticService::defaultApprovalPath();
        $approvalBytes = (string) File::get($approvalPath);
        $approval = json_decode($approvalBytes, true, 512, JSON_THROW_ON_ERROR);
        $executor = (string) File::get(base_path('scripts/mbti_comparison_english_publish_diagnostic.php'));
        self::assertFileDoesNotExist(base_path('../.github/workflows/mbti-comparison-english-publish-diagnostic.yml'));
        self::assertFileDoesNotExist(base_path('../.github/workflows/mbti-comparison-english-publish-live-qa.yml'));

        self::assertSame(MbtiComparisonEnglishPublishDiagnosticService::APPROVAL_SHA256, hash('sha256', $approvalBytes));
        self::assertSame('publish_diagnostic_readback', $approval['gate']);
        self::assertTrue($approval['permissions']['target_authority_readback_authorized']);
        foreach (array_diff(array_keys($approval['permissions']), ['target_authority_readback_authorized']) as $permission) {
            self::assertFalse($approval['permissions'][$permission], $permission.' must remain closed.');
        }
        self::assertStringContainsString("const REQUIRED_ACTIVE_REVISION = '660280d00a57e58bd8bc76608e19de2492c03f53'", $executor);
        self::assertStringContainsString('controlled_read_only_publish_diagnostic', (string) File::get(base_path('app/Services/Cms/MbtiComparisonEnglishPublishDiagnosticService.php')));
    }

    /** @return array<string, mixed> */
    private function diagnose(): array
    {
        return app(MbtiComparisonEnglishPublishDiagnosticService::class)->diagnose(
            MbtiComparisonEnglishPublishDiagnosticService::defaultApprovalPath(),
            MbtiComparisonEnglishPublishDiagnosticService::APPROVAL_SHA256,
        );
    }

    private function seedExactDrafts(): void
    {
        app(MbtiComparisonEnglishPackageImporter::class)->importDraft(
            MbtiComparisonEnglishPackageImporter::defaultPackageDirectory(),
            MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256,
            MbtiComparisonEnglishPackageImporter::defaultApprovalPath(),
            MbtiComparisonEnglishPackageImporter::APPROVAL_SHA256,
        );
    }

    private function exactAuthority(string $slug): MbtiCrossTypeComparisonAuthority
    {
        return MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', 'en')
            ->where('slug', $slug)
            ->firstOrFail();
    }

    private function expectDiagnosticFailure(string $code): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage($code.':');
    }

    private function publishedExactCount(): int
    {
        return MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', 'en')
            ->whereIn('slug', MbtiComparisonEnglishPackageImporter::EXACT_SLUGS)
            ->where('publish_status', 'published')
            ->count();
    }
}
