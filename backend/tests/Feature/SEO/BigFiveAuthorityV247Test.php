<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\ContentPage;
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

    public function test_database_preflight_rejects_publicly_readable_primary_create_content_page_without_published_revision(): void
    {
        DB::table('content_pages')->insert([
            'org_id' => 0,
            'slug' => 'methodology',
            'path' => '/en/personality/big-five/methodology',
            'kind' => ContentPage::KIND_POLICY,
            'page_type' => 'methodology',
            'title' => 'Unexpected public primary-create page',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => 'en',
            'status' => ContentPage::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => false,
            'publish_allowed' => true,
            'review_state' => 'approved',
            'legal_review_required' => false,
            'science_review_required' => false,
            'claim_gate_status' => 'passed',
            'forbidden_claims' => '[]',
            'operator_approval_required' => false,
            'schema_enabled' => false,
            'published_revision_id' => null,
            'published_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);

        $this->assertFalse($result['ok']);
        $this->assertContains('new_identity_already_publicly_readable', $result['issue_codes']);
        $observed = collect($result['observed_runtime'])->firstWhere('asset_id', 'technical_trust:en:/en/personality/big-five/methodology');
        $this->assertIsArray($observed);
        $this->assertTrue($observed['primary_publicly_readable']);
        $this->assertNull($observed['published_revision_id']);
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

    public function test_package_only_rejects_source_lineage_drift_even_when_artifact_locks_are_regenerated(): void
    {
        $review = $this->readJson(self::REVIEW);
        foreach ($review['rows'] as &$row) {
            if ($row['action_contract']['product_shell_preserved'] === true) {
                $row['source_hash'] = str_repeat('0', 64);
                break;
            }
        }
        unset($row);
        [$reviewPath, $reviewSha] = $this->writeTemporaryJson('pr47-review-source-drift.json', $review);

        $rollback = $this->readJson(self::ROLLBACK);
        $rollback['review_manifest_sha256'] = $reviewSha;
        [$rollbackPath, $rollbackSha] = $this->writeTemporaryJson('pr47-rollback-source-drift.json', $rollback);

        $authorization = $this->readJson(self::AUTHORIZATION);
        $authorization['review_manifest_sha256'] = $reviewSha;
        $authorization['rollback_plan_sha256'] = $rollbackSha;
        [$authorizationPath] = $this->writeTemporaryJson('pr47-authorization-source-drift.json', $authorization);

        try {
            $this->preflight()->packageOnly($reviewPath, $authorizationPath, $rollbackPath);
            $this->fail('Expected source lineage drift to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('source/package authority mismatch', $exception->getMessage());
        } finally {
            File::delete([$reviewPath, $rollbackPath, $authorizationPath]);
        }
    }

    public function test_package_only_rejects_executable_authorization_packet(): void
    {
        $authorization = $this->readJson(self::AUTHORIZATION);
        $authorization['production_promotion_currently_authorized'] = true;
        $authorization['approval_phrases_currently_executable'] = true;
        $authorization['deployed_sha'] = str_repeat('a', 40);
        $authorization['promotion_preflight_fingerprint'] = str_repeat('b', 64);
        $authorization['cohorts'][0]['authorized'] = true;
        $authorization['cohorts'][0]['exact_authorization'] = 'manually-made-executable';
        [$authorizationPath] = $this->writeTemporaryJson('pr47-authorization-executable.json', $authorization);

        try {
            $this->preflight()->packageOnly(self::REVIEW, $authorizationPath, self::ROLLBACK);
            $this->fail('Expected executable package-only authorization to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('must remain pending and non-executable', $exception->getMessage());
        } finally {
            File::delete($authorizationPath);
        }
    }

    public function test_package_only_rejects_duplicate_and_incomplete_pending_cohort_coverage(): void
    {
        $authorization = $this->readJson(self::AUTHORIZATION);
        $authorization['cohorts'][1] = $authorization['cohorts'][0];
        [$authorizationPath] = $this->writeTemporaryJson('pr47-authorization-duplicate-cohort.json', $authorization);

        try {
            $this->preflight()->packageOnly(self::REVIEW, $authorizationPath, self::ROLLBACK);
            $this->fail('Expected duplicate pending cohort coverage to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cohort identity coverage mismatch', $exception->getMessage());
        } finally {
            File::delete($authorizationPath);
        }
    }

    public function test_package_only_rejects_duplicate_review_cohort_ids_with_regenerated_artifact_locks(): void
    {
        $review = $this->readJson(self::REVIEW);
        $review['cohorts'][1]['cohort_id'] = $review['cohorts'][0]['cohort_id'];
        [$reviewPath, $reviewSha] = $this->writeTemporaryJson('pr47-review-duplicate-cohort.json', $review);

        $rollback = $this->readJson(self::ROLLBACK);
        $rollback['review_manifest_sha256'] = $reviewSha;
        [$rollbackPath, $rollbackSha] = $this->writeTemporaryJson('pr47-rollback-duplicate-review-cohort.json', $rollback);

        $authorization = $this->readJson(self::AUTHORIZATION);
        $authorization['review_manifest_sha256'] = $reviewSha;
        $authorization['rollback_plan_sha256'] = $rollbackSha;
        [$authorizationPath] = $this->writeTemporaryJson('pr47-authorization-duplicate-review-cohort.json', $authorization);

        try {
            $this->preflight()->packageOnly($reviewPath, $authorizationPath, $rollbackPath);
            $this->fail('Expected duplicate review cohort ids to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Review manifest cohort contract mismatch', $exception->getMessage());
        } finally {
            File::delete([$reviewPath, $rollbackPath, $authorizationPath]);
        }
    }

    public function test_package_only_rejects_review_state_drift_even_when_artifact_locks_are_regenerated(): void
    {
        $review = $this->readJson(self::REVIEW);
        $review['rows'][0]['manual_review']['status'] = 'approved';
        $review['rows'][0]['manual_review']['reviewer_id'] = 1;
        $review['rows'][0]['manual_review']['reviewed_at'] = '2026-07-15T00:00:00Z';
        $review['rows'][0]['manual_review']['review_record_sha256'] = str_repeat('a', 64);
        [$reviewPath, $reviewSha] = $this->writeTemporaryJson('pr47-review-state-drift.json', $review);

        $rollback = $this->readJson(self::ROLLBACK);
        $rollback['review_manifest_sha256'] = $reviewSha;
        [$rollbackPath, $rollbackSha] = $this->writeTemporaryJson('pr47-rollback-review-state-drift.json', $rollback);

        $authorization = $this->readJson(self::AUTHORIZATION);
        $authorization['review_manifest_sha256'] = $reviewSha;
        $authorization['rollback_plan_sha256'] = $rollbackSha;
        [$authorizationPath] = $this->writeTemporaryJson('pr47-authorization-review-state-drift.json', $authorization);

        try {
            $this->preflight()->packageOnly($reviewPath, $authorizationPath, $rollbackPath);
            $this->fail('Expected review state drift to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('review and rollback artifacts must remain pending', $exception->getMessage());
        } finally {
            File::delete([$reviewPath, $rollbackPath, $authorizationPath]);
        }
    }

    public function test_package_only_rejects_bound_rollback_state_with_regenerated_authorization_lock(): void
    {
        $rollback = $this->readJson(self::ROLLBACK);
        $rollback['rows'][0]['exact_target_bound'] = true;
        $rollback['rows'][0]['primary_id'] = 1;
        [$rollbackPath, $rollbackSha] = $this->writeTemporaryJson('pr47-rollback-bound.json', $rollback);

        $authorization = $this->readJson(self::AUTHORIZATION);
        $authorization['rollback_plan_sha256'] = $rollbackSha;
        [$authorizationPath] = $this->writeTemporaryJson('pr47-authorization-rollback-bound.json', $authorization);

        try {
            $this->preflight()->packageOnly(self::REVIEW, $authorizationPath, $rollbackPath);
            $this->fail('Expected bound rollback state to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('review and rollback artifacts must remain pending', $exception->getMessage());
        } finally {
            File::delete([$rollbackPath, $authorizationPath]);
        }
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

    public function test_database_authorization_requires_both_global_and_executable_approval_flags(): void
    {
        $authorization = $this->readJson(self::AUTHORIZATION);
        $this->assertFalse($this->preflight()->authorizationPacketIsExecutable($authorization));

        $authorization['production_promotion_currently_authorized'] = true;
        $this->assertFalse($this->preflight()->authorizationPacketIsExecutable($authorization));

        $authorization['approval_phrases_currently_executable'] = true;
        $this->assertTrue($this->preflight()->authorizationPacketIsExecutable($authorization));
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

    /** @param array<string,mixed> $payload @return array{0:string,1:string} */
    private function writeTemporaryJson(string $name, array $payload): array
    {
        $path = storage_path('framework/testing/'.$name);
        $raw = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $raw);

        return [$path, hash('sha256', $raw)];
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
