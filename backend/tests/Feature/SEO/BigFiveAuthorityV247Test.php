<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Services\BigFive\AuthorityV2\ReviewPromotion\BigFiveReviewPromotionPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

final class BigFiveAuthorityV247Test extends TestCase
{
    use RefreshDatabase;

    private const REVIEW = '../generated/big-five-authority-v2/big5-authority-v2-review-promotion-gate-47/review-manifest.json';

    private const AUTHORIZATION = '../generated/big-five-authority-v2/big5-authority-v2-review-promotion-gate-47/authorization-packet-template.json';

    private const ROLLBACK = '../generated/big-five-authority-v2/big5-authority-v2-review-promotion-gate-47/rollback-plan.json';

    public function test_package_only_preflight_locks_exact_pending_inventory_and_writes_nothing(): void
    {
        $result = $this->preflight()->packageOnly(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);

        $this->assertTrue($result['ok']);
        $this->assertSame('HOLD_FAIL_CLOSED_PENDING_REVIEW_AND_AUTHORIZATION', $result['status']);
        $this->assertSame('package_only_zero_write', $result['mode']);
        $this->assertSame([
            'assets' => 231,
            'working_revisions' => 229,
            'product_shells_preserved' => 2,
            'primary_create' => 106,
            'existing_revision' => 125,
            'cohorts' => 16,
            'manually_reviewed' => 0,
            'runtime_bound' => 0,
            'rollback_targets_bound' => 0,
            'promotion_eligible' => 0,
            'cohorts_authorized' => 0,
        ], $result['counts']);
        $this->assertSame(0, array_sum($result['actions']));
    }

    public function test_checked_in_review_rollback_and_authorization_artifacts_remain_non_executable(): void
    {
        $review = $this->readJson(self::REVIEW);
        $rollback = $this->readJson(self::ROLLBACK);
        $authorization = $this->readJson(self::AUTHORIZATION);

        $this->assertSame('HOLD_PENDING_MANUAL_REVIEW_AND_RUNTIME_BINDING', $review['status']);
        $this->assertCount(231, $review['rows']);
        $this->assertCount(16, $review['cohorts']);
        $this->assertSame(229, collect($review['rows'])->where('action_contract.revision_create', true)->count());
        $this->assertSame(2, collect($review['rows'])->where('action_contract.product_shell_preserved', true)->count());
        $this->assertSame(0, collect($review['rows'])->where('manual_review.status', 'approved')->count());
        $this->assertSame(0, collect($review['rows'])->where('permissions.media.approved', true)->count());
        $this->assertTrue(collect($review['rows'])->every(static fn (array $row): bool => $row['expected_runtime']['bound'] === false
            && collect($row['expected_runtime'])->except('bound')->every(static fn (mixed $value): bool => $value === null)));

        $this->assertSame('HOLD_PENDING_EXACT_RUNTIME_TARGETS', $rollback['status']);
        $this->assertFalse($rollback['execution_implemented']);
        $this->assertTrue(collect($rollback['rows'])->every(static fn (array $row): bool => $row['exact_target_bound'] === false));
        $this->assertFalse($authorization['production_promotion_currently_authorized']);
        $this->assertFalse($authorization['approval_phrases_currently_executable']);
        $this->assertNull($authorization['deployed_sha']);
        $this->assertNull($authorization['promotion_preflight_fingerprint']);
        $this->assertTrue(collect($authorization['cohorts'])->every(static fn (array $cohort): bool => $cohort['authorized'] === false && $cohort['exact_authorization'] === null));
    }

    public function test_database_preflight_aborts_on_missing_runtime_identities_without_mutation(): void
    {
        $before = $this->tableCounts();

        $result = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);

