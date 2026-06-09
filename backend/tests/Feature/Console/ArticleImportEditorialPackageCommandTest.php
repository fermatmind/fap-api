<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\ArticleBodyHeadingGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ArticleImportEditorialPackageCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_valid_editorial_and_evergreen_packages_pass_without_database_writes(): void
    {
        $editorialPath = $this->writePackage($this->editorialPackage([
            'slug' => 'ai-personality-editorial-draft',
        ]));
        $evergreenPath = $this->writePackage($this->evergreenPackage([
            'slug' => 'riasec-evergreen-draft',
        ]));

        $this->artisan('articles:import-editorial-package', [
            '--file' => $editorialPath,
            '--locale' => 'zh-CN',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('dry_run=1')
            ->expectsOutputToContain('action=will_create')
            ->expectsOutputToContain('content_track=editorial_journal')
            ->expectsOutputToContain('target_tests=mbti-personality-test-16-personality-types')
            ->expectsOutputToContain('target_topics=mbti')
            ->expectsOutputToContain('references_count=2')
            ->expectsOutputToContain('errors_count=0')
            ->assertExitCode(0);

        $this->artisan('articles:import-editorial-package', [
            '--file' => $evergreenPath,
            '--locale' => 'zh-CN',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('dry_run=1')
            ->expectsOutputToContain('action=will_create')
            ->expectsOutputToContain('content_track=evergreen_knowledge')
            ->expectsOutputToContain('target_tests=holland-career-interest-test')
            ->expectsOutputToContain('target_topics=riasec')
            ->expectsOutputToContain('references_count=1')
            ->expectsOutputToContain('errors_count=0')
            ->assertExitCode(0);

        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ArticleTranslationRevision::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ArticleSeoMeta::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count());
    }

    public function test_evergreen_definition_gate_accepts_multilingual_semantic_anchor_headings(): void
    {
        $cases = [
            'mbti-origin' => 'MBTI 从哪里来？',
            'big-five-origin' => '大五人格的起源',
            'eq-definition' => '什么是情商测试',
            'holland-birth' => 'The birth of Holland career-interest theory',
            'big-five-what-is' => 'What is a Big Five personality test?',
            'dimension-methodology' => '测量维度：MBTI 四个偏好',
        ];

        foreach ($cases as $slugSuffix => $definitionHeading) {
            $path = $this->writePackage($this->evergreenPackage([
                'slug' => 'semantic-anchor-'.$slugSuffix,
                'body_markdown' => "# Semantic Anchor\n\n## {$definitionHeading}\n\nThis section gives the core concept, background, or definition.\n\n## Methodology and theory\n\nThis section explains the model, measures, and framework behind the assessment.\n\n## FAQ\n\n### Is this a prediction tool?\n\nNo. It is an exploratory signal.\n\n## Conclusion\n\nUse the result as one decision input.",
            ]));

            $this->artisan('articles:import-editorial-package', [
                '--file' => $path,
                '--locale' => 'zh-CN',
                '--dry-run' => true,
            ])
                ->expectsOutputToContain('dry_run=1')
                ->expectsOutputToContain('action=will_create')
                ->expectsOutputToContain('errors_count=0')
                ->assertExitCode(0);
        }

        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ArticleTranslationRevision::query()->withoutGlobalScopes()->count());
    }

    public function test_import_creates_non_public_cms_draft_with_exact_body_metadata_and_answer_surface_boundary(): void
    {
        $package = $this->editorialPackage([
            'slug' => 'ai-personality-editorial-draft',
            'intended_status' => 'review_pending',
            'translation_group_id' => 'article_mbti_vs_holland_career_choice_v1',
        ]);
        $path = $this->writePackage($package);
        $sensitiveCounts = $this->sensitiveTableCounts();

        $this->artisan('articles:import-editorial-package', [
            '--file' => $path,
            '--locale' => 'zh-CN',
        ])
            ->expectsOutputToContain('dry_run=0')
            ->expectsOutputToContain('action=will_create')
            ->expectsOutputToContain('working_revision_status=human_review')
            ->expectsOutputToContain('published_revision_id=')
            ->expectsOutputToContain('errors_count=0')
            ->assertExitCode(0);

        $article = Article::query()
            ->withoutGlobalScopes()
            ->with(['category', 'tags', 'workingRevision', 'seoMeta'])
            ->where('slug', 'ai-personality-editorial-draft')
            ->where('locale', 'zh-CN')
            ->firstOrFail();
        $expectedBody = app(ArticleBodyHeadingGuard::class)
            ->downgradeMarkdownH1ToH2((string) $package['body_markdown']);

        $this->assertSame('draft', (string) $article->status);
        $this->assertFalse((bool) $article->is_public);
        $this->assertFalse((bool) $article->is_indexable);
        $this->assertNull($article->published_revision_id);
        $this->assertSame('article_mbti_vs_holland_career_choice_v1', (string) $article->translation_group_id);
        $this->assertSame($package['title'], (string) $article->title);
        $this->assertSame($expectedBody, (string) $article->content_md);
        $this->assertFalse(app(ArticleBodyHeadingGuard::class)->containsMarkdownH1((string) $article->content_md));
        $this->assertSame($package['cover_image'], (string) $article->cover_image_url);
        $this->assertSame($package['cover_image_alt'], (string) $article->cover_image_alt);
        $this->assertSame($package['category'], (string) $article->category?->name);
        $expectedTags = $package['tags'];
        $actualTags = $article->tags->pluck('name')->all();
        sort($expectedTags);
        sort($actualTags);
        $this->assertSame($expectedTags, $actualTags);

        $this->assertInstanceOf(ArticleTranslationRevision::class, $article->workingRevision);
        $this->assertSame(ArticleTranslationRevision::STATUS_HUMAN_REVIEW, (string) $article->workingRevision->revision_status);
        $this->assertSame($expectedBody, (string) $article->workingRevision->content_md);
        $this->assertSame($package['seo_title'], (string) $article->workingRevision->seo_title);
        $this->assertSame($package['meta_description'], (string) $article->workingRevision->seo_description);

        $this->assertSame($package['seo_title'], (string) $article->seoMeta?->seo_title);
        $this->assertSame($package['meta_description'], (string) $article->seoMeta?->seo_description);
        $this->assertSame($package['canonical'], (string) $article->seoMeta?->canonical_url);
        $this->assertSame('noindex,nofollow', (string) $article->seoMeta?->robots);
        $this->assertFalse((bool) $article->seoMeta?->is_indexable);

        $metadata = $article->cover_image_variants['editorial_package_v1'] ?? [];
        $this->assertSame('article_mbti_vs_holland_career_choice_v1', $metadata['translation_group_id'] ?? null);
        $this->assertSame('editorial_journal', $metadata['content_track'] ?? null);
        $this->assertSame($package['references'], $metadata['references'] ?? []);
        $this->assertSame($package['answer_surface_v1']['quick_answer'], data_get($metadata, 'answer_surface_v1.quick_answer'));
        $this->assertSame('below_intro', $metadata['answer_surface_visibility'] ?? null);
        $this->assertSame($this->normalizedBodyHash($expectedBody), data_get($metadata, 'validation.body_hash'));
        $this->assertSame(['2:AI 与人格如何共同影响判断', '2:执行摘要', '2:为什么这不是单纯技术问题', '2:结论'], data_get($metadata, 'validation.heading_sequence'));

        $this->assertSame($sensitiveCounts, $this->sensitiveTableCounts());
        $importLog = ArticleEditorialPackageImport::query()
            ->withoutGlobalScopes()
            ->where('slug', 'ai-personality-editorial-draft')
            ->firstOrFail();
        $this->assertSame(ArticleEditorialPackageImport::STATUS_IMPORTED, (string) $importLog->status);
        $this->assertSame((int) $article->id, (int) $importLog->article_id);
        $this->assertSame('editorial_journal', (string) $importLog->content_track);
        $this->assertSame($this->normalizedBodyHash($expectedBody), (string) $importLog->body_hash);
        $this->assertSame(2, (int) $importLog->references_count);
        $this->assertSame('complete', data_get($importLog->references_json, 'status'));
        $this->assertSame('complete', data_get($importLog->media_json, 'status'));
        $this->assertSame('complete', data_get($importLog->graph_json, 'status'));
        $this->assertSame('passed', data_get($importLog->claim_result_json, 'status'));
        $this->assertSame(['2:AI 与人格如何共同影响判断', '2:执行摘要', '2:为什么这不是单纯技术问题', '2:结论'], $importLog->heading_sequence_json);
        $this->assertStringNotContainsString($package['body_markdown'], json_encode($importLog->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->getJson('/api/v0.5/articles/ai-personality-editorial-draft?locale=zh-CN')
            ->assertNotFound();
        $this->getJson('/api/v0.5/articles/ai-personality-editorial-draft/seo?locale=zh-CN')
            ->assertNotFound();
    }

    public function test_claim_linter_blocks_import_and_warning_override_keeps_working_revision_as_draft(): void
    {
        $blockedPath = $this->writePackage($this->editorialPackage([
            'slug' => 'blocked-claim-draft',
            'title' => '哪种关系最适合你',
        ]));

        $this->artisan('articles:import-editorial-package', [
            '--file' => $blockedPath,
            '--locale' => 'zh-CN',
        ])
            ->expectsOutputToContain('claim_boundary_forbidden_phrase')
            ->assertExitCode(1);

        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
        $blockedLog = ArticleEditorialPackageImport::query()
            ->withoutGlobalScopes()
            ->where('slug', 'blocked-claim-draft')
            ->firstOrFail();
        $this->assertSame(ArticleEditorialPackageImport::STATUS_BLOCKED, (string) $blockedLog->status);
        $this->assertSame('blocked', data_get($blockedLog->claim_result_json, 'status'));
        $this->assertSame('claim_boundary_forbidden_phrase', data_get($blockedLog->blocked_reasons_json, '0.code'));

        $warningPath = $this->writePackage($this->editorialPackage([
            'slug' => 'claim-warning-draft-only',
            'title' => '哪种关系最适合你',
            'intended_status' => 'review_pending',
        ]));

        $this->artisan('articles:import-editorial-package', [
            '--file' => $warningPath,
            '--locale' => 'zh-CN',
            '--allow-claim-warnings' => true,
        ])
            ->expectsOutputToContain('claim_warning=title:claim_boundary_forbidden_phrase')
            ->expectsOutputToContain('working_revision_status=machine_draft')
            ->assertExitCode(0);

        $article = Article::query()
            ->withoutGlobalScopes()
            ->with('workingRevision')
            ->where('slug', 'claim-warning-draft-only')
            ->firstOrFail();

        $this->assertSame('draft', (string) $article->status);
        $this->assertNull($article->published_revision_id);
        $this->assertSame(ArticleTranslationRevision::STATUS_MACHINE_DRAFT, (string) $article->workingRevision?->revision_status);
        $warningLog = ArticleEditorialPackageImport::query()
            ->withoutGlobalScopes()
            ->where('slug', 'claim-warning-draft-only')
            ->firstOrFail();
        $this->assertSame(ArticleEditorialPackageImport::STATUS_WARNING, (string) $warningLog->status);
        $this->assertSame('warning', data_get($warningLog->claim_result_json, 'status'));
    }

    public function test_evergreen_semantic_headings_and_boundary_claim_context_pass_as_warnings(): void
    {
        $path = $this->writePackage($this->evergreenPackage([
            'slug' => 'riasec-semantic-evergreen-draft',
            'body_markdown' => "# 霍兰德职业兴趣测试能告诉你什么？\n\n## 执行摘要\n\n霍兰德测试不能告诉你唯一最适合的职业，也不能预测你的职业成功率。\n\n## 霍兰德职业兴趣测试到底测什么？\n\nRIASEC 是一种职业兴趣结构入口，用来解释人与工作环境之间的兴趣线索。\n\n## 如何正确使用霍兰德测试结果？\n\n它应该作为职业探索的第一层证据，也不能说某职业一定适合你一生。\n\n## FAQ\n\n### 霍兰德测试能直接决定职业吗？\n\n不能。\n\n## 结论\n\nRIASEC 应作为职业探索入口，而不是最终职业答案.",
        ]));

        $this->artisan('articles:import-editorial-package', [
            '--file' => $path,
            '--locale' => 'zh-CN',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('action=will_create')
            ->expectsOutputToContain('errors_count=0')
            ->expectsOutputToContain('claim_warning=body_markdown:claim_boundary_forbidden_phrase')
            ->expectsOutputToContain('claim_matches_count=3')
            ->assertExitCode(0);

        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count());
    }

    public function test_track_and_answer_surface_validation_block_invalid_packages_without_writes(): void
    {
        $invalidEvergreenPath = $this->writePackage($this->evergreenPackage([
            'slug' => 'invalid-evergreen-draft',
            'body_markdown' => "# RIASEC intro\n\nMissing required structure.",
            'references' => [],
        ]));

        $this->artisan('articles:import-editorial-package', [
            '--file' => $invalidEvergreenPath,
            '--locale' => 'zh-CN',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('evergreen_definition_required')
            ->expectsOutputToContain('evergreen_method_required')
            ->expectsOutputToContain('evergreen_faq_required')
            ->expectsOutputToContain('evergreen_references_required')
            ->assertExitCode(1);

        $invalidAnswerSurfacePath = $this->writePackage($this->editorialPackage([
            'slug' => 'invalid-answer-surface-draft',
            'body_markdown' => "# AI 与人格如何共同影响判断\n\n## 执行摘要\n\nAI 态度不只取决于技术知识。\n\n> **Evidence Note**\n> Evidence stays visible.\n\nAI 态度不只取决于技术知识。\n\n## 结论\n\n保持判断权。",
            'answer_surface_v1' => [
                'quick_answer' => 'AI 态度不只取决于技术知识。',
                'faq_items' => [],
                'next_steps' => [],
                'evidence_notes' => [],
            ],
        ]));

        $this->artisan('articles:import-editorial-package', [
            '--file' => $invalidAnswerSurfacePath,
            '--locale' => 'zh-CN',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('answer_surface_merged_into_body')
            ->assertExitCode(1);

        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    public function test_canary_importer_adapters_dry_run_schema_gate_preserves_source_without_database_writes(): void
    {
        $this->assertCanaryAdapterPreservesSource(
            base_path('docs/seo/canary-packages/mbti-vs-holland-career-choice.zh-CN.json'),
            base_path('docs/seo/canary-packages/mbti-vs-holland-career-choice.zh-CN.importer-adapter.json'),
        );
        $this->assertCanaryAdapterPreservesSource(
            base_path('docs/seo/canary-packages/mbti-vs-holland-code-career-choice.en.json'),
            base_path('docs/seo/canary-packages/mbti-vs-holland-code-career-choice.en.importer-adapter.json'),
        );

        $this->artisan('articles:import-editorial-package', [
            '--file' => base_path('docs/seo/canary-packages/mbti-vs-holland-career-choice.zh-CN.importer-adapter.json'),
            '--locale' => 'zh-CN',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('dry_run=1')
            ->expectsOutputToContain('action=will_create')
            ->expectsOutputToContain('content_track=evergreen_knowledge')
            ->expectsOutputToContain('target_tests=holland-career-interest-test-riasec,mbti-personality-test-16-personality-types,big-five-personality-test-ocean-model')
            ->expectsOutputToContain('target_topics=riasec,mbti')
            ->expectsOutputToContain('errors_count=0')
            ->expectsOutputToContain('claim_warning=body_markdown:claim_boundary_forbidden_phrase')
            ->assertExitCode(0);

        $this->artisan('articles:import-editorial-package', [
            '--file' => base_path('docs/seo/canary-packages/mbti-vs-holland-code-career-choice.en.importer-adapter.json'),
            '--locale' => 'en',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('dry_run=1')
            ->expectsOutputToContain('action=will_create')
            ->expectsOutputToContain('content_track=evergreen_knowledge')
            ->expectsOutputToContain('target_tests=holland-career-interest-test-riasec,mbti-personality-test-16-personality-types,big-five-personality-test-ocean-model')
            ->expectsOutputToContain('target_topics=riasec,mbti')
            ->expectsOutputToContain('errors_count=0')
            ->assertExitCode(0);

        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ArticleTranslationRevision::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ArticleSeoMeta::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count());
    }

    public function test_riasec_article_target_packages_map_to_editorial_importer_without_database_writes(): void
    {
        $targets = [
            'zh' => [
                'path' => base_path('docs/seo/cms-import-packages/riasec-explanation-v2/zh.article-target.json'),
                'topics' => 'career-interest,riasec,holland-code,career-exploration',
            ],
            'en' => [
                'path' => base_path('docs/seo/cms-import-packages/riasec-explanation-v2/en.article-target.json'),
                'topics' => 'career-interest,riasec,holland-code,career-exploration',
            ],
        ];

        foreach ($targets as $locale => $target) {
            $this->artisan('articles:import-editorial-package', [
                '--file' => $target['path'],
                '--locale' => $locale,
                '--dry-run' => true,
                '--allow-claim-warnings' => true,
            ])
                ->expectsOutputToContain('dry_run=1')
                ->expectsOutputToContain('action=will_create')
                ->expectsOutputToContain('content_track=evergreen_knowledge')
                ->expectsOutputToContain('target_tests=holland-career-interest-test-riasec')
                ->expectsOutputToContain('target_topics='.$target['topics'])
                ->expectsOutputToContain('references_count=0')
                ->expectsOutputToContain('errors_count=0')
                ->expectsOutputToContain('validation_warning=cover_image:cover_image_placeholder_required')
                ->expectsOutputToContain('validation_warning=references_needs:references_need_operator_acceptance')
                ->expectsOutputToContain('validation_warning=references_needs:evergreen_references_need_operator_acceptance')
                ->assertExitCode(0);
        }

        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ArticleTranslationRevision::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ArticleSeoMeta::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count());
    }

    public function test_article_target_mapping_fails_closed_for_private_cta_routes(): void
    {
        $package = json_decode((string) file_get_contents(base_path('docs/seo/cms-import-packages/riasec-explanation-v2/zh.article-target.json')), true);
        $this->assertIsArray($package);
        data_set($package, 'cta_suggestions.primary_cta_href', '/zh/results/private-tokenized-path');
        $path = $this->writePackage($package);

        $this->artisan('articles:import-editorial-package', [
            '--file' => $path,
            '--locale' => 'zh',
            '--dry-run' => true,
            '--allow-claim-warnings' => true,
        ])
            ->expectsOutputToContain('validation_error=cta_suggestions.primary_cta_href:unsafe_cta_href')
            ->assertExitCode(1);

        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count());
    }

    public function test_positive_forbidden_claims_still_block_canary_importer_validation(): void
    {
        $path = $this->writePackage($this->evergreenPackage([
            'slug' => 'positive-forbidden-claim-draft',
            'title' => '官方 MBTI 最准测试保证找到职业',
            'meta_description' => '这是医学诊断和心理诊断。',
        ]));

        $this->artisan('articles:import-editorial-package', [
            '--file' => $path,
            '--locale' => 'zh-CN',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('claim_boundary_forbidden_phrase')
            ->expectsOutputToContain('errors_count=5')
            ->expectsOutputToContain('claim_matches_count=5')
            ->assertExitCode(1);

        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, ArticleEditorialPackageImport::query()->withoutGlobalScopes()->count());
    }

    public function test_existing_published_article_slug_is_reported_and_not_mutated(): void
    {
        $category = ArticleCategory::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => 'existing',
            'name' => 'Existing',
            'is_active' => true,
        ]);
        $article = Article::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'category_id' => (int) $category->id,
            'author_name' => 'Fermat Institute',
            'reading_minutes' => 3,
            'slug' => 'existing-published-article',
            'locale' => 'zh-CN',
            'title' => 'Existing title',
            'excerpt' => 'Existing excerpt',
            'content_md' => 'Existing body',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
        ]);
        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => 'Existing title',
            'excerpt' => 'Existing excerpt',
            'content_md' => 'Existing body',
            'published_at' => now(),
        ]);
        $article->forceFill([
            'working_revision_id' => (int) $revision->id,
            'published_revision_id' => (int) $revision->id,
        ])->save();

        $path = $this->writePackage($this->editorialPackage([
            'slug' => 'existing-published-article',
        ]));

        $this->artisan('articles:import-editorial-package', [
            '--file' => $path,
            '--locale' => 'zh-CN',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('action=will_skip')
            ->expectsOutputToContain('existing_published_article')
            ->expectsOutputToContain('would_write=0')
            ->assertExitCode(0);

        $this->artisan('articles:import-editorial-package', [
            '--file' => $path,
            '--locale' => 'zh-CN',
        ])
            ->expectsOutputToContain('action=will_skip')
            ->expectsOutputToContain('existing_published_article')
            ->assertExitCode(0);

        $article->refresh();
        $this->assertSame('Existing title', (string) $article->title);
        $this->assertSame('Existing body', (string) $article->content_md);
        $this->assertSame((int) $revision->id, (int) $article->published_revision_id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function editorialPackage(array $overrides = []): array
    {
        return array_replace([
            'package_version' => 'editorial_package.v1',
            'title' => 'AI 与人格如何共同影响判断',
            'slug' => 'ai-personality-editorial-draft',
            'locale' => 'zh-CN',
            'author' => 'Fermat Institute',
            'intended_status' => 'draft',
            'body_markdown' => "# AI 与人格如何共同影响判断\n\n## 执行摘要\n\n围绕人工智能的争论，实质上往往是一场关于人格、控制感与风险解释方式的争论。\n\n> **Evidence Note**\n> 人格不会决定一个人是否应该使用 AI，但会影响风险解释和信任分配。\n\n## 为什么这不是单纯技术问题\n\n成熟的 AI 使用方式，是把 AI 放在被监管的位置上。\n\n## 结论\n\n真正重要的是保留最后一道判断权。",
            'references' => [
                'Kaya et al. (2024). Personality traits and AI attitudes. https://doi.org/10.1080/10447318.2022.2151730',
                'Logg et al. (2019). Algorithm appreciation. https://doi.org/10.1016/j.obhdp.2018.12.005',
            ],
            'seo_title' => '性格如何影响你对 AI 的态度？',
            'meta_description' => '用人格心理学解释 AI 态度、算法信任、控制感与职业判断边界。',
            'excerpt' => 'AI 态度不只取决于技术知识，也与人格和控制感有关。',
            'canonical' => 'https://fermatmind.com/zh/articles/ai-personality-editorial-draft',
            'indexability' => true,
            'content_track' => 'editorial_journal',
            'category' => '人工智能与人格',
            'tags' => ['AI', '人格心理学', '算法信任'],
            'topic_cluster' => 'mbti',
            'content_series' => 'ai-personality',
            'audience_intent' => 'ai_and_personality',
            'commercial_priority' => 'medium',
            'signal_source' => 'MBTI',
            'signal_type' => 'identity',
            'decision_domains' => ['self', 'workstyle'],
            'target_tests' => ['mbti-personality-test-16-personality-types'],
            'target_topics' => ['mbti'],
            'target_personality_pages' => [],
            'target_career_pages' => [],
            'target_reports' => [],
            'next_action' => 'start_mbti_test',
            'internal_links' => ['/zh/tests/mbti-personality-test-16-personality-types', '/zh/topics/mbti'],
            'graph_edges' => [
                'from_article_to_test' => ['mbti-personality-test-16-personality-types'],
                'from_article_to_topic' => ['mbti'],
            ],
            'recommended_reverse_links' => [
                'topic' => ['mbti'],
                'homepage' => ['recommended_articles'],
            ],
            'cover_image' => 'https://api.fermatmind.com/static/articles/covers/ai-personality-editorial-draft.svg',
            'cover_image_alt' => '抽象人脸轮廓与 AI 节点网络交织',
            'cover_image_prompt' => '冷静的学术编辑部封面，抽象人脸轮廓与蓝绿色 AI 节点网络交织。',
            'cover_image_style_tag' => 'academic-editorial, ai-personality, muted-geometric',
            'answer_surface_policy' => 'editor_supplied',
            'answer_surface_v1' => [
                'quick_answer' => '人格会影响一个人如何解释 AI 风险、分配信任并保留控制权。',
                'faq_items' => [
                    ['question' => '人格能决定 AI 使用方式吗？', 'answer' => '不能，只能作为理解倾向的入口。'],
                ],
                'next_steps' => ['完成 MBTI 测试'],
                'evidence_notes' => ['人格影响风险解释方式。'],
            ],
            'answer_surface_visibility' => 'below_intro',
            'cta_slots' => [
                ['position' => 'after_summary', 'label' => '开始 MBTI 测试', 'href' => '/zh/tests/mbti-personality-test-16-personality-types'],
            ],
            'primary_cta' => '开始 MBTI 深度测试',
            'secondary_cta' => '阅读 MBTI 主题页',
            'freemium_entry' => 'mbti',
            'report_upsell_allowed' => false,
            'claim_boundary_notes' => ['不宣称 AI 比人更懂你；不做诊断或职业预测。'],
            'claim_level' => 'evidence_supported',
            'sensitivity_level' => 'normal',
            'medical_disclaimer_required' => false,
            'ability_disclaimer_required' => false,
            'external_references_required' => true,
            'review_required_by' => ['editor'],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function evergreenPackage(array $overrides = []): array
    {
        return array_replace([
            'package_version' => 'editorial_package.v1',
            'title' => 'RIASEC 是什么？',
            'slug' => 'riasec-evergreen-draft',
            'locale' => 'zh-CN',
            'author' => 'Fermat Institute',
            'intended_status' => 'draft',
            'body_markdown' => "# RIASEC 是什么？\n\n## Definition｜定义\n\nRIASEC 是一种职业兴趣结构入口。\n\n## Method and theory｜方法与理论\n\n它把职业兴趣分成六类，用于解释人与工作环境的匹配线索。\n\n## FAQ｜常见问题\n\n### RIASEC 是职业推荐器吗？\n\n不是，它是职业兴趣解释框架。\n\n## 结论\n\nRIASEC 应作为职业探索入口，而不是最终职业答案。",
            'references' => ['Holland, J. L. (1997). Making vocational choices.'],
            'seo_title' => 'RIASEC 是什么？霍兰德职业兴趣六型解释',
            'meta_description' => '解释 RIASEC 六型、霍兰德职业兴趣理论和职业探索边界。',
            'excerpt' => 'RIASEC 是理解职业兴趣结构的入口，不是最终职业答案。',
            'canonical' => 'https://fermatmind.com/zh/articles/riasec-evergreen-draft',
            'indexability' => true,
            'content_track' => 'evergreen_knowledge',
            'category' => '职业发展',
            'tags' => ['RIASEC', '霍兰德', '职业兴趣'],
            'topic_cluster' => 'riasec',
            'content_series' => 'career-knowledge',
            'audience_intent' => 'career_decision',
            'commercial_priority' => 'high',
            'signal_source' => 'RIASEC',
            'signal_type' => 'interest',
            'decision_domains' => ['career'],
            'target_tests' => ['holland-career-interest-test'],
            'target_topics' => ['riasec'],
            'target_personality_pages' => [],
            'target_career_pages' => ['/zh/career'],
            'target_reports' => [],
            'next_action' => 'start_riasec_test',
            'internal_links' => ['/zh/tests/holland-career-interest-test', '/zh/career'],
            'graph_edges' => [
                'from_article_to_test' => ['holland-career-interest-test'],
                'from_article_to_topic' => ['riasec'],
            ],
            'recommended_reverse_links' => [
                'topic' => ['riasec'],
                'test' => ['holland-career-interest-test'],
            ],
            'cover_image' => 'https://api.fermatmind.com/static/articles/covers/riasec-evergreen-draft.svg',
            'cover_image_alt' => 'RIASEC 六型与职业兴趣结构图',
            'cover_image_prompt' => '冷静的学术编辑部封面，六型结构与职业路径图。',
            'cover_image_style_tag' => 'academic-editorial, career-decision, muted-geometric',
            'answer_surface_policy' => 'none',
            'answer_surface_v1' => [],
            'answer_surface_visibility' => 'disabled',
            'cta_slots' => [
                ['position' => 'after_definition', 'label' => '开始霍兰德测试', 'href' => '/zh/tests/holland-career-interest-test'],
            ],
            'primary_cta' => '开始霍兰德职业兴趣测试',
            'secondary_cta' => '查看职业中心',
            'freemium_entry' => 'riasec',
            'report_upsell_allowed' => false,
            'claim_boundary_notes' => ['不把 RIASEC 写成职业推荐器。'],
            'claim_level' => 'evidence_supported',
            'sensitivity_level' => 'career_sensitive',
            'medical_disclaimer_required' => false,
            'ability_disclaimer_required' => false,
            'external_references_required' => true,
            'review_required_by' => ['editor', 'psychometrics'],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function writePackage(array $package): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fm-editorial-package-');
        $this->assertIsString($path);
        file_put_contents($path, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function assertCanaryAdapterPreservesSource(string $sourcePath, string $adapterPath): void
    {
        $source = json_decode((string) file_get_contents($sourcePath), true);
        $adapter = json_decode((string) file_get_contents($adapterPath), true);
        $this->assertIsArray($source);
        $this->assertIsArray($adapter);

        foreach (['title', 'h1', 'seo_title', 'seo_description', 'excerpt', 'body_markdown', 'faq', 'cta_slots'] as $field) {
            $this->assertSame($source[$field], $adapter[$field], $field.' should be mechanically preserved.');
        }

        $this->assertSame($source['seo_description'], $adapter['meta_description']);
        $this->assertSame('https://fermatmind.com'.$source['canonical_path'], $adapter['canonical']);
        $this->assertSame($source['faq'], data_get($adapter, 'answer_surface_v1.faq_items'));
        $this->assertFalse((bool) data_get($adapter, 'publish_gate.publish_allowed'));
        $this->assertFalse((bool) data_get($adapter, 'adapter_publish_gate.publish_allowed'));
        $this->assertSame('draft', (string) $adapter['intended_status']);
        $this->assertFalse((bool) $adapter['indexability']);

        foreach ($adapter['cta_slots'] as $slot) {
            $href = (string) ($slot['href'] ?? '');
            $this->assertTrue(str_starts_with($href, '/zh/tests/') || str_starts_with($href, '/en/tests/'));
            $this->assertMatchesRegularExpression('/^\\/(zh|en)\\/tests\\/[a-z0-9-]+$/', $href);
            $this->assertDoesNotMatchRegularExpression('/(result|orders|share|pay|payment|history|take|token|^https?:\\/\\/)/i', $href);
        }
    }

    private function normalizedBodyHash(string $body): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($body));
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return hash('sha256', trim($text));
    }

    /**
     * @return array<string, int>
     */
    private function sensitiveTableCounts(): array
    {
        $tables = ['attempts', 'results', 'report_snapshots', 'orders', 'payment_events', 'shares'];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
