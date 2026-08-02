<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\MbtiCrossTypeComparisonAuthority;
use App\Services\ContentImport\MbtiComparisonEnglishPackageImporter;
use App\Services\ContentPromotion\PromotionAdapterRegistry;
use App\Services\ContentPromotion\PromotionContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\ContentPromotion\Concerns\AssertsExactPackagePromotionConformance;

require_once __DIR__.'/Concerns/AssertsExactPackagePromotionConformance.php';

final class MbtiComparisonEnglishPromotionAdapterTest extends TestCase
{
    use AssertsExactPackagePromotionConformance;
    use RefreshDatabase;

    public function test_exact_legacy_published_cohort_is_adopted_without_any_mutation_and_replays_stably(): void
    {
        $this->seedAdoptableCohort();
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W1', 'mbti-comparisons');
        $context = $this->context();
        $timestamps = $this->timestamps();

        $this->assertExactPhaseResult($adapter->preflight($context), $context, 'preflight');
        $draft = $adapter->draftImport($context);
        self::assertSame(0, $draft['written_count']);
        self::assertSame(0, $draft['published_count']);
        self::assertSame(7, $draft['unchanged_count']);
        self::assertNull($draft['rollback_reference']);
        $publish = $adapter->publish($context);
        self::assertSame(0, $publish['written_count']);
        self::assertSame(7, $publish['published_count']);
        self::assertSame(7, $publish['unchanged_count']);
        self::assertNull($publish['rollback_reference']);
        $this->assertExactPhaseResult($adapter->liveQa($context), $context, 'live-qa');

        self::assertSame($draft, $adapter->draftImport($context));
        self::assertSame($publish, $adapter->publish($context));
        self::assertSame($timestamps, $this->timestamps());
    }

    public function test_adoption_fails_closed_for_missing_extra_or_mixed_published_rows(): void
    {
        $this->seedAdoptableCohort();
        MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()
            ->where('slug', MbtiComparisonEnglishPackageImporter::EXACT_SLUGS[0])->delete();
        $this->assertAdoptionFails();

        $this->seedAdoptableCohort();
        $this->createRow('extra-vs-row', hash('sha256', 'extra'));
        $this->assertAdoptionFails();

        $this->seedAdoptableCohort();
        MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()
            ->where('slug', MbtiComparisonEnglishPackageImporter::EXACT_SLUGS[0])
            ->update(['publish_status' => 'draft', 'is_public' => false]);
        $this->assertAdoptionFails();
    }

    public function test_adoption_fails_closed_for_package_source_sha_locale_chinese_or_discoverability_drift(): void
    {
        $this->seedAdoptableCohort();
        MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()
            ->where('slug', MbtiComparisonEnglishPackageImporter::EXACT_SLUGS[0])
            ->update(['source_sha256' => str_repeat('0', 64)]);
        $this->assertAdoptionFails();

        $this->seedAdoptableCohort();
        MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()
            ->where('slug', MbtiComparisonEnglishPackageImporter::EXACT_SLUGS[0])
            ->update(['source_package_id' => 'drifted-package']);
        $this->assertAdoptionFails();

        $this->seedAdoptableCohort();
        MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()
            ->where('slug', MbtiComparisonEnglishPackageImporter::EXACT_SLUGS[0])
            ->update(['locale' => 'zh-CN']);
        $this->assertAdoptionFails();

        $this->seedAdoptableCohort();
        $row = MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()
            ->where('slug', MbtiComparisonEnglishPackageImporter::EXACT_SLUGS[0])->firstOrFail();
        $row->content_payload_json = ['title' => '中文泄漏'];
        $row->save();
        $this->assertAdoptionFails();

        $this->seedAdoptableCohort();
        MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()
            ->where('slug', MbtiComparisonEnglishPackageImporter::EXACT_SLUGS[0])
            ->update(['is_indexable' => true]);
        $this->assertAdoptionFails();
    }