        $this->assertFalse($result['ok']);
        $this->assertSame('FAIL_CLOSED_ABORT_RUNTIME_MISMATCH', $result['status']);
        $this->assertContains('identity_missing', $result['issue_codes']);
        $this->assertSame(0, $result['counts']['promotion_eligible']);
        $this->assertSame(0, $result['counts']['cohorts_authorized']);
        $this->assertSame(231, $result['actions']['database_reads']);
        $this->assertSame(0, array_sum(collect($result['actions'])->except('database_reads')->all()));
        $this->assertSame($before, $this->tableCounts());
    }

    public function test_artifact_drift_fails_before_database_read_or_write(): void
    {
        $review = $this->readJson(self::REVIEW);
        $review['rows'][0]['source_hash'] = str_repeat('0', 64);
        $path = storage_path('framework/testing/pr47-review-tampered.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($review, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
        $before = $this->tableCounts();

        try {
            $this->preflight()->databasePreflight($path, self::AUTHORIZATION, self::ROLLBACK);
            $this->fail('Expected review artifact drift to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('contract mismatch', $exception->getMessage());
        } finally {
            File::delete($path);
        }

        $this->assertSame($before, $this->tableCounts());
    }

    public function test_exact_authorization_phrase_locks_deploy_artifacts_runtime_cohort_and_count(): void
    {
        $phrase = $this->preflight()->approvalPhrase(
            str_repeat('a', 40),
            str_repeat('b', 64),
            str_repeat('c', 64),
            str_repeat('d', 64),
            'cms_article_en_01',
            str_repeat('e', 64),
            25,
        );

        $this->assertStringContainsString('DEPLOY_SHA='.str_repeat('a', 40), $phrase);
        $this->assertStringContainsString('REVIEW_MANIFEST_SHA256='.str_repeat('b', 64), $phrase);
        $this->assertStringContainsString('ROLLBACK_PLAN_SHA256='.str_repeat('c', 64), $phrase);
        $this->assertStringContainsString('PREFLIGHT_FINGERPRINT='.str_repeat('d', 64), $phrase);
        $this->assertStringContainsString('COHORT_ID=cms_article_en_01', $phrase);
        $this->assertStringContainsString('COHORT_SHA256='.str_repeat('e', 64), $phrase);
        $this->assertStringContainsString('ASSET_COUNT=25; ABORT_ON_ANY_MISMATCH', $phrase);

        $this->expectException(RuntimeException::class);
        $this->preflight()->approvalPhrase('main', str_repeat('b', 64), str_repeat('c', 64), str_repeat('d', 64), 'cms_article_en_01', str_repeat('e', 64), 25);
    }

    public function test_console_package_only_is_zero_write_and_exposes_no_promotion_option(): void
    {
        $before = $this->tableCounts();

        $this->artisan('personality:big-five-authority-v2-review-promotion-preflight', [
            '--review-manifest' => self::REVIEW,
            '--authorization-packet' => self::AUTHORIZATION,
            '--rollback-plan' => self::ROLLBACK,
            '--package-only' => true,
        ])
            ->expectsOutputToContain('status=HOLD_FAIL_CLOSED_PENDING_REVIEW_AND_AUTHORIZATION')
            ->assertExitCode(0);

        $command = Artisan::all()['personality:big-five-authority-v2-review-promotion-preflight'];
        $this->assertFalse($command->getDefinition()->hasOption('write'));
        $this->assertFalse($command->getDefinition()->hasOption('promote'));
        $this->assertSame($before, $this->tableCounts());
    }

    private function preflight(): BigFiveReviewPromotionPreflight
    {
        return app(BigFiveReviewPromotionPreflight::class);
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        return json_decode(File::get(base_path($path)), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,int> */
    private function tableCounts(): array
    {
        return collect([
            'articles',
            'article_translation_revisions',
            'content_pages',
            'cms_translation_revisions',
            'landing_surfaces',
            'personality_public_content_assets',
            'personality_public_content_asset_revisions',
            'topic_profiles',
            'topic_profile_revisions',
        ])->mapWithKeys(static fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
