<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Services\BigFive\AuthorityV2\DiscoverabilityParity\BigFiveDiscoverabilityParityProjector;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class BigFiveAuthorityV244Test extends TestCase
{
    private BigFiveDiscoverabilityParityProjector $projector;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $this->projector = app(BigFiveDiscoverabilityParityProjector::class);
    }

    public function test_fixture_locks_exact_three_hreflang_and_three_llms_findings(): void
    {
        $path = base_path('../generated/big-five-authority-v2/big5-authority-v2-discoverability-parity-44/discoverability-parity-findings.json');
        $fixture = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('big5-discoverability-parity-findings.v1', $fixture['schema_version']);
        $this->assertSame('60ec72b708aa5876dbee90ec12fec6dade6387414ce845f2c5ef4e4795b4ac65', $fixture['source']['artifact_sha256']);
        $this->assertSame([
            'findings' => 6,
            'unique_assets' => 4,
            'hreflang_fail' => 3,
            'llms_fail' => 3,
        ], $fixture['counts']);
        $this->assertCount(6, $fixture['findings']);
        $this->assertSame(3, collect($fixture['findings'])->where('surface', 'hreflang')->count());
        $this->assertSame(3, collect($fixture['findings'])->where('surface', 'llms.txt')->count());
        $this->assertSame(
            ['no_hreflang'],
            collect($fixture['findings'])->where('surface', 'hreflang')->pluck('expected_policy')->unique()->values()->all(),
        );
    }

    public function test_hreflang_requires_a_real_reciprocal_published_bilingual_pair(): void
    {
        [$zh, $zhRevision] = $this->articleWithRevision(10, 101, 'zh-CN', 'zh-guide', true);
        [$en, $enRevision] = $this->articleWithRevision(11, 102, 'en', 'english-guide', true);
        $zh->translation_group_id = 'group:big-five-guide';
        $en->translation_group_id = 'group:big-five-guide';

        $projection = $this->projector->forArticle($zh, $zhRevision, $en, $enRevision);

        $this->assertSame('reciprocal_bilingual_counterparts', $projection['hreflang']['policy']);
        $this->assertTrue($projection['hreflang']['output_eligible']);
        $this->assertSame([
            'en' => 'https://fermatmind.com/en/articles/english-guide',
            'zh-CN' => 'https://fermatmind.com/zh/articles/zh-guide',
            'x-default' => 'https://fermatmind.com/en/articles/english-guide',
        ], $projection['hreflang']['alternates']);

        config(['app.frontend_url' => '/relative-host-is-not-canonical']);
        $invalidCanonicalBase = $this->projector->forArticle($zh, $zhRevision, $en, $enRevision);
        $this->assertSame('withheld', $invalidCanonicalBase['hreflang']['policy']);
        $this->assertFalse($invalidCanonicalBase['hreflang']['output_eligible']);
        $this->assertSame([], $invalidCanonicalBase['hreflang']['alternates']);
        $this->assertContains('canonical_public_base_url_missing', $invalidCanonicalBase['hreflang']['blocked_reasons']);
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $en->translation_group_id = 'group:unrelated-same-slug';
        $en->slug = 'zh-guide';
        $unrelated = $this->projector->forArticle($zh, $zhRevision, $en, $enRevision);
        $this->assertSame('no_hreflang', $unrelated['hreflang']['policy']);
        $this->assertFalse($unrelated['hreflang']['output_eligible']);
        $this->assertSame([], $unrelated['hreflang']['alternates']);
        $this->assertContains('reciprocal_published_counterpart_missing', $unrelated['hreflang']['blocked_reasons']);
    }

    public function test_missing_or_unpublished_counterpart_uses_explicit_no_hreflang_policy(): void
    {
        [$article, $revision] = $this->articleWithRevision(20, 201, 'zh-CN', 'big-five-growth-guide', true);

        $missing = $this->projector->forArticle($article, $revision);
        $this->assertTrue($missing['hreflang']['policy_valid']);
        $this->assertSame('no_hreflang', $missing['hreflang']['policy']);
        $this->assertSame([], $missing['hreflang']['alternates']);

        [$counterpart, $counterpartRevision] = $this->articleWithRevision(21, 202, 'en', 'big-five-growth-guide', true);
        $counterpart->translation_group_id = $article->translation_group_id;
        $counterpart->status = 'draft';
        $draftCounterpart = $this->projector->forArticle($article, $revision, $counterpart, $counterpartRevision);
        $this->assertSame('no_hreflang', $draftCounterpart['hreflang']['policy']);
        $this->assertFalse($draftCounterpart['hreflang']['counterpart_authority_eligible']);
    }

    public function test_llms_membership_requires_backend_published_indexable_public_safe_authority(): void
    {
        [$article, $revision] = $this->articleWithRevision(30, 301, 'zh-CN', 'big-five-narrative-portrait', true);
        $article->sitemap_eligible = false;

        $eligible = $this->projector->forArticle($article, $revision);
        $this->assertTrue($eligible['llms']['membership_eligible']);
        $this->assertSame([], $eligible['llms']['blocked_reasons']);
        $this->assertFalse($eligible['preservation']['sitemap_behavior_mutated']);

        $article->llms_eligible = false;
        $backendDisabled = $this->projector->forArticle($article, $revision);
        $this->assertFalse($backendDisabled['llms']['membership_eligible']);
        $this->assertContains('backend_llms_eligibility_disabled', $backendDisabled['llms']['blocked_reasons']);

        $article->llms_eligible = true;
        $article->is_indexable = false;
        $noindex = $this->projector->forArticle($article, $revision);
        $this->assertFalse($noindex['llms']['membership_eligible']);
        $this->assertContains('current_published_indexable_public_authority_missing', $noindex['llms']['blocked_reasons']);
    }

    public function test_drafts_stale_revisions_and_future_publications_never_expand_discoverability(): void
    {
        [$article, $revision] = $this->articleWithRevision(40, 401, 'zh-CN', 'big-five-draft', true);

        $article->status = 'draft';
        $draft = $this->projector->forArticle($article, $revision);
        $this->assertSame('withheld', $draft['hreflang']['policy']);
        $this->assertFalse($draft['llms']['membership_eligible']);
        $this->assertFalse($draft['preservation']['draft_discoverability_expanded']);

        $article->status = 'published';
        $article->published_revision_id = 999;
        $stale = $this->projector->forArticle($article, $revision);
        $this->assertFalse($stale['current_public_authority_eligible']);
        $this->assertFalse($stale['llms']['membership_eligible']);

        $article->published_revision_id = 401;
        $revision->published_at = now()->addDay();
        $scheduled = $this->projector->forArticle($article, $revision);
        $this->assertFalse($scheduled['current_public_authority_eligible']);
        $this->assertFalse($scheduled['hreflang']['output_eligible']);

        $revision->published_at = null;
        $article->locale = 'fr';
        $unsupportedLocale = $this->projector->forArticle($article, $revision);
        $this->assertFalse($unsupportedLocale['current_public_authority_eligible']);
        $this->assertFalse($unsupportedLocale['llms']['membership_eligible']);

        $article->locale = 'zh-CN';
        $article->lifecycle_state = Article::LIFECYCLE_ARCHIVED;
        $archived = $this->projector->forArticle($article, $revision);
        $this->assertFalse($archived['current_public_authority_eligible']);
        $this->assertFalse($archived['llms']['membership_eligible']);
    }

    /** @return array{Article, ArticleTranslationRevision} */
    private function articleWithRevision(
        int $articleId,
        int $revisionId,
        string $locale,
        string $slug,
        bool $llmsEligible,
    ): array {
        $article = new Article([
            'org_id' => 7,
            'slug' => $slug,
            'locale' => $locale,
            'translation_group_id' => 'group:'.$slug,
            'status' => 'published',
            'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
            'is_public' => true,
            'is_indexable' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => $llmsEligible,
            'published_revision_id' => $revisionId,
        ]);
        $article->forceFill(['id' => $articleId]);
        $revision = new ArticleTranslationRevision([
            'org_id' => 7,
            'article_id' => $articleId,
            'locale' => $locale,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'published_at' => '2026-07-10T00:00:00Z',
        ]);
        $revision->forceFill(['id' => $revisionId]);

        return [$article, $revision];
    }
}