    public function test_package_context_drift_is_rejected_before_adoption(): void
    {
        $this->seedAdoptableCohort();
        $context = new PromotionContext(
            packageDirectory: MbtiComparisonEnglishPackageImporter::defaultPackageDirectory(),
            packageSha256: str_repeat('0', 64),
            lane: 'W1',
            subscope: 'mbti-comparisons',
            sourceCommit: str_repeat('a', 40),
            executorReleaseSha256: str_repeat('b', 64),
            releasePolicySha256: str_repeat('c', 64),
            workflowRunId: '123',
            workflowRunAttempt: 1,
            workflowSignature: str_repeat('d', 64),
            expectedRowCount: 7,
            idempotencyKey: str_repeat('e', 64),
        );

        try {
            app(PromotionAdapterRegistry::class)->resolve('W1', 'mbti-comparisons')->draftImport($context);
            self::fail('Package drift must fail before adoption.');
        } catch (DomainException $exception) {
            self::assertSame('mbti_comparison_exact_package_mismatch', $exception->getMessage());
        }
    }

    private function assertAdoptionFails(): void
    {
        try {
            app(PromotionAdapterRegistry::class)->resolve('W1', 'mbti-comparisons')->draftImport($this->context());
            self::fail('The published cohort drift must fail closed.');
        } catch (DomainException $exception) {
            self::assertSame('mbti_comparison_adoption_cohort_mismatch', $exception->getMessage());
        }
    }

    private function seedAdoptableCohort(): void
    {
        MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->delete();
        foreach ($this->sourceShas() as $slug => $sourceSha) {
            $this->createRow($slug, $sourceSha);
        }
    }

    private function createRow(string $slug, string $sourceSha): void
    {
        [$left, $right] = explode('-vs-', $slug, 2);
        MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'locale' => 'en',
            'slug' => $slug,
            'comparison_type' => MbtiCrossTypeComparisonAuthority::COMPARISON_TYPE,
            'left_type_code' => strtoupper($left),
            'right_type_code' => strtoupper($right),
            'title' => $slug,
            'seo_title' => $slug,
            'seo_description' => $slug,
            'summary' => $slug,
            'content_payload_json' => ['comparison_slug' => $slug, 'sections' => [['title' => 'English', 'body' => ['English only']]]],
            'claim_boundary' => 'informational only',
            'source_package_id' => MbtiComparisonEnglishPackageImporter::PACKAGE_ID,
            'source_sha256' => $sourceSha,
            'review_status' => 'operator_approved_published',
            'publish_status' => 'published',
            'indexability_status' => 'blocked',
            'is_public' => true,
            'is_indexable' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'search_submission_eligible' => false,
            'published_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    private function sourceShas(): array
    {
        return [
            'enfp-vs-entp' => '64dbdc436327232d5152080f02b8142ceb7cde4e7658c61c88e730593135121a',
            'entj-vs-intj' => '6f1512fa1778a5bee3100188943439bac7fb6fe9a294257848b42e1051a9bea9',
            'estj-vs-entj' => 'ae1c150b66ef50e0d8494f22ae0535940f6a5dbba22262d6e3525d6998a5ec4b',
            'infj-vs-infp' => 'e2c426ffed25ac9b6780494ce35a626f1a1566dd6a4d0e050a577a86fddf256a',
            'intj-vs-intp' => 'cdd6ff50c8246b3c1271b056dfa2559ca65c7521fd53170b00167bd342136275',
            'isfp-vs-infp' => '963d82928f858a712799d7c9cc9ae8f6c86f24a5ece2e8850757b12abccb88d8',
            'istj-vs-isfj' => 'c1d4707c1e4826bf3ded3484c22909cbe866adc8a89efba726422cd2da027b18',
        ];
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    private function timestamps(): array
    {
        return MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()
            ->orderBy('slug')->get()
            ->mapWithKeys(static fn (MbtiCrossTypeComparisonAuthority $row): array => [
                $row->slug => [$row->updated_at?->toJSON(), $row->published_at?->toJSON()],
            ])->all();
    }

    private function context(): PromotionContext
    {
        return new PromotionContext(
            packageDirectory: MbtiComparisonEnglishPackageImporter::defaultPackageDirectory(),
            packageSha256: MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256,
            lane: 'W1',
            subscope: 'mbti-comparisons',
            sourceCommit: str_repeat('a', 40),
            executorReleaseSha256: str_repeat('b', 64),
            releasePolicySha256: str_repeat('c', 64),
            workflowRunId: '123',
            workflowRunAttempt: 1,
            workflowSignature: str_repeat('d', 64),
            expectedRowCount: 7,
            idempotencyKey: str_repeat('e', 64),
        );
    }
}
