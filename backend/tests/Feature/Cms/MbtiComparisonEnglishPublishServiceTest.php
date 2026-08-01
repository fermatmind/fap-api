<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Models\MbtiCrossTypeComparisonAuthority;
use App\Services\Cms\MbtiComparisonEnglishPublishService;
use App\Services\ContentImport\MbtiComparisonEnglishPackageImporter;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class MbtiComparisonEnglishPublishServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_publishes_only_the_exact_seven_english_drafts_and_holds_every_discoverability_gate(): void
    {
        $this->seedExactDrafts();
        $unrelated = $this->createAuthority('other-english-comparison', 'en', 'other-package');

        $receipt = app(MbtiComparisonEnglishPublishService::class)->publish(
            MbtiComparisonEnglishPublishService::defaultApprovalPath(),
            MbtiComparisonEnglishPublishService::APPROVAL_SHA256,
        );

        self::assertSame('PASS', $receipt['status']);
        self::assertSame(7, $receipt['row_count']);
        self::assertTrue($receipt['writes_committed']);
        self::assertTrue($receipt['publish_attempted']);
        self::assertFalse($receipt['activation_attempted']);
        self::assertFalse($receipt['active_pointer_changed']);
        self::assertFalse($receipt['indexability_attempted']);
        self::assertSame('noindex,follow', $receipt['readback']['meta_robots']);

        foreach (MbtiComparisonEnglishPublishService::exactSlugs() as $slug) {
            $row = MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()
                ->where('org_id', 0)->where('locale', 'en')->where('slug', $slug)->firstOrFail();
            self::assertSame('operator_approved_published', $row->review_status);
            self::assertSame('published', $row->publish_status);
            self::assertTrue($row->is_public);
            self::assertFalse($row->is_indexable);
            self::assertFalse($row->sitemap_eligible);
            self::assertFalse($row->llms_eligible);
            self::assertFalse($row->search_submission_eligible);
            self::assertNotNull($row->published_at);
        }

        $unrelated->refresh();
        self::assertSame('draft', $unrelated->publish_status);
        self::assertFalse($unrelated->is_public);
    }

    public function test_it_is_idempotent_after_the_exact_publish_has_committed(): void
    {
        $this->seedExactDrafts();
        $publisher = app(MbtiComparisonEnglishPublishService::class);
        $publisher->publish(MbtiComparisonEnglishPublishService::defaultApprovalPath(), MbtiComparisonEnglishPublishService::APPROVAL_SHA256);
        $receipt = $publisher->publish(MbtiComparisonEnglishPublishService::defaultApprovalPath(), MbtiComparisonEnglishPublishService::APPROVAL_SHA256);

        self::assertFalse($receipt['writes_committed']);
        self::assertSame(
            array_fill(0, 7, 'preserved_exact_published_english_comparison'),
            array_column($receipt['rows'], 'action'),
        );
    }

    public function test_it_fails_closed_for_wrong_approval_or_non_draft_target_without_writing_any_row(): void
    {
        $this->seedExactDrafts();
        $publisher = app(MbtiComparisonEnglishPublishService::class);

        try {
            $publisher->publish(MbtiComparisonEnglishPublishService::defaultApprovalPath(), str_repeat('0', 64));
            self::fail('Expected the wrong approval hash to fail.');
        } catch (DomainException $exception) {
            self::assertStringStartsWith('approval_sha256_mismatch:', $exception->getMessage());
        }
        self::assertSame(0, $this->publishedExactCount());

        $blocked = MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()
            ->where('org_id', 0)->where('locale', 'en')->where('slug', MbtiComparisonEnglishPublishService::exactSlugs()[0])->firstOrFail();
        $blocked->forceFill(['review_status' => 'draft'])->save();

        try {
            $publisher->publish(MbtiComparisonEnglishPublishService::defaultApprovalPath(), MbtiComparisonEnglishPublishService::APPROVAL_SHA256);
            self::fail('Expected a non-W9 draft target to fail.');
        } catch (DomainException $exception) {
            self::assertStringStartsWith('target_not_exact_english_draft:', $exception->getMessage());
        }
        self::assertSame(0, $this->publishedExactCount());
    }

    public function test_the_human_operator_artifact_and_production_executor_bind_the_exact_receipt_and_forbid_release_adjacent_actions(): void
    {
        $path = MbtiComparisonEnglishPublishService::defaultApprovalPath();
        $bytes = (string) File::get($path);
        $approval = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        $executor = (string) File::get(base_path('scripts/mbti_comparison_english_publish_live_qa.php'));
        $workflow = (string) File::get(base_path('../.github/workflows/mbti-comparison-english-publish-live-qa.yml'));

        self::assertSame(MbtiComparisonEnglishPublishService::APPROVAL_SHA256, hash('sha256', $bytes));
        self::assertSame(MbtiComparisonEnglishPublishService::TARGET_AUTHORITY_RECEIPT_SHA256, $approval['target_authority_receipt_sha256']);
        self::assertTrue($approval['permissions']['publish_authorized']);
        foreach (array_diff(array_keys($approval['permissions']), ['publish_authorized', 'target_authority_readback_authorized']) as $permission) {
            self::assertFalse($approval['permissions'][$permission], $permission.' must remain closed.');
        }
        self::assertStringContainsString("const REQUIRED_ACTIVE_REVISION = '660280d00a57e58bd8bc76608e19de2492c03f53'", $executor);
        self::assertStringContainsString("'meta_robots' => 'noindex,follow'", $executor);
        self::assertStringContainsString('comparison_english_publish_live_qa_failed:$errorCode', $executor);
        self::assertStringContainsString('ControlledReceiptWriter', $executor);
        self::assertStringContainsString('environment: production', $workflow);
        self::assertStringContainsString('mbti_comparison_english_publish_live_qa.php', $workflow);
        self::assertStringContainsString("sed -n '1p' \"\$RUN_DIR/executor.stderr\" >&2 || true", $workflow);
        self::assertStringContainsString('test -s "$receipt_path"', $workflow);
        self::assertStringContainsString('sha256sum "$receipt_path"', $workflow);
        self::assertStringNotContainsString('php artisan migrate', $workflow);
        self::assertStringNotContainsString('dep deploy', $workflow);
    }

    private function seedExactDrafts(): void
    {
        foreach (MbtiComparisonEnglishPublishService::exactSlugs() as $slug) {
            $this->createAuthority($slug, 'en', MbtiComparisonEnglishPackageImporter::PACKAGE_ID);
        }
    }

    private function createAuthority(string $slug, string $locale, string $packageId): MbtiCrossTypeComparisonAuthority
    {
        return MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'locale' => $locale,
            'slug' => $slug,
            'comparison_type' => MbtiCrossTypeComparisonAuthority::COMPARISON_TYPE,
            'left_type_code' => 'ENFP',
            'right_type_code' => 'ENTP',
            'title' => $slug,
            'seo_title' => $slug,
            'seo_description' => $slug,
            'summary' => $slug,
            'content_payload_json' => ['comparison_slug' => $slug],
            'claim_boundary' => 'informational only',
            'source_package_id' => $packageId,
            'source_sha256' => hash('sha256', $slug),
            'review_status' => 'w9_passed_pending_editorial',
            'publish_status' => 'draft',
            'indexability_status' => 'blocked',
            'is_public' => false,
            'is_indexable' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'search_submission_eligible' => false,
        ]);
    }

    private function publishedExactCount(): int
    {
        return MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()
            ->where('org_id', 0)->where('locale', 'en')
            ->whereIn('slug', MbtiComparisonEnglishPublishService::exactSlugs())
            ->where('publish_status', 'published')->count();
    }
}
