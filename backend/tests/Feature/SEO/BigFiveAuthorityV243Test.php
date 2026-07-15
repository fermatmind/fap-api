<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\LandingSurface;
use App\Models\PersonalityPublicContentAsset;
use App\Models\TopicProfile;
use App\Models\TopicProfileRevision;
use App\Services\BigFive\AuthorityV2\VisibleProvenance\BigFiveVisibleProvenanceProjector;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class BigFiveAuthorityV243Test extends TestCase
{
    private BigFiveVisibleProvenanceProjector $projector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projector = app(BigFiveVisibleProvenanceProjector::class);
    }

    public function test_fixture_locks_exact_author_reviewer_and_source_failures(): void
    {
        $path = base_path('../generated/big-five-authority-v2/big5-authority-v2-visible-provenance-43/visible-provenance-findings.json');
        $fixture = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('big5-visible-provenance-findings.v1', $fixture['schema_version']);
        $this->assertSame('60ec72b708aa5876dbee90ec12fec6dade6387414ce845f2c5ef4e4795b4ac65', $fixture['source']['artifact_sha256']);
        $this->assertSame([
            'unique_findings' => 11,
            'visible_author_fail' => 3,
            'visible_reviewer_fail' => 7,
            'visible_source_fail' => 3,
        ], $fixture['counts']);
        $this->assertCount(11, $fixture['findings']);
        $this->assertCount(11, array_unique(array_column($fixture['findings'], 'asset_id')));
        $this->assertSame(3, collect($fixture['findings'])->where('assessment.visible_author', 'FAIL')->count());
        $this->assertSame(7, collect($fixture['findings'])->where('assessment.visible_reviewer', 'FAIL')->count());
        $this->assertSame(3, collect($fixture['findings'])->where('assessment.visible_source', 'FAIL')->count());
    }

    public function test_article_requires_canonical_revision_actor_review_and_source_authority(): void
    {
        $article = new Article([
            'org_id' => 7,
            'slug' => 'big-five-guide',
            'locale' => 'en',
            'status' => 'published',
            'is_public' => true,
            'author_name' => 'FermatMind Editorial',
            'reviewer_name' => 'Content Review Desk',
            'published_revision_id' => 101,
        ]);
        $article->forceFill(['id' => 10]);
        $revision = new ArticleTranslationRevision([
            'org_id' => 7,
            'article_id' => 10,
            'locale' => 'en',
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'created_by' => 41,
            'reviewed_by' => 42,
            'reviewed_at' => '2026-07-10T00:00:00Z',
            'published_at' => '2026-07-10T00:00:00Z',
            'authority_metadata_json' => $this->metadata('published'),
        ]);
        $revision->forceFill(['id' => 101]);

        $projection = $this->projector->forArticle($article, $revision);

        $this->assertSame('admin_user:41', $projection['visible_provenance']['author']['identity']);
        $this->assertSame('Content Review Desk', $projection['visible_provenance']['reviewer']['label']);
        $this->assertSame('editorial_reviewer', $projection['visible_provenance']['reviewer']['role']);
        $this->assertSame('2026-07-10T00:00:00+00:00', $projection['visible_provenance']['reviewer']['reviewed_at']);
        $this->assertSame('published', $projection['visible_provenance']['reviewer']['review_state']);
        $this->assertSame('review-ledger:article:10:r1', $projection['visible_provenance']['reviewer']['authority_ref']);
        $this->assertSame(
            ['academic_evidence', 'internal_policy', 'product_authority'],
            array_column($projection['visible_provenance']['sources'], 'category'),
        );
        $this->assertTrue($projection['eligibility']['promotion_eligible']);
        $this->assertSame([], $projection['eligibility']['blocked_reasons']);

        $metadata = $this->metadata('published');
        data_set($metadata, 'visible_provenance.author.authority_ref', 'revision-author:');
        $revision->authority_metadata_json = $metadata;
        $this->assertNull($this->projector->forArticle($article, $revision)['visible_provenance']['author']);
        $metadata = $this->metadata('published');
        data_set($metadata, 'visible_provenance.reviewer.authority_ref', 'review-ledger:');
        $revision->authority_metadata_json = $metadata;
        $this->assertNull($this->projector->forArticle($article, $revision)['visible_provenance']['reviewer']);
        $revision->authority_metadata_json = $this->metadata('published');

        $revision->published_at = null;
        $nullPublishedAt = $this->projector->forArticle($article, $revision);
        $this->assertSame('admin_user:41', $nullPublishedAt['visible_provenance']['author']['identity']);
        $this->assertTrue($nullPublishedAt['eligibility']['promotion_eligible']);
        $revision->published_at = '2026-07-10T00:00:00Z';

        $article->published_revision_id = 102;
        $stale = $this->projector->forArticle($article, $revision);
        $this->assertSame(['author' => null, 'reviewer' => null, 'sources' => []], $stale['visible_provenance']);
        $this->assertFalse($stale['eligibility']['promotion_eligible']);

        $article->published_revision_id = 101;
        $revision->published_at = now()->addDay();
        $this->assertFalse($this->projector->forArticle($article, $revision)['eligibility']['promotion_eligible']);

        $revision->published_at = '2026-07-10T00:00:00Z';
        $article->lifecycle_state = Article::LIFECYCLE_ARCHIVED;
        $this->assertFalse($this->projector->forArticle($article, $revision)['eligibility']['promotion_eligible']);

        $article->lifecycle_state = Article::LIFECYCLE_ACTIVE;
        $revision->created_by = null;
        $missingRevisionAuthor = $this->projector->forArticle($article, $revision);
        $this->assertNull($missingRevisionAuthor['visible_provenance']['author']);
        $this->assertFalse($missingRevisionAuthor['eligibility']['promotion_eligible']);
    }

    public function test_missing_or_fabricated_reviewer_fails_closed_without_overwriting_public_content(): void
    {
        $asset = new PersonalityPublicContentAsset([
            'slug' => 'agreeableness',
            'locale' => 'en',
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'review_state' => 'approved',
            'is_public' => true,
            'last_reviewed_at' => '2026-07-10T00:00:00Z',
            'authority_json' => [
                'visible_provenance' => [
                    'author' => $this->metadata('approved')['visible_provenance']['author'],
                    'reviewer' => [
                        'identity' => 'generated:reviewer',
                        'label' => 'Generated Review Desk',
                        'role' => 'editorial_reviewer',
                        'reviewed_at' => '2026-07-10T00:00:00Z',
                        'review_state' => 'approved',
                        'authority_ref' => 'self-attested',
                    ],
                    'sources' => $this->metadata('approved')['visible_provenance']['sources'],
                ],
            ],
        ]);
        $asset->forceFill(['id' => 20, 'created_by_admin_user_id' => 41]);

        $projection = $this->projector->forPersonalityAsset($asset);

        $this->assertNull($projection['visible_provenance']['reviewer']);
        $this->assertFalse($projection['eligibility']['visible_reviewer_eligible']);
        $this->assertFalse($projection['eligibility']['promotion_eligible']);
        $this->assertContains('promotion_reviewer_gate_blocked', $projection['eligibility']['blocked_reasons']);
        $this->assertTrue($projection['preservation']['existing_public_content_preserved']);
        $this->assertFalse($projection['preservation']['missing_reviewer_overwrites_existing_content']);
        $this->assertSame([
            'institutional_certification_claimed' => false,
            'expert_endorsement_claimed' => false,
            'clinical_review_claimed' => false,
        ], $projection['claim_boundaries']);

        $metadata = $this->metadata('approved');
        data_set($metadata, 'visible_provenance.reviewer.authority_ref', 'self-attested');
        $asset->authority_json = $metadata;
        $this->assertNull($this->projector->forPersonalityAsset($asset)['visible_provenance']['reviewer']);

        $metadata = $this->metadata('approved');
        data_set($metadata, 'visible_provenance.author.identity', 'generated:author');
        $asset->authority_json = $metadata;
        $generatedAuthor = $this->projector->forPersonalityAsset($asset);
        $this->assertNull($generatedAuthor['visible_provenance']['author']);
        $this->assertFalse($generatedAuthor['eligibility']['promotion_eligible']);

        $metadata = $this->metadata('approved');
        data_set($metadata, 'visible_provenance.sources.0.authority_ref', 'self-attested');
        $asset->authority_json = $metadata;
        $selfAttestedSource = $this->projector->forPersonalityAsset($asset);
        $this->assertSame([], $selfAttestedSource['visible_provenance']['sources']);
        $this->assertFalse($selfAttestedSource['eligibility']['promotion_eligible']);

        foreach ([
            [0, 'source-ledger:academic:'],
            [1, 'policy:'],
            [2, 'product-contract:'],
        ] as [$sourceIndex, $emptyAuthorityRef]) {
            $metadata = $this->metadata('approved');
            data_set($metadata, 'visible_provenance.sources.'.$sourceIndex.'.authority_ref', $emptyAuthorityRef);
            $asset->authority_json = $metadata;
            $this->assertSame([], $this->projector->forPersonalityAsset($asset)['visible_provenance']['sources']);
        }
    }

    public function test_personality_topic_and_landing_reuse_metadata_but_drafts_expose_nothing(): void
    {
        $asset = new PersonalityPublicContentAsset([
            'slug' => 'openness', 'locale' => 'en',
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'review_state' => 'approved', 'is_public' => true,
            'last_reviewed_at' => '2026-07-10T00:00:00Z', 'authority_json' => $this->metadata('approved'),
        ]);
        $asset->forceFill(['id' => 30, 'created_by_admin_user_id' => 41]);
        $topic = new TopicProfile([
            'slug' => 'big-five', 'locale' => 'en', 'status' => TopicProfile::STATUS_PUBLISHED, 'is_public' => true,
            'published_revision_id' => 401,
        ]);
        $topic->forceFill(['id' => 40, 'published_revision_id' => 401]);
        $revision = new TopicProfileRevision([
            'profile_id' => 40,
            'created_by_admin_user_id' => 41,
            'snapshot_json' => ['profile' => $this->metadata('approved')],
        ]);
        $revision->forceFill(['id' => 401]);
        $landing = new LandingSurface([
            'surface_key' => 'big-five', 'locale' => 'en', 'status' => LandingSurface::STATUS_PUBLISHED,
            'is_public' => true, 'payload_json' => $this->metadata('approved'),
        ]);
        $landing->forceFill(['id' => 50]);

        $assetProjection = $this->projector->forPersonalityAsset($asset);
        $this->assertSame('admin_user:41', $assetProjection['visible_provenance']['author']['identity']);
        $this->assertNull($assetProjection['visible_provenance']['reviewer']);
        $this->assertFalse($assetProjection['eligibility']['promotion_eligible']);
        $this->assertCount(3, $assetProjection['visible_provenance']['sources']);

        $topicProjection = $this->projector->forTopic($topic, $revision);
        $this->assertTrue($topicProjection['eligibility']['promotion_eligible']);
        $this->assertCount(3, $topicProjection['visible_provenance']['sources']);

        $landingProjection = $this->projector->forLandingSurface($landing);
        $this->assertNull($landingProjection['visible_provenance']['reviewer']);
        $this->assertFalse($landingProjection['eligibility']['promotion_eligible']);
        $this->assertCount(3, $landingProjection['visible_provenance']['sources']);

        $asset->launch_state = PersonalityPublicContentAsset::LAUNCH_CONTENT_READY;
        $this->assertFalse($this->projector->forPersonalityAsset($asset)['eligibility']['promotion_eligible']);
        foreach (['operator_approved_content_ready', 'seo_discoverability_released'] as $releaseReviewState) {
            $asset->review_state = $releaseReviewState;
            $asset->authority_json = $this->metadata($releaseReviewState);
            $releaseProjection = $this->projector->forPersonalityAsset($asset);
            $this->assertNull($releaseProjection['visible_provenance']['reviewer']);
            $this->assertFalse($releaseProjection['eligibility']['promotion_eligible']);
        }
        $asset->published_at = now()->addDay();
        $this->assertFalse($this->projector->forPersonalityAsset($asset)['eligibility']['promotion_eligible']);

        $topic->published_at = now()->addDay();
        $this->assertFalse($this->projector->forTopic($topic, $revision)['eligibility']['promotion_eligible']);
        $topic->published_at = null;

        $revision->created_by_admin_user_id = null;
        $missingTopicAuthor = $this->projector->forTopic($topic, $revision);
        $this->assertNull($missingTopicAuthor['visible_provenance']['author']);
        $this->assertFalse($missingTopicAuthor['eligibility']['promotion_eligible']);
        $revision->created_by_admin_user_id = 99;
        $mismatchedTopicAuthor = $this->projector->forTopic($topic, $revision);
        $this->assertNull($mismatchedTopicAuthor['visible_provenance']['author']);
        $this->assertFalse($mismatchedTopicAuthor['eligibility']['promotion_eligible']);
        $revision->created_by_admin_user_id = 41;

        $revision->snapshot_json = $this->metadata('approved');
        $rootMetadata = $this->projector->forTopic($topic, $revision);
        $this->assertSame(['author' => null, 'reviewer' => null, 'sources' => []], $rootMetadata['visible_provenance']);
        $this->assertFalse($rootMetadata['eligibility']['promotion_eligible']);

        $landing->status = LandingSurface::STATUS_DRAFT;
        $draft = $this->projector->forLandingSurface($landing);
        $this->assertSame(['author' => null, 'reviewer' => null, 'sources' => []], $draft['visible_provenance']);
        $this->assertFalse($draft['eligibility']['promotion_eligible']);
    }

    /** @return array<string, mixed> */
    private function metadata(string $reviewState): array
    {
        return [
            'visible_provenance' => [
                'author' => [
                    'identity' => 'admin_user:41',
                    'label' => 'FermatMind Editorial',
                    'role' => 'editorial_author',
                    'authority_ref' => 'revision-author:41',
                ],
                'reviewer' => [
                    'identity' => 'admin_user:42',
                    'label' => 'Content Review Desk',
                    'role' => 'editorial_reviewer',
                    'reviewed_at' => '2026-07-10T00:00:00Z',
                    'review_state' => $reviewState,
                    'authority_ref' => 'review-ledger:article:10:r1',
                ],
                'sources' => [
                    ['source_id' => 'academic:1', 'label' => 'Peer-reviewed research', 'category' => 'academic_evidence', 'authority_ref' => 'source-ledger:academic:1'],
                    ['source_id' => 'policy:1', 'label' => 'FermatMind claim boundary', 'category' => 'internal_repository_evidence', 'authority_ref' => 'policy:claim-boundary:v1'],
                    ['source_id' => 'product:1', 'label' => 'Big Five public contract', 'category' => 'official_product_evidence', 'authority_ref' => 'product-contract:big5:v2'],
                ],
            ],
        ];
    }
}
