<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTag;
use App\Models\ArticleTranslationRevision;
use App\Services\SeoIntel\ContentOps\ContentPublishRehearsalDryRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

final class SeoIntelContentPublishRehearsalDryRunTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function service_reports_safe_article_rehearsal_without_writes_or_publish(): void
    {
        $article = $this->createRehearsalArticle([
            'is_indexable' => true,
            'robots' => 'index,follow',
        ]);

        $before = $this->articleSnapshot($article);
        $report = (new ContentPublishRehearsalDryRun)->report([(int) $article->id], [], makeIndexable: true);

        $this->assertSame('content_publish_rehearsal', $report['runtime']);
        $this->assertSame('success', $report['status']);
        $this->assertSame('safe', $report['rehearsal_state']);
        $this->assertTrue($report['dry_run']);
        $this->assertTrue($report['no_write']);
        $this->assertFalse($report['writes_attempted']);
        $this->assertFalse($report['cms_mutation_attempted']);
        $this->assertFalse($report['article_publish_attempted']);
        $this->assertFalse($report['search_channel_enqueue_attempted']);
        $this->assertFalse($report['search_submission_attempted']);
        $this->assertFalse($report['sitemap_mutation_attempted']);
        $this->assertFalse($report['llms_mutation_attempted']);
        $this->assertFalse($report['observation_queue_write_attempted']);
        $this->assertSame('safe', $report['claim_lint_state']);
        $this->assertSame('safe', $report['internal_link_readiness_state']);
        $this->assertSame('dry_run_eligible_after_manual_publish_review', $report['search_channel_eligibility_state']);
        $this->assertContains('published', $report['planned_observation_events']);
        $this->assertSame([], $report['blockers']);

        $this->assertSame($before, $this->articleSnapshot($article));
    }

    #[Test]
    public function service_blocks_claim_unsafe_article_without_auto_fixing_content(): void
    {
        $article = $this->createRehearsalArticle([
            'claim_result_json' => [
                'status' => 'blocked',
                'matches' => [
                    [
                        'field' => 'body_markdown',
                        'code' => 'claim_boundary_forbidden_phrase',
                        'text' => '精准职业推荐',
                        'boundary_context' => false,
                    ],
                ],
            ],
            'import_status' => ArticleEditorialPackageImport::STATUS_BLOCKED,
        ]);

        $before = $this->articleSnapshot($article);
        $report = (new ContentPublishRehearsalDryRun)->report([(int) $article->id], [], makeIndexable: true);

        $this->assertSame('blocked', $report['status']);
        $this->assertSame('blocked', $report['rehearsal_state']);
        $this->assertSame('blocked', $report['claim_lint_state']);
        $this->assertFalse($report['cms_mutation_attempted']);
        $this->assertFalse($report['article_publish_attempted']);
        $this->assertContains('claim_lint_blocked', array_column($report['blockers'], 'code'));
        $this->assertContains('invalid_import_status', array_column($report['blockers'], 'code'));

        $this->assertSame($before, $this->articleSnapshot($article));
    }

    #[Test]
    public function command_requires_dry_run_no_write_and_json(): void
    {
        $exitCode = Artisan::call('seo-intel:content-publish-rehearsal');

        $this->assertSame(Command::FAILURE, $exitCode);

        $output = Artisan::output();

        $this->assertStringContainsString('status=blocked', $output);
        $this->assertStringContainsString('dry_run=0', $output);
        $this->assertStringContainsString('no_write=0', $output);
        $this->assertStringContainsString('writes_attempted=0', $output);
    }

    #[Test]
    public function command_emits_json_report_and_does_not_publish_or_write(): void
    {
        $article = $this->createRehearsalArticle([
            'is_indexable' => true,
            'robots' => 'index,follow',
        ]);

        $before = $this->articleSnapshot($article);
        $exitCode = Artisan::call('seo-intel:content-publish-rehearsal', [
            '--article' => [(string) $article->id],
            '--make-indexable' => true,
            '--dry-run' => true,
            '--no-write' => true,
            '--json' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $payload = $this->artisanJsonOutput();

        $this->assertSame('content_publish_rehearsal', $payload['runtime']);
        $this->assertSame('safe', $payload['rehearsal_state']);
        $this->assertTrue($payload['dry_run']);
        $this->assertTrue($payload['no_write']);
        $this->assertFalse($payload['writes_attempted']);
        $this->assertFalse($payload['cms_mutation_attempted']);
        $this->assertFalse($payload['article_publish_attempted']);
        $this->assertFalse($payload['search_channel_enqueue_attempted']);
        $this->assertFalse($payload['search_submission_attempted']);
        $this->assertFalse($payload['observation_queue_write_attempted']);

        $this->assertSame($before, $this->articleSnapshot($article));
    }

    #[Test]
    public function command_help_exposes_no_publish_write_submit_or_scheduler_options(): void
    {
        Artisan::call('seo-intel:content-publish-rehearsal --help');
        $help = Artisan::output();

        foreach ([
            '--publish',
            '--execute',
            '--write',
            '--submit',
            '--scheduler',
            '--production',
        ] as $forbiddenOption) {
            $this->assertDoesNotMatchRegularExpression('/(^|\\s)'.preg_quote($forbiddenOption, '/').'(=|\\s|$)/', $help);
        }

        foreach ([
            '--article',
            '--dry-run',
            '--no-write',
            '--json',
        ] as $allowedOption) {
            $this->assertStringContainsString($allowedOption, $help);
        }
    }

    #[Test]
    public function docs_and_generated_artifact_lock_dry_run_runtime_boundary(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/content-publish-rehearsal-dry-run.md')));
        $artifact = $this->artifact();
        $artifactJson = strtolower((string) json_encode($artifact, JSON_THROW_ON_ERROR));

        $this->assertSame('content-publish-rehearsal-dry-run.v1', $artifact['version'] ?? null);
        $this->assertSame('CONTENT-OPS-02B', $artifact['task'] ?? null);
        $this->assertSame('INTERNAL-LINK-01A', $artifact['next_task'] ?? null);
        $this->assertSame('App\\Services\\SeoIntel\\ContentOps\\ContentPublishRehearsalDryRun', $artifact['service'] ?? null);
        $this->assertTrue($artifact['safety_flags']['no_cms_mutation'] ?? false);
        $this->assertTrue($artifact['safety_flags']['no_article_publish'] ?? false);
        $this->assertTrue($artifact['safety_flags']['no_observation_queue_write'] ?? false);
        $this->assertTrue($artifact['safety_flags']['no_search_channel_enqueue'] ?? false);

        foreach ([
            'dry-run validator',
            'does not publish',
            'does not mutate cms',
            'does not write `seo_intel`',
            'does not write observation queue rows',
            'does not enqueue search channel queue rows',
            'does not submit urls',
            'does not change sitemap',
            'does not touch fap-web',
            'planned observation queue',
            'next task: `internal-link-01a`',
            '"next_task":"internal-link-01a"',
        ] as $required) {
            $this->assertStringContainsString($required, str_replace(' ', '', $artifactJson) === $artifactJson ? $doc."\n".$artifactJson : $doc."\n".$artifactJson);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createRehearsalArticle(array $overrides = []): Article
    {
        $category = ArticleCategory::query()->withoutGlobalScopes()->firstOrCreate(
            ['org_id' => 0, 'slug' => 'career-development'],
            ['name' => 'Career Development', 'is_active' => true]
        );
        $tag = ArticleTag::query()->withoutGlobalScopes()->firstOrCreate(
            ['org_id' => 0, 'slug' => 'mbti'],
            ['name' => 'MBTI', 'is_active' => true]
        );
        $body = "# Rehearsal Draft\n\nBody.\n\n## FAQ\n\n### Q\n\nA.";
        $bodyHash = hash('sha256', preg_replace("/\r\n?/", "\n", trim($body)));
        $locale = (string) ($overrides['locale'] ?? 'en');
        $slug = (string) ($overrides['slug'] ?? 'rehearsal-draft');

        $article = Article::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'category_id' => (int) $category->id,
            'slug' => $slug,
            'locale' => $locale,
            'title' => 'Rehearsal Draft',
            'excerpt' => 'Rehearsal excerpt',
            'content_md' => $body,
            'cover_image_url' => '/storage/articles/rehearsal.svg',
            'cover_image_alt' => 'Rehearsal cover alt',
            'cover_image_width' => 1200,
            'cover_image_height' => 675,
            'status' => (string) ($overrides['status'] ?? 'draft'),
            'lifecycle_state' => $overrides['lifecycle_state'] ?? Article::LIFECYCLE_ACTIVE,
            'is_public' => (bool) ($overrides['is_public'] ?? false),
            'is_indexable' => (bool) ($overrides['is_indexable'] ?? false),
        ]);
        $article->tags()->attach((int) $tag->id, ['org_id' => 0]);

        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => $locale,
            'source_locale' => $locale,
            'revision_number' => 1,
            'revision_status' => (string) ($overrides['revision_status'] ?? ArticleTranslationRevision::STATUS_APPROVED),
            'title' => 'Rehearsal Draft',
            'excerpt' => 'Rehearsal excerpt',
            'content_md' => $body,
            'seo_title' => 'Rehearsal SEO Title',
            'seo_description' => 'Rehearsal SEO description',
            'reviewed_by' => 7,
            'reviewed_at' => now()->subMinutes(10),
            'approved_at' => now()->subMinutes(5),
        ]);
        $article->forceFill(['working_revision_id' => (int) $revision->id])->save();

        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => $locale,
            'seo_title' => 'Rehearsal SEO Title',
            'seo_description' => 'Rehearsal SEO description',
            'canonical_url' => "https://example.test/en/articles/{$article->slug}",
            'og_title' => 'Rehearsal OG Title',
            'og_description' => 'Rehearsal OG description',
            'og_image_url' => 'https://example.test/storage/articles/rehearsal.svg',
            'robots' => (string) ($overrides['robots'] ?? 'noindex,nofollow'),
            'schema_json' => [
                'editorial_package_v1' => [
                    'sensitivity_level' => 'career_sensitive',
                    'cta_slots' => [
                        ['slot_id' => 'cta_primary', 'href' => '/en/tests/personality-test'],
                    ],
                    'answer_surface_v1' => [
                        'faq_items' => [
                            ['question' => 'Q', 'answer' => 'A'],
                        ],
                    ],
                    'target_topics' => ['mbti'],
                    'target_tests' => ['personality-test'],
                ],
            ],
            'is_indexable' => (bool) ($overrides['is_indexable'] ?? false),
        ]);

        ArticleEditorialPackageImport::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'slug' => $slug,
            'locale' => $locale,
            'title' => 'Rehearsal Draft',
            'content_track' => 'evergreen_knowledge',
            'status' => (string) ($overrides['import_status'] ?? ArticleEditorialPackageImport::STATUS_IMPORTED),
            'intended_status' => 'review_pending',
            'claim_result_json' => $overrides['claim_result_json'] ?? ['status' => 'passed', 'matches' => []],
            'exactness_json' => ['status' => 'passed', 'body_hash' => $bodyHash],
            'references_json' => ['status' => 'complete', 'count' => 1],
            'media_json' => ['status' => 'complete'],
            'graph_json' => ['status' => 'complete', 'target_topics' => ['mbti']],
            'answer_surface_json' => ['status' => 'complete'],
            'body_hash' => $bodyHash,
            'heading_sequence_json' => ['1:Rehearsal Draft', '2:FAQ'],
            'references_count' => 1,
        ]);

        return $article->fresh(['workingRevision', 'publishedRevision', 'seoMeta', 'category', 'tags']) ?? $article;
    }

    /**
     * @return array<string, mixed>
     */
    private function articleSnapshot(Article $article): array
    {
        $article = $article->fresh(['workingRevision', 'publishedRevision', 'seoMeta']) ?? $article;

        return [
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'published_revision_id' => $article->published_revision_id,
            'working_revision_status' => (string) $article->workingRevision?->revision_status,
            'seo_robots' => (string) $article->seoMeta?->robots,
            'seo_is_indexable' => (bool) $article->seoMeta?->is_indexable,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function artisanJsonOutput(): array
    {
        $decoded = json_decode(Artisan::output(), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/content-publish-rehearsal-dry-run.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
