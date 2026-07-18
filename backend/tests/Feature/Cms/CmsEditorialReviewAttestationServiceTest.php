<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Models\EditorialReview;
use App\Models\InterpretationGuide;
use App\Models\ResearchReport;
use App\Models\ReviewAttestation;
use App\Models\SupportArticle;
use App\Services\Cms\CmsEditorialReviewAttestationService;
use App\Services\ReviewGovernance\ReviewAttestationFactory;
use App\Services\ReviewGovernance\ReviewAttestationValidationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CmsEditorialReviewAttestationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('review_governance.mode', 'solo_owner');
        config()->set('review_governance.solo_owner_admin_user_id', 1);
    }

    public function test_all_pr2_resource_surfaces_build_exact_private_targets(): void
    {
        $resources = [
            $this->resource('article', new Article, 1),
            $this->resource('article_translation_revision', new ArticleTranslationRevision, 2),
            $this->resource('cms_translation_revision', new CmsTranslationRevision, 3),
            $this->resource('content_page', new ContentPage, 4),
            $this->resource('support_article', new SupportArticle, 5),
            $this->resource('interpretation_guide', new InterpretationGuide, 6),
            $this->resource('research_report', new ResearchReport, 7),
            $this->resource('editorial_review', new EditorialReview, 'review-8'),
        ];

        $service = app(CmsEditorialReviewAttestationService::class);
        $forward = $service->targets($resources);
        $reverse = $service->targets(array_reverse($resources));

        $this->assertCount(8, $forward);
        $this->assertSame(
            array_values(array_reverse(array_column($forward, 'target_identity'))),
            array_column($reverse, 'target_identity'),
        );
        foreach ($forward as $target) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $target['target_sha256']);
            $this->assertStringNotContainsString('private body', $target['target_identity']);
        }
    }

    public function test_single_and_batch_compact_attestations_expand_exact_evidence_without_publication_changes(): void
    {
        $first = $this->article('cms-review-first');
        $second = $this->article('cms-review-second');
        $resources = [
            ['surface_id' => 'article', 'record' => $first],
            ['surface_id' => 'article', 'record' => $second],
        ];
        $service = app(CmsEditorialReviewAttestationService::class);
        $targets = $service->targets($resources);
        $attestation = app(ReviewAttestationFactory::class)->make(
            'cms_resource_batch',
            'articles:cms-review-batch',
            'approved_all',
            array_reverse($targets),
        );

        $preflight = $service->preflight($attestation, $resources);
        $this->assertSame('PASS_SOLO_OWNER_ATTESTATION_PREFLIGHT', $preflight['status']);
        $this->assertSame(0, $preflight['database_writes']);
        $this->assertDatabaseCount('review_attestations', 0);

        $bound = $service->bindApproved($attestation, $resources, 1);

        $this->assertSame(2, $bound->targetEvidences->count());
        $this->assertSame(['approved'], $bound->targetEvidences->pluck('target_decision')->unique()->values()->all());
        $this->assertSame('draft', $first->refresh()->status);
        $this->assertSame('draft', $second->refresh()->status);
        $this->assertFalse((bool) $first->is_public);
        $this->assertFalse((bool) $second->is_public);
    }

    public function test_approved_with_exceptions_is_preflightable_but_state_changing_bind_fails_closed(): void
    {
        $first = $this->article('cms-review-exception-first');
        $second = $this->article('cms-review-exception-second');
        $resources = [
            ['surface_id' => 'article', 'record' => $first],
            ['surface_id' => 'article', 'record' => $second],
        ];
        $service = app(CmsEditorialReviewAttestationService::class);
        $targets = $service->targets($resources);
        $attestation = app(ReviewAttestationFactory::class)->make(
            'cms_resource_batch',
            'articles:cms-review-exceptions',
            'approved_with_exceptions',
            $targets,
            exceptions: [[
                'target_identity' => $targets[1]['target_identity'],
                'reason' => 'private correction remains',
            ]],
        );

        $this->assertSame(1, $service->preflight($attestation, $resources)['exception_count']);

        try {
            $service->bindApproved($attestation, $resources, 1);
            $this->fail('An exception batch changed CMS review state.');
        } catch (ReviewAttestationValidationException $exception) {
            $this->assertStringContainsString('fail closed', $exception->getMessage());
        }

        $this->assertSame(0, ReviewAttestation::query()->count());
    }

    public function test_non_configured_actor_and_team_separated_mode_cannot_forge_solo_approval(): void
    {
        $article = $this->article('cms-review-owner-boundary');
        $resources = [['surface_id' => 'article', 'record' => $article]];
        $service = app(CmsEditorialReviewAttestationService::class);

        foreach ([2, 999] as $actorId) {
            try {
                $service->bindOrCreateApproved(
                    null,
                    'cms_resource',
                    'article:'.$article->id,
                    $resources,
                    $actorId,
                );
                $this->fail('A non-configured actor forged solo approval.');
            } catch (ReviewAttestationValidationException $exception) {
                $this->assertStringContainsString('configured owner', $exception->getMessage());
            }
        }

        config()->set('review_governance.mode', 'team_separated');
        try {
            $service->bindOrCreateApproved(
                null,
                'cms_resource',
                'article:'.$article->id,
                $resources,
                1,
            );
            $this->fail('Team-separated mode accepted a solo-owner attestation.');
        } catch (ReviewAttestationValidationException $exception) {
            $this->assertStringContainsString('configured owner', $exception->getMessage());
        }

        $this->assertSame(0, ReviewAttestation::query()->count());
    }

    public function test_approved_evidence_survives_state_transitions_but_not_content_edits(): void
    {
        $article = $this->article('cms-review-state-transition');
        $resources = [['surface_id' => 'article', 'record' => $article]];
        $service = app(CmsEditorialReviewAttestationService::class);
        $reviewedTargets = $service->targets($resources);
        $service->bindOrCreateApproved(
            null,
            'cms_resource',
            'article:'.$article->id,
            $resources,
            1,
        );

        $this->assertSame(
            $reviewedTargets,
            $service->targets([['surface_id' => 'article', 'record' => $article->refresh()]]),
        );
        $this->assertTrue($service->hasApprovedEvidence('article', $article->refresh()));

        $article->forceFill([
            'status' => 'published',
            'is_public' => true,
            'published_at' => now(),
        ])->save();
        $this->assertTrue($service->hasApprovedEvidence('article', $article->refresh()));

        $article->forceFill(['title' => 'Changed after review'])->save();
        $this->assertFalse($service->hasApprovedEvidence('article', $article->refresh()));
    }

    /** @return array{surface_id:string,record:Model} */
    private function resource(string $surfaceId, Model $record, int|string $key): array
    {
        $record->setRawAttributes([
            'id' => $key,
            'title' => 'private body',
            'updated_at' => '2026-07-18 00:00:00',
        ], true);
        $record->exists = true;

        return ['surface_id' => $surfaceId, 'record' => $record];
    }

    private function article(string $slug): Article
    {
        return Article::query()->create([
            'org_id' => 0,
            'slug' => $slug.'-'.Str::lower(Str::random(6)),
            'locale' => 'en',
            'title' => 'Private editorial title',
            'excerpt' => 'Private editorial excerpt.',
            'content_md' => 'Private editorial body.',
            'content_html' => '<p>Private editorial body.</p>',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => false,
        ]);
    }
}
