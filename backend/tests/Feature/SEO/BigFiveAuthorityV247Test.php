<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Models\LandingSurface;
use App\Models\PersonalityPublicContentAsset;
use App\Models\TopicProfile;
use App\Models\TopicProfileEntry;
use App\Models\TopicProfileRevision;
use App\Models\TopicProfileSection;
use App\Services\BigFive\AuthorityV2\ReleaseGate\BigFiveAuthorityV2DraftImportWriter;
use App\Services\BigFive\AuthorityV2\ReviewPromotion\BigFiveReviewPromotionPreflight;
use App\Services\Cms\ContentPageTranslationAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
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
        $this->assertContains('row_promotion_eligibility_missing', $result['blocker_codes']);
        $this->assertSame(0, $result['counts']['promotion_eligible']);
        $this->assertSame(0, $result['counts']['cohorts_authorized']);
        $this->assertSame(231, $result['actions']['database_reads']);
        $this->assertSame(0, array_sum(collect($result['actions'])->except('database_reads')->all()));
        $this->assertSame($before, $this->tableCounts());
    }

    public function test_package_only_rejects_incomplete_source_artifact_set_with_regenerated_artifact_locks(): void
    {
        $review = $this->readJson(self::REVIEW);
        array_pop($review['source_artifacts']);
        [$reviewPath, $reviewSha] = $this->writeTemporaryJson('pr47-review-missing-source-artifact.json', $review);

        $rollback = $this->readJson(self::ROLLBACK);
        $rollback['review_manifest_sha256'] = $reviewSha;
        [$rollbackPath, $rollbackSha] = $this->writeTemporaryJson('pr47-rollback-missing-source-artifact.json', $rollback);

        $authorization = $this->readJson(self::AUTHORIZATION);
        $authorization['review_manifest_sha256'] = $reviewSha;
        $authorization['rollback_plan_sha256'] = $rollbackSha;
        [$authorizationPath] = $this->writeTemporaryJson('pr47-authorization-missing-source-artifact.json', $authorization);

        try {
            $this->preflight()->packageOnly($reviewPath, $authorizationPath, $rollbackPath);
            $this->fail('Expected incomplete source artifact evidence to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('source artifact identity contract mismatch', $exception->getMessage());
        } finally {
            File::delete([$reviewPath, $rollbackPath, $authorizationPath]);
        }
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
            'canonical_path' => '/en/personality/big-five/methodology',
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

    public function test_database_preflight_rejects_future_scheduled_primary_create_content_page(): void
    {
        DB::table('content_pages')->insert([
            'org_id' => 0,
            'slug' => 'methodology',
            'path' => '/en/personality/big-five/methodology',
            'kind' => ContentPage::KIND_POLICY,
            'page_type' => 'methodology',
            'title' => 'Future scheduled primary-create page',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => 'en',
            'status' => ContentPage::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => false,
            'canonical_path' => '/en/personality/big-five/methodology',
            'published_revision_id' => null,
            'published_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);

        $this->assertFalse($result['ok']);
        $this->assertContains('new_identity_publication_state_present', $result['issue_codes']);
        $observed = collect($result['observed_runtime'])->firstWhere('asset_id', 'technical_trust:en:/en/personality/big-five/methodology');
        $this->assertIsArray($observed);
        $this->assertFalse($observed['primary_publicly_readable']);
    }

    public function test_database_preflight_rejects_soft_deleted_article_identity(): void
    {
        DB::table('articles')->insert([
            'org_id' => 0,
            'slug' => 'big-five-personality-test-vs-mbti',
            'locale' => 'en',
            'title' => 'Deleted authority identity',
            'content_md' => 'Deleted authority identity.',
            'status' => 'draft',
            'lifecycle_state' => Article::LIFECYCLE_SOFT_DELETED,
            'is_public' => false,
            'is_indexable' => false,
            'deleted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);

        $this->assertFalse($result['ok']);
        $this->assertContains('article_identity_not_live', $result['issue_codes']);
        $observed = collect($result['observed_runtime'])->firstWhere('asset_id', 'article:en:/en/articles/big-five-personality-test-vs-mbti');
        $this->assertIsArray($observed);
        $this->assertFalse($observed['primary_record_live']);
    }

    public function test_database_preflight_rejects_working_revision_owned_by_another_primary(): void
    {
        $primaryId = DB::table('content_pages')->insertGetId([
            'org_id' => 0,
            'slug' => 'methodology',
            'path' => '/en/personality/big-five/methodology',
            'kind' => ContentPage::KIND_POLICY,
            'page_type' => 'methodology',
            'title' => 'Authority identity',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => 'en',
            'status' => ContentPage::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => false,
            'canonical_path' => '/en/personality/big-five/methodology',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherPrimaryId = DB::table('content_pages')->insertGetId([
            'org_id' => 0,
            'slug' => 'other-methodology',
            'path' => '/en/personality/big-five/other-methodology',
            'kind' => ContentPage::KIND_POLICY,
            'page_type' => 'methodology',
            'title' => 'Other authority identity',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => 'en',
            'status' => ContentPage::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $row = collect($this->readJson(self::REVIEW)['rows'])
            ->firstWhere('asset_id', 'technical_trust:en:/en/personality/big-five/methodology');
        $this->assertIsArray($row);
        $revisionId = DB::table('cms_translation_revisions')->insertGetId([
            'org_id' => 0,
            'content_type' => 'content_page',
            'content_id' => $otherPrimaryId,
            'translation_group_id' => 'big-five-methodology',
            'locale' => 'en',
            'source_locale' => 'en',
            'revision_number' => 1,
            'revision_status' => 'draft',
            'payload_json' => '{}',
            'authority_asset_key' => $row['asset_id'],
            'authority_source_hash' => $row['source_hash'],
            'authority_package_sha256' => $row['authority_package_sha256'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('content_pages')->where('id', $primaryId)->update(['working_revision_id' => $revisionId]);

        $result = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);

        $this->assertFalse($result['ok']);
        $this->assertContains('working_revision_authority_mismatch', $result['issue_codes']);
        $observed = collect($result['observed_runtime'])->firstWhere('asset_id', 'technical_trust:en:/en/personality/big-five/methodology');
        $this->assertIsArray($observed);
        $this->assertFalse($observed['revision_authority_matches']);
    }

    public function test_database_preflight_rejects_working_revision_payload_drift_with_unchanged_authority_metadata(): void
    {
        $descriptor = collect(app(BigFiveAuthorityV2DraftImportWriter::class)->validatedPlan(
            '../generated/big-five-authority-v2/big5-authority-v2-release-gate-37/draft-import-package.json',
            '../generated/big-five-authority-v2/big5-authority-v2-release-gate-37/production-authorization-packet.json',
        )['descriptors'])->firstWhere('asset_id', 'technical_trust:en:/en/personality/big-five/methodology');
        $this->assertIsArray($descriptor);
        $row = collect($this->readJson(self::REVIEW)['rows'])->firstWhere('asset_id', $descriptor['asset_id']);
        $this->assertIsArray($row);

        $page = ContentPage::query()->create($descriptor['attributes']);
        $rawPayload = [
            ...$descriptor['attributes'],
            '_big_five_authority_v2_import' => [
                'asset_id' => $descriptor['asset_id'],
                'route' => $descriptor['route'],
                'source_package' => $descriptor['source_package'],
                'source_hash' => $descriptor['source_hash'],
                'authority_package_sha256' => $row['authority_package_sha256'],
                'public_runtime_mutation_allowed' => false,
            ],
        ];
        $revisionId = DB::table('cms_translation_revisions')->insertGetId([
            'org_id' => 0,
            'content_type' => 'content_page',
            'content_id' => (int) $page->id,
            'translation_group_id' => $page->translation_group_id,
            'locale' => $page->locale,
            'source_locale' => $page->locale,
            'revision_number' => 1,
            'revision_status' => CmsTranslationRevision::STATUS_APPROVED,
            'payload_json' => json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'authority_asset_key' => $row['asset_id'],
            'authority_source_package' => $row['source_package'],
            'authority_source_hash' => $row['source_hash'],
            'authority_package_sha256' => $row['authority_package_sha256'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $page->forceFill(['working_revision_id' => $revisionId])->save();
        $page->refresh();

        $raw = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);
        $rawObserved = collect($raw['observed_runtime'])->firstWhere('asset_id', $row['asset_id']);
        $this->assertIsArray($rawObserved);
        $this->assertFalse($rawObserved['revision_authority_matches']);

        $candidate = $page->replicate();
        $candidate->exists = true;
        $candidate->setAttribute($page->getKeyName(), $page->getKey());
        $candidate->forceFill($descriptor['attributes']);
        $candidate->forceFill([
            'seo_title' => $descriptor['attributes']['title'],
            'seo_description' => $descriptor['attributes']['summary'],
        ]);
        $payload = [
            ...app(ContentPageTranslationAdapter::class)->snapshotPayload($candidate),
            '_big_five_authority_v2_import' => $rawPayload['_big_five_authority_v2_import'],
        ];
        DB::table('cms_translation_revisions')->where('id', $revisionId)->update([
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);

        $before = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);
        $beforeObserved = collect($before['observed_runtime'])->firstWhere('asset_id', $row['asset_id']);
        $this->assertIsArray($beforeObserved);
        $this->assertTrue($beforeObserved['revision_authority_matches']);

        DB::table('cms_translation_revisions')->where('id', $revisionId)->update([
            'translation_group_id' => 'drifted-content-page-group',
            'updated_at' => now(),
        ]);
        $identityDrift = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);
        $identityDriftObserved = collect($identityDrift['observed_runtime'])->firstWhere('asset_id', $row['asset_id']);
        $this->assertIsArray($identityDriftObserved);
        $this->assertFalse($identityDriftObserved['revision_authority_matches']);
        DB::table('cms_translation_revisions')->where('id', $revisionId)->update([
            'translation_group_id' => $page->translation_group_id,
            'updated_at' => now(),
        ]);

        $payload['body_md'] = '# Mutated after runtime and rollback binding';
        DB::table('cms_translation_revisions')->where('id', $revisionId)->update([
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);

        $after = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);
        $afterObserved = collect($after['observed_runtime'])->firstWhere('asset_id', $row['asset_id']);
        $this->assertIsArray($afterObserved);
        $this->assertFalse($afterObserved['revision_authority_matches']);
        $this->assertContains('working_revision_authority_mismatch', $after['issue_codes']);
    }

    public function test_database_preflight_requires_approved_article_working_revision(): void
    {
        $descriptor = collect(app(BigFiveAuthorityV2DraftImportWriter::class)->validatedPlan(
            '../generated/big-five-authority-v2/big5-authority-v2-release-gate-37/draft-import-package.json',
            '../generated/big-five-authority-v2/big5-authority-v2-release-gate-37/production-authorization-packet.json',
        )['descriptors'])->firstWhere('asset_id', 'article:en:/en/articles/big-five-personality-test-vs-mbti');
        $this->assertIsArray($descriptor);
        $row = collect($this->readJson(self::REVIEW)['rows'])->firstWhere('asset_id', $descriptor['asset_id']);
        $this->assertIsArray($row);
        $attributes = $descriptor['attributes'];

        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => 'big-five-personality-test-vs-mbti',
            'locale' => 'en',
            'translation_group_id' => $attributes['translation_group_id'],
            'title' => 'Existing published authority',
            'excerpt' => 'Existing published excerpt.',
            'content_md' => '# Existing published body',
            'status' => 'published',
            'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => now()->subDay(),
        ]);
        $publishedRevision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => $attributes['translation_group_id'],
            'locale' => 'en',
            'source_locale' => 'en',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => 'Existing published authority',
            'excerpt' => 'Existing published excerpt.',
            'content_md' => '# Existing published body',
            'seo_title' => 'Existing published authority',
            'seo_description' => 'Existing published excerpt.',
            'published_at' => now()->subDay(),
        ]);
        $workingRevision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => $attributes['translation_group_id'],
            'locale' => 'en',
            'source_locale' => 'en',
            'revision_number' => 2,
            'revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            'authority_asset_key' => $row['asset_id'],
            'authority_source_package' => $row['source_package'],
            'authority_source_hash' => $row['source_hash'],
            'authority_package_sha256' => $row['authority_package_sha256'],
            'authority_metadata_json' => [
                'route' => $descriptor['route'],
                'authority_surface' => $descriptor['authority_surface'],
                'draft_attributes' => $attributes,
                'public_runtime_mutation_allowed' => false,
            ],
            'title' => $attributes['title'],
            'excerpt' => $attributes['excerpt'],
            'content_md' => $attributes['content_md'],
            'seo_title' => $attributes['title'],
            'seo_description' => $attributes['excerpt'],
        ]);
        $article->forceFill([
            'working_revision_id' => (int) $workingRevision->id,
            'published_revision_id' => (int) $publishedRevision->id,
        ])->save();

        $pending = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);
        $pendingObserved = collect($pending['observed_runtime'])->firstWhere('asset_id', $row['asset_id']);
        $this->assertIsArray($pendingObserved);
        $this->assertFalse($pendingObserved['revision_authority_matches']);

        $workingRevision->forceFill(['revision_status' => ArticleTranslationRevision::STATUS_APPROVED])->save();
        $approved = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);
        $approvedObserved = collect($approved['observed_runtime'])->firstWhere('asset_id', $row['asset_id']);
        $this->assertIsArray($approvedObserved);
        $this->assertTrue($approvedObserved['revision_authority_matches']);

        $workingRevision->forceFill(['translation_group_id' => 'drifted-article-group'])->save();
        $identityDrift = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);
        $identityDriftObserved = collect($identityDrift['observed_runtime'])->firstWhere('asset_id', $row['asset_id']);
        $this->assertIsArray($identityDriftObserved);
        $this->assertFalse($identityDriftObserved['revision_authority_matches']);
    }

    public function test_database_preflight_rejects_public_route_drift_for_all_non_identity_route_fields(): void
    {
        DB::table('content_pages')->insert([
            'org_id' => 0,
            'slug' => 'methodology',
            'path' => '/en/personality/big-five/wrong-methodology-path',
            'kind' => ContentPage::KIND_POLICY,
            'page_type' => 'methodology',
            'title' => 'Drifted content page route',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => 'en',
            'status' => ContentPage::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => false,
            'canonical_path' => '/en/personality/big-five/methodology',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('personality_public_content_assets')->insert([
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_HUB,
            'entity_key' => 'big-five',
            'slug' => 'wrong-big-five-slug',
            'locale' => 'en',
            'title' => 'Drifted personality route',
            'is_public' => false,
            'index_eligible' => false,
            'canonical_json' => json_encode(['path' => '/en/personality/big-five'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('topic_profiles')->insert([
            'org_id' => 0,
            'topic_code' => 'big-five',
            'slug' => 'wrong-big-five-topic-slug',
            'locale' => 'en',
            'title' => 'Drifted topic route',
            'status' => TopicProfile::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);

        $this->assertFalse($result['ok']);
        $this->assertContains('public_route_mismatch', $result['issue_codes']);
        foreach ([
            'technical_trust:en:/en/personality/big-five/methodology',
            'model_hub:en:/en/personality/big-five',
            'topic_hub:en:/en/topics/big-five',
        ] as $assetId) {
            $observed = collect($result['observed_runtime'])->firstWhere('asset_id', $assetId);
            $this->assertIsArray($observed);
            $this->assertFalse($observed['public_route_matches']);
        }
    }

    public function test_database_preflight_rejects_public_canonical_route_drift(): void
    {
        DB::table('content_pages')->insert([
            'org_id' => 0,
            'slug' => 'methodology',
            'path' => '/en/personality/big-five/methodology',
            'kind' => ContentPage::KIND_POLICY,
            'page_type' => 'methodology',
            'title' => 'Drifted content page canonical',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => 'en',
            'status' => ContentPage::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => false,
            'canonical_path' => '/en/personality/big-five/wrong-canonical',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('personality_public_content_assets')->insert([
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_HUB,
            'entity_key' => 'big-five',
            'slug' => 'big-five',
            'locale' => 'en',
            'title' => 'Drifted personality canonical',
            'is_public' => false,
            'index_eligible' => false,
            'canonical_json' => json_encode(['path' => '/en/personality/wrong-canonical'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);

        $this->assertFalse($result['ok']);
        $this->assertContains('public_route_mismatch', $result['issue_codes']);
        foreach ([
            'technical_trust:en:/en/personality/big-five/methodology',
            'model_hub:en:/en/personality/big-five',
        ] as $assetId) {
            $observed = collect($result['observed_runtime'])->firstWhere('asset_id', $assetId);
            $this->assertIsArray($observed);
            $this->assertFalse($observed['public_route_matches']);
        }
    }

    public function test_database_preflight_rejects_existing_identities_that_are_no_longer_publicly_readable(): void
    {
        DB::table('articles')->insert([
            'org_id' => 0,
            'slug' => 'big-five-personality-test-vs-mbti',
            'locale' => 'en',
            'title' => 'Non-public existing article',
            'content_md' => 'Non-public existing article.',
            'status' => 'draft',
            'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
            'is_public' => false,
            'is_indexable' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('personality_public_content_assets')->insert([
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_HUB,
            'entity_key' => 'big-five',
            'slug' => 'big-five',
            'locale' => 'en',
            'title' => 'Non-public existing personality asset',
            'is_public' => false,
            'index_eligible' => false,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_DRAFT,
            'canonical_json' => json_encode(['path' => '/en/personality/big-five'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('topic_profiles')->insert([
            'org_id' => 0,
            'topic_code' => 'big-five',
            'slug' => 'big-five',
            'locale' => 'en',
            'title' => 'Non-public existing topic profile',
            'status' => TopicProfile::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);

        $this->assertFalse($result['ok']);
        $this->assertContains('existing_identity_not_publicly_readable', $result['issue_codes']);
        foreach ([
            'article:en:/en/articles/big-five-personality-test-vs-mbti',
            'model_hub:en:/en/personality/big-five',
            'topic_hub:en:/en/topics/big-five',
        ] as $assetId) {
            $observed = collect($result['observed_runtime'])->firstWhere('asset_id', $assetId);
            $this->assertIsArray($observed);
            $this->assertFalse($observed['primary_publicly_readable']);
        }
    }

    public function test_database_preflight_allows_row_backed_existing_assets_to_isolate_working_revision_without_published_pointer(): void
    {
        $rows = collect($this->readJson(self::REVIEW)['rows'])->keyBy('asset_id');
        $personalityRow = $rows->get('model_hub:en:/en/personality/big-five');
        $topicRow = $rows->get('topic_hub:en:/en/topics/big-five');
        $this->assertIsArray($personalityRow);
        $this->assertIsArray($topicRow);

        $personalityId = DB::table('personality_public_content_assets')->insertGetId([
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_HUB,
            'entity_key' => 'big-five',
            'slug' => 'big-five',
            'locale' => 'en',
            'title' => 'Published row-backed personality authority',
            'is_public' => true,
            'index_eligible' => false,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'canonical_json' => json_encode(['path' => '/en/personality/big-five'], JSON_THROW_ON_ERROR),
            'published_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $personalityRevisionId = DB::table('personality_public_content_asset_revisions')->insertGetId([
            'asset_id' => $personalityId,
            'revision_no' => 1,
            'authority_asset_key' => $personalityRow['asset_id'],
            'source_package' => $personalityRow['source_package'],
            'source_hash' => $personalityRow['source_hash'],
            'authority_package_sha256' => $personalityRow['authority_package_sha256'],
            'workflow_state' => 'draft',
            'snapshot_json' => '{}',
            'public_runtime_fingerprint_before' => str_repeat('a', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('personality_public_content_assets')->where('id', $personalityId)->update([
            'working_revision_id' => $personalityRevisionId,
            'published_revision_id' => null,
        ]);

        $topicId = DB::table('topic_profiles')->insertGetId([
            'org_id' => 0,
            'topic_code' => 'big-five',
            'slug' => 'big-five',
            'locale' => 'en',
            'title' => 'Published row-backed topic authority',
            'status' => TopicProfile::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $topicRevisionId = DB::table('topic_profile_revisions')->insertGetId([
            'profile_id' => $topicId,
            'revision_no' => 1,
            'authority_asset_key' => $topicRow['asset_id'],
            'source_package' => $topicRow['source_package'],
            'source_hash' => $topicRow['source_hash'],
            'authority_package_sha256' => $topicRow['authority_package_sha256'],
            'workflow_state' => 'draft',
            'snapshot_json' => '{}',
            'public_runtime_fingerprint_before' => str_repeat('b', 64),
            'created_at' => now(),
        ]);
        DB::table('topic_profiles')->where('id', $topicId)->update([
            'working_revision_id' => $topicRevisionId,
            'published_revision_id' => null,
        ]);

        $result = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);

        $this->assertNotContains('existing_public_revision_isolation_mismatch', $result['issue_codes']);
        foreach ([$personalityRow['asset_id'], $topicRow['asset_id']] as $assetId) {
            $observed = collect($result['observed_runtime'])->firstWhere('asset_id', $assetId);
            $this->assertIsArray($observed);
            $this->assertTrue($observed['primary_publicly_readable']);
            $this->assertNotNull($observed['working_revision_id']);
            $this->assertNull($observed['published_revision_id']);
        }
    }

    public function test_database_preflight_still_requires_published_revision_for_revision_backed_existing_article(): void
    {
        $row = collect($this->readJson(self::REVIEW)['rows'])
            ->firstWhere('asset_id', 'article:en:/en/articles/big-five-personality-test-vs-mbti');
        $this->assertIsArray($row);
        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => 'big-five-personality-test-vs-mbti',
            'locale' => 'en',
            'translation_group_id' => 'article:big-five-personality-test-vs-mbti',
            'title' => 'Published revision-backed article authority',
            'content_md' => '# Published article',
            'status' => 'published',
            'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => now()->subDay(),
        ]);
        $workingRevision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => 'article:big-five-personality-test-vs-mbti',
            'locale' => 'en',
            'source_locale' => 'en',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            'authority_asset_key' => $row['asset_id'],
            'authority_source_package' => $row['source_package'],
            'authority_source_hash' => $row['source_hash'],
            'authority_package_sha256' => $row['authority_package_sha256'],
            'title' => 'Working article authority',
            'content_md' => '# Working article',
        ]);
        $article->forceFill([
            'working_revision_id' => (int) $workingRevision->id,
            'published_revision_id' => null,
        ])->save();

        $result = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);

        $this->assertContains('existing_public_revision_isolation_mismatch', $result['issue_codes']);
    }

    public function test_cohort_order_fallback_matches_checked_in_node_builder_without_intl(): void
    {
        $lockedRows = $this->readJson('../generated/big-five-authority-v2/big5-authority-v2-release-gate-37/draft-import-package.json')['assets'];
        $expectedCohorts = $this->readJson(self::REVIEW)['cohorts'];
        $expectedByGroup = collect($expectedCohorts)
            ->groupBy(static fn (array $cohort): string => $cohort['authority_surface'].'|'.$cohort['locale'])
            ->map(static fn ($cohorts): array => $cohorts->flatMap(
                static fn (array $cohort): array => $cohort['asset_ids'],
            )->values()->all());
        $actualByGroup = collect($lockedRows)
            ->reject(static fn (array $row): bool => $row['authority_surface'] === 'CMS landing_surfaces/page_blocks')
            ->groupBy(static fn (array $row): string => $row['authority_surface'].'|'.$row['locale'])
            ->map(static fn ($rows): array => $rows->pluck('asset_id')->values()->all());

        $sort = new \ReflectionMethod(BigFiveReviewPromotionPreflight::class, 'sortCohortAssetIds');
        foreach ($actualByGroup as $key => $assetIds) {
            $this->assertSame(
                $expectedByGroup->get($key),
                $sort->invoke($this->preflight(), $assetIds, true),
                'Fallback cohort order drifted for '.$key,
            );
        }
    }

    public function test_article_runtime_baseline_changes_when_published_revision_or_public_relation_changes(): void
    {
        $row = collect($this->readJson(self::REVIEW)['rows'])
            ->firstWhere('asset_id', 'article:en:/en/articles/big-five-personality-test-vs-mbti');
        $this->assertIsArray($row);
        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => 'big-five-personality-test-vs-mbti',
            'locale' => 'en',
            'translation_group_id' => 'article:big-five-personality-test-vs-mbti',
            'title' => 'Big Five vs MBTI',
            'excerpt' => 'Published excerpt.',
            'content_md' => '# Legacy primary body',
            'status' => 'published',
            'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => now()->subDay(),
        ]);
        $publishedRevision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => 'article:big-five-personality-test-vs-mbti',
            'locale' => 'en',
            'source_locale' => 'en',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => 'Reviewed public title',
            'excerpt' => 'Reviewed public excerpt.',
            'content_md' => '# Reviewed public body',
            'seo_title' => 'Reviewed SEO title',
            'seo_description' => 'Reviewed SEO description.',
            'published_at' => now()->subDay(),
        ]);
        $workingRevision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => 'article:big-five-personality-test-vs-mbti',
            'locale' => 'en',
            'source_locale' => 'en',
            'revision_number' => 2,
            'revision_status' => ArticleTranslationRevision::STATUS_APPROVED,
            'authority_asset_key' => $row['asset_id'],
            'authority_source_hash' => $row['source_hash'],
            'authority_package_sha256' => $row['authority_package_sha256'],
            'title' => 'Reviewed working title',
            'excerpt' => 'Reviewed working excerpt.',
            'content_md' => '# Reviewed working body',
        ]);
        $article->forceFill([
            'published_revision_id' => (int) $publishedRevision->id,
            'working_revision_id' => (int) $workingRevision->id,
        ])->save();

        $before = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);
        $beforeBaseline = collect($before['observed_runtime'])
            ->firstWhere('asset_id', $row['asset_id'])['public_runtime_baseline_sha256'] ?? null;
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $beforeBaseline);

        $publishedRevision->forceFill(['content_md' => '# Mutated public body'])->save();

        $after = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);
        $afterBaseline = collect($after['observed_runtime'])
            ->firstWhere('asset_id', $row['asset_id'])['public_runtime_baseline_sha256'] ?? null;
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $afterBaseline);
        $this->assertNotSame($beforeBaseline, $afterBaseline);
        $this->assertNotSame($before['promotion_preflight_fingerprint'], $after['promotion_preflight_fingerprint']);

        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => 'en',
            'seo_title' => 'Relation-backed SEO title',
            'seo_description' => 'Relation-backed SEO description.',
            'canonical_url' => 'https://www.fermatmind.com/en/articles/big-five-personality-test-vs-mbti',
            'robots' => 'noindex,follow',
            'is_indexable' => false,
        ]);

        $afterRelation = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);
        $afterRelationBaseline = collect($afterRelation['observed_runtime'])
            ->firstWhere('asset_id', $row['asset_id'])['public_runtime_baseline_sha256'] ?? null;
        $this->assertNotSame($afterBaseline, $afterRelationBaseline);
        $this->assertNotSame($after['promotion_preflight_fingerprint'], $afterRelation['promotion_preflight_fingerprint']);

        $alternate = Article::query()->create([
            'org_id' => 0,
            'slug' => 'big-five-test-vs-mbti-zh',
            'locale' => 'zh-CN',
            'translation_group_id' => 'article:big-five-personality-test-vs-mbti',
            'title' => 'Big Five 与 MBTI',
            'content_md' => '# Alternate body',
            'status' => 'published',
            'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subDay(),
        ]);
        $alternateRevision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $alternate->id,
            'source_article_id' => (int) $alternate->id,
            'translation_group_id' => 'article:big-five-personality-test-vs-mbti',
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => 'Big Five 与 MBTI',
            'content_md' => '# Alternate body',
            'published_at' => now()->subDay(),
        ]);
        $alternate->forceFill(['published_revision_id' => (int) $alternateRevision->id])->save();

        $afterAlternate = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);
        $afterAlternateBaseline = collect($afterAlternate['observed_runtime'])
            ->firstWhere('asset_id', $row['asset_id'])['public_runtime_baseline_sha256'] ?? null;
        $this->assertNotSame($afterRelationBaseline, $afterAlternateBaseline);
        $this->assertNotSame($afterRelation['promotion_preflight_fingerprint'], $afterAlternate['promotion_preflight_fingerprint']);
    }

    public function test_topic_runtime_baseline_changes_when_public_section_relation_changes(): void
    {
        $row = collect($this->readJson(self::REVIEW)['rows'])
            ->firstWhere('asset_id', 'topic_hub:en:/en/topics/big-five');
        $this->assertIsArray($row);
        $profile = TopicProfile::query()->create([
            'org_id' => 0,
            'topic_code' => 'big-five',
            'slug' => 'big-five',
            'locale' => 'en',
            'title' => 'Big Five topic',
            'status' => TopicProfile::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => now()->subDay(),
        ]);
        $publishedRevision = TopicProfileRevision::query()->create([
            'profile_id' => (int) $profile->id,
            'revision_no' => 1,
            'workflow_state' => 'published',
            'snapshot_json' => ['title' => 'Published Big Five topic'],
            'created_at' => now()->subDay(),
        ]);
        $workingRevision = TopicProfileRevision::query()->create([
            'profile_id' => (int) $profile->id,
            'revision_no' => 2,
            'authority_asset_key' => $row['asset_id'],
            'source_package' => $row['source_package'],
            'source_hash' => $row['source_hash'],
            'authority_package_sha256' => $row['authority_package_sha256'],
            'workflow_state' => 'approved',
            'snapshot_json' => ['title' => 'Working Big Five topic'],
            'created_at' => now(),
        ]);
        $profile->forceFill([
            'published_revision_id' => (int) $publishedRevision->id,
            'working_revision_id' => (int) $workingRevision->id,
        ])->save();

        $before = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);
        $beforeBaseline = collect($before['observed_runtime'])
            ->firstWhere('asset_id', $row['asset_id'])['public_runtime_baseline_sha256'] ?? null;
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $beforeBaseline);

        $publishedRevision->forceFill([
            'snapshot_json' => ['title' => 'Drifted published Big Five topic'],
        ])->save();
        $afterPublishedRevision = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);
        $afterPublishedRevisionBaseline = collect($afterPublishedRevision['observed_runtime'])
            ->firstWhere('asset_id', $row['asset_id'])['public_runtime_baseline_sha256'] ?? null;
        $this->assertNotSame($beforeBaseline, $afterPublishedRevisionBaseline);
        $this->assertNotSame($before['promotion_preflight_fingerprint'], $afterPublishedRevision['promotion_preflight_fingerprint']);
        $before = $afterPublishedRevision;
        $beforeBaseline = $afterPublishedRevisionBaseline;

        TopicProfileSection::query()->create([
            'profile_id' => (int) $profile->id,
            'section_key' => 'overview',
            'title' => 'Public overview',
            'render_variant' => 'rich_text',
            'body_md' => 'Relation-backed public topic body.',
            'sort_order' => 10,
            'is_enabled' => true,
        ]);

        $after = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);
        $afterBaseline = collect($after['observed_runtime'])
            ->firstWhere('asset_id', $row['asset_id'])['public_runtime_baseline_sha256'] ?? null;
        $this->assertNotSame($beforeBaseline, $afterBaseline);
        $this->assertNotSame($before['promotion_preflight_fingerprint'], $after['promotion_preflight_fingerprint']);

        $targetArticle = Article::query()->create([
            'org_id' => 0,
            'slug' => 'topic-target-article',
            'locale' => 'en',
            'translation_group_id' => 'article:topic-target-article',
            'title' => 'Legacy target title',
            'content_md' => '# Legacy target body',
            'status' => 'published',
            'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => now()->subDay(),
        ]);
        $targetRevision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $targetArticle->id,
            'source_article_id' => (int) $targetArticle->id,
            'translation_group_id' => 'article:topic-target-article',
            'locale' => 'en',
            'source_locale' => 'en',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => 'Resolved target title',
            'excerpt' => 'Resolved target excerpt.',
            'content_md' => '# Resolved target body',
            'published_at' => now()->subDay(),
        ]);
        $targetArticle->forceFill(['published_revision_id' => (int) $targetRevision->id])->save();
        TopicProfileEntry::query()->create([
            'profile_id' => (int) $profile->id,
            'entry_type' => 'article',
            'group_key' => 'articles',
            'target_key' => 'topic-target-article',
            'target_locale' => 'en',
            'sort_order' => 10,
            'is_featured' => false,
            'is_enabled' => true,
        ]);

        $beforeTargetChange = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);
        $beforeTargetBaseline = collect($beforeTargetChange['observed_runtime'])
            ->firstWhere('asset_id', $row['asset_id'])['public_runtime_baseline_sha256'] ?? null;

        $targetRevision->forceFill(['title' => 'Mutated resolved target title'])->save();

        $afterTargetChange = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);
        $afterTargetBaseline = collect($afterTargetChange['observed_runtime'])
            ->firstWhere('asset_id', $row['asset_id'])['public_runtime_baseline_sha256'] ?? null;
        $this->assertNotSame($beforeTargetBaseline, $afterTargetBaseline);
        $this->assertNotSame($beforeTargetChange['promotion_preflight_fingerprint'], $afterTargetChange['promotion_preflight_fingerprint']);
    }

    public function test_database_preflight_rejects_future_scheduled_topic_identity_that_public_reader_hides(): void
    {
        DB::table('topic_profiles')->insert([
            'org_id' => 0,
            'topic_code' => 'big-five',
            'slug' => 'big-five',
            'locale' => 'en',
            'title' => 'Future scheduled existing topic profile',
            'status' => TopicProfile::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);

        $this->assertFalse($result['ok']);
        $this->assertContains('existing_identity_not_publicly_readable', $result['issue_codes']);
        $observed = collect($result['observed_runtime'])->firstWhere('asset_id', 'topic_hub:en:/en/topics/big-five');
        $this->assertIsArray($observed);
        $this->assertFalse($observed['primary_publicly_readable']);
    }

    public function test_database_preflight_rejects_public_preserved_product_shell(): void
    {
        $surfaceId = DB::table('landing_surfaces')->insertGetId([
            'org_id' => 0,
            'surface_key' => 'test_big_five_personality_test_ocean_model',
            'locale' => 'en',
            'title' => 'Unexpected public preserved shell',
            'schema_version' => 'big5-authority-v2-draft.v1',
            'payload_json' => '{}',
            'status' => LandingSurface::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);

        $this->assertFalse($result['ok']);
        $this->assertContains('new_identity_already_publicly_readable', $result['issue_codes']);
        $observed = collect($result['observed_runtime'])
            ->firstWhere('asset_id', 'test_landing:en:/en/tests/big-five-personality-test-ocean-model');
        $this->assertIsArray($observed);
        $this->assertTrue($observed['primary_publicly_readable']);

        DB::table('page_blocks')->insert([
            'landing_surface_id' => $surfaceId,
            'block_key' => 'hero',
            'block_type' => 'hero',
            'title' => 'Unexpected public shell block',
            'payload_json' => json_encode(['body' => 'Runtime block drift'], JSON_THROW_ON_ERROR),
            'sort_order' => 10,
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $afterBlock = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);
        $afterBlockObserved = collect($afterBlock['observed_runtime'])
            ->firstWhere('asset_id', 'test_landing:en:/en/tests/big-five-personality-test-ocean-model');
        $this->assertIsArray($afterBlockObserved);
        $this->assertNotSame(
            $observed['public_runtime_baseline_sha256'],
            $afterBlockObserved['public_runtime_baseline_sha256'],
        );
        $this->assertNotSame($result['promotion_preflight_fingerprint'], $afterBlock['promotion_preflight_fingerprint']);

        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => 'landing-runtime-target',
            'locale' => 'en',
            'translation_group_id' => 'article:landing-runtime-target',
            'title' => 'Legacy landing target',
            'content_md' => '# Legacy landing target',
            'status' => 'published',
            'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subDay(),
        ]);
        $revision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => 'article:landing-runtime-target',
            'locale' => 'en',
            'source_locale' => 'en',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => 'Resolved landing target',
            'content_md' => '# Resolved landing target',
            'published_at' => now()->subDay(),
        ]);
        $article->forceFill(['published_revision_id' => (int) $revision->id])->save();
        DB::table('page_blocks')->insert([
            'landing_surface_id' => $surfaceId,
            'block_key' => 'recommended_articles',
            'block_type' => 'article_cards',
            'title' => 'Resolved recommendations',
            'payload_json' => json_encode([
                'limit' => 1,
                'items' => [['pinned' => true, 'article' => ['slug' => 'landing-runtime-target']]],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'sort_order' => 20,
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $beforeResolvedChange = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);
        $beforeResolvedBaseline = collect($beforeResolvedChange['observed_runtime'])
            ->firstWhere('asset_id', 'test_landing:en:/en/tests/big-five-personality-test-ocean-model')['public_runtime_baseline_sha256'] ?? null;

        $revision->forceFill(['title' => 'Mutated resolved landing target'])->save();

        $afterResolvedChange = $this->preflight()->databasePreflight(self::REVIEW, self::AUTHORIZATION, self::ROLLBACK);
        $afterResolvedBaseline = collect($afterResolvedChange['observed_runtime'])
            ->firstWhere('asset_id', 'test_landing:en:/en/tests/big-five-personality-test-ocean-model')['public_runtime_baseline_sha256'] ?? null;
        $this->assertNotSame($beforeResolvedBaseline, $afterResolvedBaseline);
        $this->assertNotSame(
            $beforeResolvedChange['promotion_preflight_fingerprint'],
            $afterResolvedChange['promotion_preflight_fingerprint'],
        );
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

    public function test_package_only_rejects_unknown_cohort_asset_with_regenerated_artifact_locks(): void
    {
        $review = $this->readJson(self::REVIEW);
        $omittedAssetId = $review['cohorts'][0]['asset_ids'][0];
        $review['cohorts'][0]['asset_ids'][0] = 'unknown:en:/not-a-review-row';
        $review['cohorts'][0]['cohort_sha256'] = hash('sha256', json_encode($review['cohorts'][0]['asset_ids'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        foreach ($review['rows'] as &$row) {
            if ($row['asset_id'] === $omittedAssetId) {
                $row['promotion']['cohort_id'] = null;
                break;
            }
        }
        unset($row);
        [$reviewPath, $reviewSha] = $this->writeTemporaryJson('pr47-review-unknown-cohort-asset.json', $review);

        $rollback = $this->readJson(self::ROLLBACK);
        $rollback['review_manifest_sha256'] = $reviewSha;
        [$rollbackPath, $rollbackSha] = $this->writeTemporaryJson('pr47-rollback-unknown-cohort-asset.json', $rollback);

        $authorization = $this->readJson(self::AUTHORIZATION);
        $authorization['review_manifest_sha256'] = $reviewSha;
        $authorization['rollback_plan_sha256'] = $rollbackSha;
        $authorization['cohorts'][0]['cohort_sha256'] = $review['cohorts'][0]['cohort_sha256'];
        [$authorizationPath] = $this->writeTemporaryJson('pr47-authorization-unknown-cohort-asset.json', $authorization);

        try {
            $this->preflight()->packageOnly($reviewPath, $authorizationPath, $rollbackPath);
            $this->fail('Expected unknown cohort asset identity to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cohort references an unknown asset identity', $exception->getMessage());
        } finally {
            File::delete([$reviewPath, $rollbackPath, $authorizationPath]);
        }
    }

    public function test_package_only_rejects_repartitioned_cohorts_with_regenerated_artifact_locks(): void
    {
        $review = $this->readJson(self::REVIEW);
        $firstAssetId = $review['cohorts'][0]['asset_ids'][0];
        $secondAssetId = $review['cohorts'][1]['asset_ids'][0];
        $review['cohorts'][0]['asset_ids'][0] = $secondAssetId;
        $review['cohorts'][1]['asset_ids'][0] = $firstAssetId;
        foreach ([0, 1] as $index) {
            $review['cohorts'][$index]['cohort_sha256'] = hash('sha256', json_encode($review['cohorts'][$index]['asset_ids'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
        foreach ($review['rows'] as &$row) {
            if ($row['asset_id'] === $firstAssetId) {
                $row['promotion']['cohort_id'] = $review['cohorts'][1]['cohort_id'];
            } elseif ($row['asset_id'] === $secondAssetId) {
                $row['promotion']['cohort_id'] = $review['cohorts'][0]['cohort_id'];
            }
        }
        unset($row);
        [$reviewPath, $reviewSha] = $this->writeTemporaryJson('pr47-review-repartitioned-cohorts.json', $review);

        $rollback = $this->readJson(self::ROLLBACK);
        $rollback['review_manifest_sha256'] = $reviewSha;
        [$rollbackPath, $rollbackSha] = $this->writeTemporaryJson('pr47-rollback-repartitioned-cohorts.json', $rollback);

        $authorization = $this->readJson(self::AUTHORIZATION);
        $authorization['review_manifest_sha256'] = $reviewSha;
        $authorization['rollback_plan_sha256'] = $rollbackSha;
        foreach ([0, 1] as $index) {
            $authorization['cohorts'][$index]['cohort_sha256'] = $review['cohorts'][$index]['cohort_sha256'];
        }
        [$authorizationPath] = $this->writeTemporaryJson('pr47-authorization-repartitioned-cohorts.json', $authorization);

        try {
            $this->preflight()->packageOnly($reviewPath, $authorizationPath, $rollbackPath);
            $this->fail('Expected deterministic cohort repartitioning to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('exact cohort identity contract mismatch', $exception->getMessage());
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

    public function test_package_only_rejects_offsetting_nonzero_rollback_effects(): void
    {
        $rollback = $this->readJson(self::ROLLBACK);
        $rollback['effects']['database_writes'] = 1;
        $rollback['effects']['rollbacks'] = -1;
        [$rollbackPath, $rollbackSha] = $this->writeTemporaryJson('pr47-rollback-offsetting-effects.json', $rollback);

        $authorization = $this->readJson(self::AUTHORIZATION);
        $authorization['rollback_plan_sha256'] = $rollbackSha;
        [$authorizationPath] = $this->writeTemporaryJson('pr47-authorization-offsetting-effects.json', $authorization);

        try {
            $this->preflight()->packageOnly(self::REVIEW, $authorizationPath, $rollbackPath);
            $this->fail('Expected offsetting nonzero rollback effects to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Rollback plan identity/hold contract mismatch', $exception->getMessage());
        } finally {
            File::delete([$rollbackPath, $authorizationPath]);
        }
    }

    public function test_database_preflight_rejects_rehashed_nonzero_rollback_effects(): void
    {
        $rollback = $this->readJson(self::ROLLBACK);
        $rollback['effects']['database_writes'] = 1;
        [$rollbackPath, $rollbackSha] = $this->writeTemporaryJson('pr47-database-rollback-nonzero-effects.json', $rollback);

        $authorization = $this->readJson(self::AUTHORIZATION);
        $authorization['rollback_plan_sha256'] = $rollbackSha;
        [$authorizationPath] = $this->writeTemporaryJson('pr47-database-authorization-nonzero-effects.json', $authorization);

        try {
            $this->preflight()->databasePreflight(self::REVIEW, $authorizationPath, $rollbackPath);
            $this->fail('Expected database preflight to reject nonzero rollback effects.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Rollback plan identity/hold contract mismatch', $exception->getMessage());
        } finally {
            File::delete([$rollbackPath, $authorizationPath]);
        }
    }

    public function test_database_preflight_rejects_rehashed_rollback_that_allows_missing_targets(): void
    {
        $rollback = $this->readJson(self::ROLLBACK);
        $rollback['abort_on_missing_target'] = false;
        [$rollbackPath, $rollbackSha] = $this->writeTemporaryJson('pr47-database-rollback-allows-missing-targets.json', $rollback);

        $authorization = $this->readJson(self::AUTHORIZATION);
        $authorization['rollback_plan_sha256'] = $rollbackSha;
        [$authorizationPath] = $this->writeTemporaryJson('pr47-database-authorization-allows-missing-targets.json', $authorization);

        try {
            $this->preflight()->databasePreflight(self::REVIEW, $authorizationPath, $rollbackPath);
            $this->fail('Expected database preflight to reject rollback without abort-on-missing safety.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Rollback plan identity/hold contract mismatch', $exception->getMessage());
        } finally {
            File::delete([$rollbackPath, $authorizationPath]);
        }
    }

    public function test_standalone_validator_rejects_rehashed_nonzero_rollback_effects(): void
    {
        $source = base_path('../generated/big-five-authority-v2/big5-authority-v2-review-promotion-gate-47');
        $temporary = base_path('../generated/big-five-authority-v2/pr47-validator-test-'.bin2hex(random_bytes(8)));
        $this->assertTrue(File::copyDirectory($source, $temporary));

        try {
            $rollbackPath = $temporary.'/rollback-plan.json';
            $rollback = json_decode(File::get($rollbackPath), true, flags: JSON_THROW_ON_ERROR);
            $rollback['effects']['database_writes'] = 1;
            File::put($rollbackPath, json_encode($rollback, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);

            $authorizationPath = $temporary.'/authorization-packet-template.json';
            $authorization = json_decode(File::get($authorizationPath), true, flags: JSON_THROW_ON_ERROR);
            $authorization['rollback_plan_sha256'] = hash_file('sha256', $rollbackPath);
            File::put($authorizationPath, json_encode($authorization, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);

            $hashesPath = $temporary.'/sha256sums.json';
            $hashes = json_decode(File::get($hashesPath), true, flags: JSON_THROW_ON_ERROR);
            foreach (array_keys($hashes['files']) as $name) {
                $hashes['files'][$name] = hash_file('sha256', $temporary.'/'.$name);
            }
            File::put($hashesPath, json_encode($hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);

            $process = new Process(['node', $temporary.'/validate-package.mjs'], dirname(base_path()));
            $process->setTimeout(20);
            $process->run();

            $this->assertFalse($process->isSuccessful(), $process->getOutput());
            $this->assertStringContainsString(
                'rollback effects must match the exact zero-effect contract',
                $process->getErrorOutput().$process->getOutput(),
            );
        } finally {
            File::deleteDirectory($temporary);
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

    public function test_database_preflight_rejects_promotion_eligibility_on_preserved_product_shell(): void
    {
        $review = $this->readJson(self::REVIEW);
        $shellIndex = collect($review['rows'])->search(
            static fn (array $row): bool => ($row['action_contract']['product_shell_preserved'] ?? false) === true,
        );
        $this->assertIsInt($shellIndex);
        $review['rows'][$shellIndex]['promotion']['eligible'] = true;
        [$reviewPath, $reviewSha] = $this->writeTemporaryJson('pr47-review-shell-promotion-eligible.json', $review);

        $rollback = $this->readJson(self::ROLLBACK);
        $rollback['review_manifest_sha256'] = $reviewSha;
        [$rollbackPath, $rollbackSha] = $this->writeTemporaryJson('pr47-rollback-shell-promotion-eligible.json', $rollback);

        $authorization = $this->readJson(self::AUTHORIZATION);
        $authorization['review_manifest_sha256'] = $reviewSha;
        $authorization['rollback_plan_sha256'] = $rollbackSha;
        [$authorizationPath] = $this->writeTemporaryJson('pr47-authorization-shell-promotion-eligible.json', $authorization);

        try {
            $result = $this->preflight()->databasePreflight($reviewPath, $authorizationPath, $rollbackPath);

            $this->assertFalse($result['ok']);
            $this->assertContains('product_shell_promotion_eligibility_forbidden', $result['blocker_codes']);
        } finally {
            File::delete([$reviewPath, $rollbackPath, $authorizationPath]);
        }
    }

    public function test_database_preflight_rejects_authorization_deploy_sha_that_differs_from_runtime_revision(): void
    {
        $authorization = $this->readJson(self::AUTHORIZATION);
        $authorization['production_promotion_currently_authorized'] = true;
        $authorization['approval_phrases_currently_executable'] = true;
        $authorization['deployed_sha'] = str_repeat('a', 40);
        $authorization['promotion_preflight_fingerprint'] = str_repeat('b', 64);
        [$authorizationPath] = $this->writeTemporaryJson('pr47-authorization-stale-deploy.json', $authorization);
        $revisionPath = base_path('../REVISION');
        $file = File::partialMock();
        $file->shouldReceive('isFile')->once()->with($revisionPath)->andReturnTrue();
        $file->shouldReceive('get')->once()->with($revisionPath)->andReturn(str_repeat('c', 40).PHP_EOL);

        try {
            $result = $this->preflight()->databasePreflight(self::REVIEW, $authorizationPath, self::ROLLBACK);

            $this->assertFalse($result['ok']);
            $this->assertFalse($result['authorization_deploy_sha_matches_runtime']);
            $this->assertContains('authorization_deploy_sha_mismatch', $result['blocker_codes']);
            $this->assertTrue(collect($result['cohorts'])->every(
                static fn (array $cohort): bool => $cohort['exact_authorization_template'] === null
                    && $cohort['authorization_matches'] === false,
            ));
        } finally {
            File::delete($authorizationPath);
        }
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
