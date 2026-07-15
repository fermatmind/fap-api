<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\BigFive\AuthorityV2\StructuredData\BigFiveStructuredDataProjector;
use App\Services\Cms\ArticleSeoService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class BigFiveAuthorityV245Test extends TestCase
{
    public function test_fixture_locks_exact_nine_faq_and_four_article_breadcrumb_findings(): void
    {
        $fixture = json_decode(File::get($this->fixturePath()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('big5-structured-data-findings.v1', $fixture['schema_version']);
        $this->assertSame('60ec72b708aa5876dbee90ec12fec6dade6387414ce845f2c5ef4e4795b4ac65', $fixture['source']['artifact_sha256']);
        $this->assertSame([
            'findings' => 13,
            'unique_assets' => 9,
            'faq_json_ld_fail' => 9,
            'article_breadcrumb_fail' => 4,
        ], $fixture['counts']);
        $this->assertCount(9, $fixture['assets']);
        $this->assertCount(9, collect($fixture['assets'])->where('assessment.faq_json_ld', 'FAIL'));
        $this->assertCount(4, collect($fixture['assets'])->where('assessment.json_ld', 'FAIL'));
        $this->assertSame(['CMS Article'], collect($fixture['assets'])->pluck('authority_surface')->unique()->values()->all());
    }

    public function test_projector_aligns_article_breadcrumb_faq_dates_author_and_sources_with_visible_authority(): void
    {
        [$article, $revision] = $this->authorityArticle();
        $projection = app(BigFiveStructuredDataProjector::class)->forArticle(
            $article,
            $revision,
            $this->context(),
        );

        $this->assertTrue($projection['current_public_authority_eligible']);
        $this->assertTrue(data_get($projection, 'eligibility.article.enabled'));
        $this->assertTrue(data_get($projection, 'eligibility.breadcrumb_list.enabled'));
        $this->assertTrue(data_get($projection, 'eligibility.faq_page.enabled'));
        $this->assertSame('https://fermatmind.com/en/articles/big-five-growth-guide', data_get($projection, 'fragments.article.url'));
        $this->assertSame('FermatMind Editorial', data_get($projection, 'fragments.article.author.name'));
        $this->assertSame('2026-07-10T00:00:00+00:00', data_get($projection, 'fragments.article.datePublished'));
        $this->assertSame('2026-07-12T00:00:00+00:00', data_get($projection, 'fragments.article.dateModified'));
        $this->assertSame(['Peer-reviewed research', 'FermatMind claim boundary'], data_get($projection, 'fragments.article.citation'));
        $this->assertSame('FAQPage', data_get($projection, 'fragments.article.hasPart.0.@type'));
        $this->assertSame(['What can Big Five help with?'], collect(data_get($projection, 'fragments.faq_page.mainEntity'))->pluck('name')->all());
        $this->assertSame('Content Review Desk', data_get($projection, 'visible_alignment.reviewer_gate.label'));
        $this->assertSame('BreadcrumbList', data_get($projection, 'fragments.breadcrumb_list.@type'));
        $this->assertTrue(data_get($projection, 'preservation.reviewer_is_eligibility_gate_not_article_property'));
        $this->assertArrayNotHasKey('reviewedBy', data_get($projection, 'fragments.article'));
    }

    public function test_projector_fails_closed_for_false_gates_missing_review_and_non_current_authority(): void
    {
        [$article, $revision] = $this->authorityArticle();
        $projector = app(BigFiveStructuredDataProjector::class);

        $context = $this->context();
        data_set($context, 'editorial_package.article_schema_enabled', false);
        data_set($context, 'editorial_package.breadcrumb_schema_enabled', false);
        data_set($context, 'editorial_package.faq_schema_enabled', false);
        $falseGates = $projector->forArticle($article, $revision, $context);
        $this->assertSame(['article' => null, 'breadcrumb_list' => null, 'faq_page' => null], $falseGates['fragments']);

        $metadata = (array) $revision->authority_metadata_json;
        data_set($metadata, 'visible_provenance.reviewer', null);
        $revision->authority_metadata_json = $metadata;
        $missingReview = $projector->forArticle($article, $revision, $this->context());
        $this->assertFalse($missingReview['current_public_authority_eligible']);
        $this->assertSame(['article' => null, 'breadcrumb_list' => null, 'faq_page' => null], $missingReview['fragments']);

        [, $revision] = $this->authorityArticle();
        foreach (['draft', 'noindex', 'seo_noindex', 'stale', 'future'] as $case) {
            [$candidate, $candidateRevision] = $this->authorityArticle();
            $candidateContext = $this->context();
            match ($case) {
                'draft' => $candidate->status = 'draft',
                'noindex' => $candidate->is_indexable = false,
                'seo_noindex' => $candidateContext['robots'] = 'noindex,nofollow',
                'stale' => $candidate->published_revision_id = 999,
                'future' => $candidateRevision->published_at = now()->addDay(),
            };
            $held = $projector->forArticle($candidate, $candidateRevision, $candidateContext);
            $this->assertFalse($held['current_public_authority_eligible'], $case);
            $this->assertNull(data_get($held, 'fragments.article'), $case);
            $this->assertNull(data_get($held, 'fragments.breadcrumb_list'), $case);
            $this->assertNull(data_get($held, 'fragments.faq_page'), $case);
        }
    }

    public function test_article_seo_service_uses_backend_projection_and_rejects_raw_schema_bypass(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        [$article, $revision] = $this->authorityArticle();
        $seo = new ArticleSeoMeta([
            'org_id' => 7,
            'article_id' => 10,
            'locale' => 'en',
            'seo_title' => 'Big Five growth guide',
            'seo_description' => 'Visible description.',
            'schema_json' => [
                '@type' => 'FabricatedSchemaBypass',
                'author' => ['name' => 'Invented Author'],
                'editorial_package_v1' => $this->context()['editorial_package'],
            ],
        ]);
        $article->setRelation('seoMeta', $seo);

        $jsonLd = app(ArticleSeoService::class)->generateJsonLd($article, $revision, true);

        $this->assertSame('Article', $jsonLd['@type']);
        $this->assertSame('FermatMind Editorial', data_get($jsonLd, 'author.name'));
        $this->assertSame('FAQPage', data_get($jsonLd, 'hasPart.0.@type'));
        $this->assertStringNotContainsString('FabricatedSchemaBypass', json_encode($jsonLd, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Invented Author', json_encode($jsonLd, JSON_THROW_ON_ERROR));
    }

    /** @return array{Article, ArticleTranslationRevision} */
    private function authorityArticle(): array
    {
        $article = new Article([
            'org_id' => 7,
            'slug' => 'big-five-growth-guide',
            'locale' => 'en',
            'title' => 'Big Five growth guide',
            'excerpt' => 'Visible description.',
            'content_md' => '# Big Five growth guide',
            'status' => 'published',
            'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
            'is_public' => true,
            'is_indexable' => true,
            'author_name' => 'FermatMind Editorial',
            'reviewer_name' => 'Content Review Desk',
            'published_revision_id' => 20,
            'published_at' => '2026-07-10T00:00:00Z',
        ]);
        $article->forceFill(['id' => 10, 'updated_at' => '2026-07-12T00:00:00Z']);
        $revision = new ArticleTranslationRevision([
            'org_id' => 7,
            'article_id' => 10,
            'locale' => 'en',
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => 'Big Five growth guide',
            'excerpt' => 'Visible description.',
            'content_md' => '# Big Five growth guide',
            'created_by' => 41,
            'reviewed_by' => 42,
            'reviewed_at' => '2026-07-11T00:00:00Z',
            'published_at' => '2026-07-10T00:00:00Z',
            'authority_metadata_json' => $this->authorityMetadata(),
        ]);
        $revision->forceFill(['id' => 20, 'updated_at' => '2026-07-12T00:00:00Z']);

        return [$article, $revision];
    }

    /** @return array<string, mixed> */
    private function authorityMetadata(): array
    {
        return [
            'date_provenance' => [
                'updated_at' => [
                    'value' => '2026-07-12T00:00:00Z',
                    'source_kind' => 'editorial_update',
                    'authority_ref' => 'cms-edit:article:10:r1',
                ],
            ],
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
                    'reviewed_at' => '2026-07-11T00:00:00Z',
                    'review_state' => 'published',
                    'authority_ref' => 'review-ledger:article:10:r1',
                ],
                'sources' => [
                    ['source_id' => 'academic:1', 'label' => 'Peer-reviewed research', 'category' => 'academic_evidence', 'authority_ref' => 'source-ledger:academic:1'],
                    ['source_id' => 'policy:1', 'label' => 'FermatMind claim boundary', 'category' => 'internal_policy', 'authority_ref' => 'policy:claim-boundary:v1'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return [
            'canonical' => 'https://fermatmind.com/en/articles/big-five-growth-guide',
            'headline' => 'Big Five growth guide',
            'description' => 'Visible description.',
            'breadcrumb_root_url' => 'https://fermatmind.com/en/articles',
            'editorial_package' => [
                'article_schema_enabled' => true,
                'breadcrumb_schema_enabled' => true,
                'faq_schema_enabled' => true,
                'answer_surface_policy' => 'editor_supplied',
                'answer_surface_visibility' => 'below_intro',
                'answer_surface_v1' => [
                    'faq_items' => [
                        ['question' => 'What can Big Five help with?', 'answer' => 'It supports reflection and action planning.'],
                        ['question' => 'Hidden fallback?', 'answer' => 'Never expose this.', 'hidden' => true],
                    ],
                ],
            ],
        ];
    }

    private function fixturePath(): string
    {
        return base_path('../generated/big-five-authority-v2/big5-authority-v2-structured-data-45/structured-data-findings.json');
    }
}
