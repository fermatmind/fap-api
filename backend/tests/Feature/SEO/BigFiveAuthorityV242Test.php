<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\LandingSurface;
use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Models\TopicProfile;
use App\Models\TopicProfileRevision;
use App\Services\BigFive\AuthorityV2\VisibleDate\BigFiveVisibleDateProjector;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class BigFiveAuthorityV242Test extends TestCase
{
    private BigFiveVisibleDateProjector $projector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projector = app(BigFiveVisibleDateProjector::class);
    }

    public function test_repository_fixture_locks_the_exact_82_visible_date_findings_by_page_family(): void
    {
        $path = base_path('../generated/big-five-authority-v2/big5-authority-v2-visible-date-42/visible-date-findings.json');
        $fixture = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('big5-visible-date-findings.v1', $fixture['schema_version']);
        $this->assertSame(
            '60ec72b708aa5876dbee90ec12fec6dade6387414ce845f2c5ef4e4795b4ac65',
            $fixture['source']['artifact_sha256'],
        );
        $this->assertSame(82, $fixture['counts']['finding_count']);
        $this->assertSame(82, count($fixture['findings']));
        $this->assertSame([
            'domain' => 5,
            'facet' => 30,
            'facet_hub' => 2,
            'model_hub' => 1,
            'range' => 40,
            'test_landing' => 2,
            'topic_hub' => 2,
        ], $this->distribution($fixture['findings'], 'page_family'));
        $this->assertSame([
            'CMS landing_surfaces/page_blocks' => 2,
            'CMS personality_public_content_assets' => 78,
            'CMS topic_profiles' => 2,
        ], $this->distribution($fixture['findings'], 'authority_surface'));
        $this->assertSame(['en' => 64, 'zh-CN' => 18], $this->distribution($fixture['findings'], 'locale'));
        $this->assertCount(82, array_unique(array_column($fixture['findings'], 'asset_id')));
        $this->assertCount(82, array_unique(array_column($fixture['findings'], 'route')));
        foreach ($fixture['findings'] as $finding) {
            $this->assertSame('FAIL', $finding['assessment']);
            $this->assertFalse($finding['observed_visible_date']);
        }
    }

    public function test_article_projects_only_publication_manual_review_and_explicit_editorial_update_dates(): void
    {
        $article = new Article([
            'org_id' => 7,
            'slug' => 'big-five-overview',
            'locale' => 'en',
            'status' => 'published',
            'is_public' => true,
            'published_at' => '2026-07-01T00:00:00Z',
        ]);
        $article->forceFill([
            'id' => 10,
            'published_revision_id' => 20,
            'updated_at' => '2026-07-04T00:00:00Z',
        ]);
        $revision = new ArticleTranslationRevision([
            'org_id' => 7,
            'article_id' => 10,
            'locale' => 'en',
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'reviewed_by' => 42,
            'reviewed_at' => '2026-07-02T00:00:00Z',
            'published_at' => '2026-07-03T00:00:00Z',
            'authority_metadata_json' => $this->dateMetadata('2026-07-04T00:00:00Z'),
        ]);
        $revision->forceFill([
            'id' => 20,
            'created_at' => '2026-06-29T00:00:00Z',
            'updated_at' => '2026-07-04T00:00:00Z',
        ]);

        $projection = $this->projector->forArticle($article, $revision);

        $this->assertSame([
            'published_at' => '2026-07-03T00:00:00+00:00',
            'reviewed_at' => '2026-07-02T00:00:00+00:00',
            'updated_at' => '2026-07-04T00:00:00+00:00',
        ], $projection['visible_dates']);
        $this->assertSame('article_translation_revisions.published_at', $projection['provenance']['published_at']['source_field']);
        $this->assertSame('manual_review', $projection['provenance']['reviewed_at']['source_kind']);
        $this->assertSame('editorial_update', $projection['provenance']['updated_at']['source_kind']);
        $this->assertTrue($projection['eligibility']['published_date_eligible']);
    }

    public function test_personality_topic_and_landing_project_their_own_authority_dates(): void
    {
        $asset = new PersonalityPublicContentAsset([
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_DOMAIN,
            'entity_key' => 'agreeableness',
            'slug' => 'agreeableness',
            'locale' => 'en',
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'review_state' => 'approved',
            'is_public' => true,
            'published_at' => '2026-07-01T00:00:00Z',
            'last_reviewed_at' => '2026-07-02T00:00:00Z',
            'authority_json' => $this->dateMetadata('2026-07-03T00:00:00Z'),
        ]);
        $asset->forceFill([
            'id' => 30,
            'published_revision_id' => 31,
            'updated_at' => '2026-07-03T00:00:00Z',
        ]);
        $assetRevision = new PersonalityPublicContentAssetRevision(['asset_id' => 30]);
        $assetRevision->forceFill(['id' => 31, 'created_at' => '2026-06-28T00:00:00Z']);

        $topic = new TopicProfile([
            'slug' => 'big-five',
            'locale' => 'en',
            'status' => TopicProfile::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => '2026-07-05T00:00:00Z',
        ]);
        $topic->forceFill([
            'id' => 40,
            'published_revision_id' => 41,
            'updated_at' => '2026-07-07T00:00:00Z',
        ]);
        $topicRevision = new TopicProfileRevision([
            'profile_id' => 40,
            'snapshot_json' => $this->dateMetadata('2026-07-07T00:00:00Z', '2026-07-06T00:00:00Z'),
            'created_at' => '2026-06-27T00:00:00Z',
        ]);
        $topicRevision->forceFill(['id' => 41]);

        $landing = new LandingSurface([
            'surface_key' => 'big-five-test',
            'locale' => 'en',
            'status' => LandingSurface::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => '2026-07-08T00:00:00Z',
            'payload_json' => $this->dateMetadata('2026-07-10T00:00:00Z', '2026-07-09T00:00:00Z'),
        ]);
        $landing->forceFill(['id' => 50, 'updated_at' => '2026-07-10T00:00:00Z']);

        $assetProjection = $this->projector->forPersonalityAsset($asset, $assetRevision);
        $topicProjection = $this->projector->forTopic($topic, $topicRevision);
        $landingProjection = $this->projector->forLandingSurface($landing);

        $this->assertSame('2026-07-01T00:00:00+00:00', $assetProjection['visible_dates']['published_at']);
        $this->assertSame('2026-07-02T00:00:00+00:00', $assetProjection['visible_dates']['reviewed_at']);
        $this->assertSame('2026-07-03T00:00:00+00:00', $assetProjection['visible_dates']['updated_at']);
        $this->assertSame('2026-06-28T00:00:00+00:00', $assetProjection['audit_only_dates']['revision_created_at']);
        $this->assertSame('2026-07-05T00:00:00+00:00', $topicProjection['visible_dates']['published_at']);
        $this->assertSame('2026-07-06T00:00:00+00:00', $topicProjection['visible_dates']['reviewed_at']);
        $this->assertSame('2026-07-07T00:00:00+00:00', $topicProjection['visible_dates']['updated_at']);
        $this->assertSame('2026-07-08T00:00:00+00:00', $landingProjection['visible_dates']['published_at']);
        $this->assertSame('2026-07-09T00:00:00+00:00', $landingProjection['visible_dates']['reviewed_at']);
        $this->assertSame('2026-07-10T00:00:00+00:00', $landingProjection['visible_dates']['updated_at']);
    }

    public function test_stale_draft_foreign_tenant_and_foreign_locale_revisions_fail_closed(): void
    {
        $article = new Article([
            'org_id' => 7,
            'slug' => 'big-five-overview',
            'locale' => 'en',
            'status' => 'published',
            'is_public' => true,
            'published_at' => '2026-07-01T00:00:00Z',
        ]);
        $article->forceFill([
            'id' => 10,
            'published_revision_id' => 20,
            'updated_at' => '2026-07-04T00:00:00Z',
        ]);

        $revisionAttributes = [
            'org_id' => 7,
            'article_id' => 10,
            'locale' => 'en',
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'reviewed_by' => 42,
            'reviewed_at' => '2026-07-02T00:00:00Z',
            'published_at' => '2026-07-03T00:00:00Z',
            'authority_metadata_json' => $this->dateMetadata('2026-07-04T00:00:00Z'),
        ];
        $invalidRevisions = [
            (new ArticleTranslationRevision($revisionAttributes))->forceFill(['id' => 21, 'updated_at' => '2026-07-04T00:00:00Z']),
            (new ArticleTranslationRevision([...$revisionAttributes, 'org_id' => 8]))->forceFill(['id' => 20, 'updated_at' => '2026-07-04T00:00:00Z']),
            (new ArticleTranslationRevision([...$revisionAttributes, 'locale' => 'zh-CN']))->forceFill(['id' => 20, 'updated_at' => '2026-07-04T00:00:00Z']),
            (new ArticleTranslationRevision([
                ...$revisionAttributes,
                'revision_status' => ArticleTranslationRevision::STATUS_MACHINE_DRAFT,
            ]))->forceFill(['id' => 20, 'updated_at' => '2026-07-04T00:00:00Z']),
            (new ArticleTranslationRevision([
                ...$revisionAttributes,
                'published_at' => '2099-07-03T00:00:00Z',
            ]))->forceFill(['id' => 20, 'updated_at' => '2026-07-04T00:00:00Z']),
        ];

        foreach ($invalidRevisions as $invalidRevision) {
            $projection = $this->projector->forArticle($article, $invalidRevision);
            $this->assertSame(
                ['published_at' => null, 'reviewed_at' => null, 'updated_at' => null],
                $projection['visible_dates'],
            );
            $this->assertNull($projection['audit_only_dates']['revision_created_at']);
            $this->assertFalse($projection['eligibility']['visible_date_eligible']);
        }

        $asset = new PersonalityPublicContentAsset([
            'slug' => 'agreeableness',
            'locale' => 'en',
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'review_state' => 'approved',
            'is_public' => true,
            'published_at' => '2026-07-01T00:00:00Z',
            'last_reviewed_at' => '2026-07-02T00:00:00Z',
            'authority_json' => $this->dateMetadata('2026-07-03T00:00:00Z'),
        ]);
        $asset->forceFill([
            'id' => 30,
            'published_revision_id' => 31,
            'working_revision_id' => 32,
            'updated_at' => '2026-07-03T00:00:00Z',
        ]);
        $assetDraftRevision = (new PersonalityPublicContentAssetRevision(['asset_id' => 30]))
            ->forceFill(['id' => 32, 'created_at' => '2026-06-28T00:00:00Z']);
        $assetProjection = $this->projector->forPersonalityAsset($asset, $assetDraftRevision);
        $this->assertSame(
            ['published_at' => null, 'reviewed_at' => null, 'updated_at' => null],
            $assetProjection['visible_dates'],
        );
        $this->assertSame('2026-06-28T00:00:00+00:00', $assetProjection['audit_only_dates']['revision_created_at']);
        $this->assertFalse($assetProjection['eligibility']['visible_date_eligible']);

        $topic = new TopicProfile([
            'slug' => 'big-five',
            'locale' => 'en',
            'status' => TopicProfile::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => '2026-07-05T00:00:00Z',
        ]);
        $topic->forceFill([
            'id' => 40,
            'published_revision_id' => 41,
            'working_revision_id' => 42,
            'updated_at' => '2026-07-07T00:00:00Z',
        ]);
        $topicDraftRevision = new TopicProfileRevision([
            'profile_id' => 40,
            'snapshot_json' => $this->dateMetadata('2026-07-07T00:00:00Z', '2026-07-06T00:00:00Z'),
            'created_at' => '2026-06-27T00:00:00Z',
        ]);
        $topicDraftRevision->forceFill(['id' => 42]);
        $topicProjection = $this->projector->forTopic($topic, $topicDraftRevision);
        $this->assertSame([
            'published_at' => '2026-07-05T00:00:00+00:00',
            'reviewed_at' => null,
            'updated_at' => null,
        ], $topicProjection['visible_dates']);
        $this->assertSame('2026-06-27T00:00:00+00:00', $topicProjection['audit_only_dates']['revision_created_at']);
    }

    public function test_non_public_authority_records_never_project_visible_dates(): void
    {
        $article = new Article([
            'org_id' => 7,
            'slug' => 'private-article',
            'locale' => 'en',
            'status' => 'published',
            'is_public' => false,
            'published_at' => '2026-07-01T00:00:00Z',
        ]);
        $article->forceFill(['id' => 10, 'published_revision_id' => 20]);
        $articleRevision = new ArticleTranslationRevision([
            'org_id' => 7,
            'article_id' => 10,
            'locale' => 'en',
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'reviewed_by' => 42,
            'reviewed_at' => '2026-07-02T00:00:00Z',
            'published_at' => '2026-07-01T00:00:00Z',
        ]);
        $articleRevision->forceFill(['id' => 20]);

        $asset = new PersonalityPublicContentAsset([
            'slug' => 'private-asset',
            'locale' => 'en',
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'review_state' => 'approved',
            'is_public' => false,
            'published_at' => '2026-07-01T00:00:00Z',
            'last_reviewed_at' => '2026-07-02T00:00:00Z',
            'authority_json' => $this->dateMetadata('2026-07-03T00:00:00Z'),
        ]);
        $asset->forceFill(['id' => 30, 'updated_at' => '2026-07-03T00:00:00Z']);

        $topic = new TopicProfile([
            'slug' => 'private-topic',
            'locale' => 'en',
            'status' => TopicProfile::STATUS_PUBLISHED,
            'is_public' => false,
            'published_at' => '2026-07-01T00:00:00Z',
        ]);
        $topic->forceFill(['id' => 40, 'published_revision_id' => 41, 'updated_at' => '2026-07-03T00:00:00Z']);
        $topicRevision = new TopicProfileRevision([
            'profile_id' => 40,
            'snapshot_json' => $this->dateMetadata('2026-07-03T00:00:00Z', '2026-07-02T00:00:00Z'),
        ]);
        $topicRevision->forceFill(['id' => 41]);

        $landing = new LandingSurface([
            'surface_key' => 'private-landing',
            'locale' => 'en',
            'status' => LandingSurface::STATUS_PUBLISHED,
            'is_public' => false,
            'published_at' => '2026-07-01T00:00:00Z',
            'payload_json' => $this->dateMetadata('2026-07-03T00:00:00Z', '2026-07-02T00:00:00Z'),
        ]);
        $landing->forceFill(['id' => 50, 'updated_at' => '2026-07-03T00:00:00Z']);

        foreach ([
            $this->projector->forArticle($article, $articleRevision),
            $this->projector->forPersonalityAsset($asset),
            $this->projector->forTopic($topic, $topicRevision),
            $this->projector->forLandingSurface($landing),
        ] as $projection) {
            $this->assertSame(
                ['published_at' => null, 'reviewed_at' => null, 'updated_at' => null],
                $projection['visible_dates'],
            );
            $this->assertFalse($projection['eligibility']['visible_date_eligible']);
        }
    }

    public function test_import_build_deploy_revision_and_raw_update_dates_never_backfill_visible_dates(): void
    {
        $metadata = [
            'date_provenance' => [
                'published_at' => ['value' => '2026-07-01T00:00:00Z', 'source_kind' => 'import_event', 'authority_ref' => 'import:1'],
                'updated_at' => ['value' => '2026-07-02T00:00:00Z', 'source_kind' => 'editorial_update', 'authority_ref' => 'edit:1'],
                'imported_at' => ['value' => '2026-07-03T00:00:00Z', 'source_kind' => 'import_event', 'authority_ref' => 'import:1'],
                'built_at' => ['value' => '2026-07-04T00:00:00Z', 'source_kind' => 'build_event', 'authority_ref' => 'build:1'],
                'deployed_at' => ['value' => '2026-07-05T00:00:00Z', 'source_kind' => 'deploy_event', 'authority_ref' => 'deploy:1'],
            ],
        ];
        $article = new Article(['slug' => 'draft', 'locale' => 'en', 'status' => 'draft', 'is_public' => false]);
        $article->forceFill(['id' => 1, 'updated_at' => '2026-07-06T00:00:00Z']);
        $articleRevision = new ArticleTranslationRevision([
            'org_id' => 0,
            'article_id' => 1,
            'locale' => 'en',
            'revision_status' => ArticleTranslationRevision::STATUS_MACHINE_DRAFT,
            'authority_metadata_json' => $metadata,
        ]);
        $articleRevision->forceFill(['id' => 2, 'created_at' => '2026-06-30T00:00:00Z', 'updated_at' => '2026-07-06T00:00:00Z']);

        $asset = new PersonalityPublicContentAsset([
            'slug' => 'draft', 'locale' => 'en', 'launch_state' => PersonalityPublicContentAsset::LAUNCH_DRAFT,
            'review_state' => 'draft', 'is_public' => false, 'authority_json' => $metadata,
        ]);
        $asset->forceFill(['id' => 3, 'updated_at' => '2026-07-06T00:00:00Z']);
        $assetRevision = new PersonalityPublicContentAssetRevision(['asset_id' => 3]);
        $assetRevision->forceFill(['created_at' => '2026-06-30T00:00:00Z']);

        $topic = new TopicProfile(['slug' => 'draft', 'locale' => 'en', 'status' => TopicProfile::STATUS_DRAFT, 'is_public' => false]);
        $topic->forceFill(['id' => 4, 'updated_at' => '2026-07-06T00:00:00Z']);
        $topicRevision = new TopicProfileRevision(['profile_id' => 4, 'snapshot_json' => $metadata, 'created_at' => '2026-06-30T00:00:00Z']);

        $landing = new LandingSurface([
            'surface_key' => 'draft', 'locale' => 'en', 'status' => LandingSurface::STATUS_DRAFT,
            'is_public' => false, 'payload_json' => $metadata,
        ]);
        $landing->forceFill(['id' => 5, 'updated_at' => '2026-07-06T00:00:00Z']);

        $projections = [
            $this->projector->forArticle($article, $articleRevision),
            $this->projector->forPersonalityAsset($asset, $assetRevision),
            $this->projector->forTopic($topic, $topicRevision),
            $this->projector->forLandingSurface($landing),
        ];
        foreach ($projections as $projection) {
            $this->assertSame(['published_at' => null, 'reviewed_at' => null, 'updated_at' => null], $projection['visible_dates']);
            $this->assertFalse($projection['eligibility']['visible_date_eligible']);
            $this->assertFalse($projection['eligibility']['published_date_eligible']);
            $this->assertContains('published_at_authority_missing', $projection['eligibility']['blocked_reasons']);
            $this->assertSame('2026-07-03T00:00:00+00:00', $projection['audit_only_dates']['imported_at']);
            $this->assertSame('2026-07-04T00:00:00+00:00', $projection['audit_only_dates']['built_at']);
            $this->assertSame('2026-07-05T00:00:00+00:00', $projection['audit_only_dates']['deployed_at']);
        }
        $this->assertSame('2026-06-30T00:00:00+00:00', $projections[0]['audit_only_dates']['revision_created_at']);
        $this->assertNull($projections[3]['audit_only_dates']['revision_created_at']);
    }

    /** @return array<string, mixed> */
    private function dateMetadata(string $updatedAt, ?string $reviewedAt = null): array
    {
        $provenance = [
            'updated_at' => [
                'value' => $updatedAt,
                'source_kind' => 'editorial_update',
                'authority_ref' => 'cms-edit:verified',
            ],
        ];
        if ($reviewedAt !== null) {
            $provenance['reviewed_at'] = [
                'value' => $reviewedAt,
                'source_kind' => 'manual_review',
                'authority_ref' => 'review-ledger:verified',
            ];
        }

        return ['date_provenance' => $provenance];
    }

    /** @param list<array<string, mixed>> $rows @return array<string, int> */
    private function distribution(array $rows, string $field): array
    {
        $counts = array_count_values(array_column($rows, $field));
        ksort($counts);

        return $counts;
    }
}
