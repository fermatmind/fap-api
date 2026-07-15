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
            'authority_metadata_json' => $this->metadata('published'),
        ]);

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
        $asset->forceFill(['id' => 20]);

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
    }

    public function test_personality_topic_and_landing_reuse_metadata_but_drafts_expose_nothing(): void
    {
        $asset = new PersonalityPublicContentAsset([
            'slug' => 'openness', 'locale' => 'en',
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'review_state' => 'approved', 'is_public' => true,
            'last_reviewed_at' => '2026-07-10T00:00:00Z', 'authority_json' => $this->metadata('approved'),
        ]);
        $asset->forceFill(['id' => 30]);
        $topic = new TopicProfile([
            'slug' => 'big-five', 'locale' => 'en', 'status' => TopicProfile::STATUS_PUBLISHED, 'is_public' => true,
        ]);
        $topic->forceFill(['id' => 40]);
        $revision = new TopicProfileRevision(['profile_id' => 40, 'snapshot_json' => $this->metadata('approved')]);
        $landing = new LandingSurface([
            'surface_key' => 'big-five', 'locale' => 'en', 'status' => LandingSurface::STATUS_PUBLISHED,
            'is_public' => true, 'payload_json' => $this->metadata('approved'),
        ]);
        $landing->forceFill(['id' => 50]);

        foreach ([
            $this->projector->forPersonalityAsset($asset),
            $this->projector->forTopic($topic, $revision),
            $this->projector->forLandingSurface($landing),
        ] as $projection) {
            $this->assertTrue($projection['eligibility']['promotion_eligible']);
            $this->assertCount(3, $projection['visible_provenance']['sources']);
        }

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
