<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\ArticleIqMethodPagesPublishGate;
use App\Console\Commands\ArticleIqMethodPagesReviewApproval;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class ArticleIqMethodPagesReviewApprovalCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = $this->app->make(Kernel::class);
        $kernel->registerCommand($this->app->make(ArticleIqMethodPagesPublishGate::class));
        $kernel->registerCommand($this->app->make(ArticleIqMethodPagesReviewApproval::class));
    }

    public function test_review_approval_dry_run_passes_without_writing(): void
    {
        [$package, $reviewPacket, $locks] = $this->importPackageAndLocks();

        [$exit, $payload] = $this->callReviewApproval([
            '--package' => $package,
            '--review-packet' => $reviewPacket,
            '--article-lock' => $locks,
            '--reviewed-by' => 42,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertTrue($payload['ok']);
        $this->assertSame('dry_run_pass', $payload['status']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['execute']);
        $this->assertCount(7, $payload['articles']);
        $this->assertSame([], $payload['approved_articles']);
        $this->assertFalse($payload['side_effects']['db_write']);
        $this->assertFalse($payload['side_effects']['publish']);
        $this->assertFalse($payload['side_effects']['indexability']);
        $this->assertFalse($payload['side_effects']['sitemap']);
        $this->assertFalse($payload['side_effects']['llms']);

        $article = Article::query()->withoutGlobalScopes()->where('slug', 'what-is-iq-style-reasoning-test')->firstOrFail();
        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $article->id)->firstOrFail();

        $this->assertSame('draft_review_only', $article->status);
        $this->assertSame(ArticleTranslationRevision::STATUS_HUMAN_REVIEW, $revision->revision_status);
        $this->assertNull($revision->approved_at);
    }

    public function test_review_approval_execute_requires_exact_confirmation(): void
    {
        [$package, $reviewPacket, $locks] = $this->importPackageAndLocks();

        [$exit, $payload] = $this->callReviewApproval([
            '--package' => $package,
            '--review-packet' => $reviewPacket,
            '--article-lock' => $locks,
            '--reviewed-by' => 42,
            '--confirm' => 'wrong confirmation',
            '--execute' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertFalse($payload['ok']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('confirmation_mismatch', collect($payload['issues'])->pluck('code')->all());
        $this->assertFalse($payload['side_effects']['db_write']);

        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(ArticleTranslationRevision::STATUS_HUMAN_REVIEW, $revision->revision_status);
        $this->assertNull($revision->approved_at);
    }

    public function test_review_approval_execute_writes_review_metadata_without_publish_or_indexing(): void
    {
        [$package, $reviewPacket, $locks] = $this->importPackageAndLocks();

        [$dryRunExit, $dryRunPayload] = $this->callReviewApproval([
            '--package' => $package,
            '--review-packet' => $reviewPacket,
            '--article-lock' => $locks,
            '--reviewed-by' => 42,
            '--json' => true,
        ]);
        $this->assertSame(0, $dryRunExit);

        [$exit, $payload] = $this->callReviewApproval([
            '--package' => $package,
            '--review-packet' => $reviewPacket,
            '--article-lock' => $locks,
            '--reviewed-by' => 42,
            '--confirm' => $dryRunPayload['expected_confirmation'],
            '--execute' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertTrue($payload['ok']);
        $this->assertSame('approved', $payload['status']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['execute']);
        $this->assertCount(7, $payload['approved_articles']);
        $this->assertTrue($payload['side_effects']['db_write']);
        $this->assertTrue($payload['side_effects']['review_approval']);
        $this->assertFalse($payload['side_effects']['publish']);
        $this->assertFalse($payload['side_effects']['indexability']);
        $this->assertFalse($payload['side_effects']['sitemap']);
        $this->assertFalse($payload['side_effects']['llms']);

        $articles = Article::query()->withoutGlobalScopes()->orderBy('slug')->get();
        $this->assertCount(7, $articles);

        foreach ($articles as $article) {
            $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $article->id)->firstOrFail();
            $seo = ArticleSeoMeta::query()->withoutGlobalScopes()->where('article_id', $article->id)->firstOrFail();

            $this->assertSame('review_pending', $article->status);
            $this->assertFalse((bool) $article->is_public);
            $this->assertFalse((bool) $article->is_indexable);
            $this->assertFalse((bool) $article->sitemap_eligible);
            $this->assertFalse((bool) $article->llms_eligible);
            $this->assertNull($article->published_at);
            $this->assertNull($article->published_revision_id);
            $this->assertSame(ArticleTranslationRevision::STATUS_APPROVED, $revision->revision_status);
            $this->assertSame(42, (int) $revision->reviewed_by);
            $this->assertNotNull($revision->reviewed_at);
            $this->assertNotNull($revision->approved_at);
            $this->assertSame('noindex,follow', $seo->robots);
            $this->assertFalse((bool) $seo->is_indexable);
            $this->assertSame(
                'IQ-METHOD-PAGES-ZH-CN-CMS-REVIEW-APPROVAL-01',
                data_get($seo->schema_json, 'editorial_package_v1.review_approval_v1.pr_id')
            );
            $this->assertFalse((bool) data_get($seo->schema_json, 'editorial_package_v1.review_approval_v1.publish_allowed'));
            $this->assertFalse((bool) data_get($seo->schema_json, 'editorial_package_v1.review_approval_v1.indexability_allowed'));
            $this->assertFalse((bool) data_get($seo->schema_json, 'editorial_package_v1.review_approval_v1.sitemap_llms_allowed'));
        }
    }

    /**
     * @return array{0:string,1:string,2:list<string>}
     */
    private function importPackageAndLocks(): array
    {
        $package = $this->writeIqMethodPackage();
        $reviewPacket = $this->writeReviewPacket();

        $importExit = Artisan::call('articles:import-iq-method-pages-draft', [
            '--package' => $package,
            '--json' => true,
        ]);
        $this->assertSame(0, $importExit);

        [$gateExit, $gatePayload] = $this->callPublishGate([
            '--package' => $package,
            '--review-packet' => $reviewPacket,
            '--json' => true,
        ]);
        $this->assertSame(0, $gateExit, json_encode($gatePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assertTrue($gatePayload['ok']);

        $locks = collect($gatePayload['approval_candidates'])
            ->map(static fn (array $candidate): string => $candidate['slug'].':'.$candidate['article_id'].':'.$candidate['working_revision_id'])
            ->values()
            ->all();

        return [$package, $reviewPacket, $locks];
    }

    private function writeReviewPacket(): string
    {
        $path = sys_get_temp_dir().'/iq-method-pages-review-approval-packet-'.Str::uuid().'.json';
        $pages = [];

        foreach ($this->pageDefinitions() as $index => $page) {
            $pages[] = [
                'order' => $index + 1,
                'slug' => $page['slug'],
                'title' => $page['title'],
                'canonical_url' => 'https://fermatmind.com/zh/articles/'.$page['slug'],
                'method_review' => [
                    'status' => 'pass',
                    'decision' => 'Scoped method review passed for backend review approval.',
                ],
                'claim_review' => [
                    'status' => 'pass',
                    'forbidden_claims_found' => [],
                    'contextual_boundary_mentions' => [],
                    'contextual_boundary_decision' => 'No affirmative forbidden claims.',
                ],
                'approved_for_next_gate' => true,
            ];
        }

        file_put_contents($path, json_encode([
            'schema_version' => 'fermatmind.iq_method_pages.review_packet.v1',
            'packet_id' => 'IQ-METHOD-PAGES-ZH-CN-REVIEW-PACKET-2026-07-05',
            'created_at' => '2026-07-05T14:09:30+08:00',
            'locale' => 'zh-CN',
            'reviewer' => [
                'name' => 'Codex GPT-5',
                'role' => 'FermatMind operator-authorized internal method and claim boundary reviewer',
            ],
            'global_review_decision' => [
                'method_review' => 'pass',
                'claim_review' => 'pass',
                'forbidden_claim_scan' => 'pass_with_contextual_boundary_mentions',
                'publication_readiness' => 'ready_for_backend_publish_gate_only',
                'public_indexing_readiness' => 'not_ready_until_separate_seo_geo_activation_gate',
            ],
            'pages' => $pages,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function writeIqMethodPackage(): string
    {
        $root = sys_get_temp_dir().'/iq-method-pages-review-approval-'.Str::uuid();
        $packageRoot = $root.'/generated/iq-method-pages-zh-cn-v0.2';
        $dryRunRoot = $packageRoot.'/cms-dry-run';
        mkdir($dryRunRoot, 0777, true);

        $articleImports = [];
        $seoPages = [];
        $claimPages = [];
        $topicItems = [];
        $landingItems = [];

        foreach ($this->pageDefinitions() as $index => $page) {
            $pageNumber = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            $pageDir = $packageRoot.'/pages/'.$pageNumber.'-'.$page['slug'];
            mkdir($pageDir, 0777, true);

            $relativeDir = 'generated/iq-method-pages-zh-cn-v0.2/pages/'.$pageNumber.'-'.$page['slug'];
            $articleMd = $relativeDir.'/article.md';
            $articleCms = $relativeDir.'/article.cms.json';
            $seoJson = $relativeDir.'/seo.json';
            $answerSurface = $relativeDir.'/answer_surface_v1.json';
            $internalLinks = $relativeDir.'/internal_links.json';
            $mediaBrief = $relativeDir.'/media_brief.json';
            $faq = $relativeDir.'/faq.json';
            $landingSurface = $relativeDir.'/landing_surface_v1.json';
            $geoAnswer = $relativeDir.'/geo_answer_block.json';
            $claimAudit = $relativeDir.'/claim_audit.json';
            $qaChecklist = $relativeDir.'/qa_checklist.md';

            file_put_contents($pageDir.'/article.md', $page['content_md']);
            file_put_contents($pageDir.'/article.cms.json', json_encode([
                'status' => 'draft_review_only',
                'locale' => 'zh-CN',
                'slug' => $page['slug'],
                'title' => $page['title'],
                'excerpt' => $page['excerpt'],
                'content_md' => $page['content_md'],
                'related_test_slug' => 'iq-test-intelligence-quotient-assessment',
                'category_suggestion' => $page['category'],
                'tags' => ['IQ 风格推理测试', '测评方法与边界', '非官方非认证', '推理表现'],
                'is_public' => false,
                'is_indexable' => false,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'canonical_url' => 'https://fermatmind.com/zh/articles/'.$page['slug'],
                'robots' => 'noindex,follow',
                'schema_json' => [
                    'editorial_package_v1' => [
                        'package_version' => 'iq-method-pages-zh-cn-v0.2',
                        'review_required_before_publish' => true,
                    ],
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            file_put_contents($pageDir.'/seo.json', json_encode([
                'status' => 'draft_review_only',
                'seo_title' => $page['seo_title'],
                'seo_description' => $page['seo_description'],
                'canonical_url' => 'https://fermatmind.com/zh/articles/'.$page['slug'],
                'robots' => 'noindex,follow',
                'sitemap_eligible' => false,
                'llms_eligible' => false,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            file_put_contents($pageDir.'/answer_surface_v1.json', json_encode([
                'version' => 'answer_surface_v1',
                'status' => 'draft_review_only',
                'quick_answer' => 'IQ 风格推理测试用于理解本次推理任务表现，非官方、非临床、非认证。',
                'faq_items' => [
                    ['question' => '这是正式智力测评吗？', 'answer' => '不是。'],
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            file_put_contents($pageDir.'/internal_links.json', json_encode([
                'links_out' => [
                    ['label' => '开始 IQ 风格推理测试', 'href' => '/zh/tests/iq-test-intelligence-quotient-assessment'],
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            file_put_contents($pageDir.'/media_brief.json', json_encode([
                'status' => 'media_library_upload_deferred',
                'brief' => 'No public media URL assigned in draft import.',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            file_put_contents($pageDir.'/faq.json', '{"items":[]}');
            file_put_contents($pageDir.'/landing_surface_v1.json', '{"status":"draft_review_only"}');
            file_put_contents($pageDir.'/geo_answer_block.json', '{"status":"draft_review_only"}');
            file_put_contents($pageDir.'/claim_audit.json', '{"status":"passed_text_scan"}');
            file_put_contents($pageDir.'/qa_checklist.md', "- draft only\n");

            $articleImports[] = [
                'order' => $index + 1,
                'cms_resource_type' => 'Article',
                'operation' => 'upsert_draft_only',
                'locale' => 'zh-CN',
                'slug' => $page['slug'],
                'title' => $page['title'],
                'canonical_url' => 'https://fermatmind.com/zh/articles/'.$page['slug'],
                'route' => '/zh/articles/'.$page['slug'],
                'source_files' => [
                    'article_md' => $articleMd,
                    'article_cms_json' => $articleCms,
                    'seo_json' => $seoJson,
                    'faq_json' => $faq,
                    'internal_links_json' => $internalLinks,
                    'answer_surface_v1_json' => $answerSurface,
                    'landing_surface_v1_json' => $landingSurface,
                    'geo_answer_block_json' => $geoAnswer,
                    'media_brief_json' => $mediaBrief,
                    'claim_audit_json' => $claimAudit,
                    'qa_checklist_md' => $qaChecklist,
                ],
                'required_cms_fields' => [
                    'category' => $page['category'],
                    'tags' => ['IQ 风格推理测试', '测评方法与边界', '非官方非认证', '推理表现'],
                    'related_test_slug' => 'iq-test-intelligence-quotient-assessment',
                ],
                'publish_state' => $this->draftState(),
                'gates' => [
                    'human_method_review_required' => true,
                    'human_claim_review_required' => true,
                    'cms_dry_run_required' => true,
                    'cms_readback_required' => true,
                    'private_url_guard_required' => true,
                    'production_publish_allowed_in_this_pr' => false,
                    'sitemap_llms_activation_allowed_in_this_pr' => false,
                ],
            ];
            $seoPages[] = [
                'slug' => $page['slug'],
                'canonical_url' => 'https://fermatmind.com/zh/articles/'.$page['slug'],
                'robots' => 'noindex,follow',
                'sitemap_eligible' => false,
                'llms_eligible' => false,
            ];
            $claimPages[] = [
                'slug' => $page['slug'],
                'status' => 'draft_review_only',
                'page_status' => 'draft_review_only_passed_text_scan',
                'forbidden_terms_found' => [],
                'human_review_required' => true,
            ];
            $topicItems[] = [
                'slug' => $page['slug'],
                'title' => $page['title'],
                'href' => '/zh/articles/'.$page['slug'],
                'status' => 'draft_review_only',
                'is_public' => false,
                'is_indexable' => false,
            ];
            $landingItems[] = [
                'title' => $page['title'],
                'href' => '/zh/articles/'.$page['slug'],
                'slug' => $page['slug'],
            ];
        }

        file_put_contents($dryRunRoot.'/cms_import_manifest.json', json_encode([
            'schema_version' => 'fermatmind.iq_method_pages.cms_dry_run_manifest.v1',
            'pr_id' => 'IQ-METHOD-PAGES-ZH-CN-CMS-DRY-RUN-01',
            'mode' => 'cms_dry_run_contract_only',
            'source_package' => 'generated/iq-method-pages-zh-cn-v0.2',
            'required_default_publish_state' => $this->draftState(),
            'article_imports' => $articleImports,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        file_put_contents($dryRunRoot.'/seo_geo_gate.json', json_encode([
            'schema_version' => 'fermatmind.iq_method_pages.seo_geo_gate.v1',
            'pr_id' => 'IQ-METHOD-PAGES-ZH-CN-CMS-DRY-RUN-01',
            'status' => 'hold_noindex_until_human_and_cms_readback_pass',
            'default_gate' => $this->draftState(),
            'pages' => $seoPages,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        file_put_contents($dryRunRoot.'/claim_audit_summary.json', json_encode([
            'schema_version' => 'fermatmind.iq_method_pages.claim_audit_summary.v1',
            'pr_id' => 'IQ-METHOD-PAGES-ZH-CN-CMS-DRY-RUN-01',
            'status' => 'automated_text_scan_passed_human_review_required',
            'pages' => $claimPages,
            'gate_result' => 'pass_with_human_review_required',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        file_put_contents($dryRunRoot.'/topic_iq_articles_mapping.json', json_encode([
            'schema_version' => 'fermatmind.iq_method_pages.topic_mapping.v1',
            'target_topic' => [
                'locale' => 'zh-CN',
                'slug' => 'iq-eq',
                'route' => '/zh/topics/iq-eq',
            ],
            'required_display_policy' => [
                'split_mixed_group' => true,
                'iq_group_label' => 'IQ 文章',
                'eq_group_label' => 'EQ 文章',
                'frontend_hardcode_allowed' => false,
            ],
            'entry_groups' => [
                [
                    'group_key' => 'iq_articles',
                    'label' => 'IQ 文章',
                    'items' => $topicItems,
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        file_put_contents($dryRunRoot.'/landing_page_blocks_mapping.json', json_encode([
            'schema_version' => 'fermatmind.iq_method_pages.landing_page_blocks_mapping.v1',
            'target_landing_surface' => [
                'locale' => 'zh-CN',
                'related_test_slug' => 'iq-test-intelligence-quotient-assessment',
                'route' => '/zh/tests/iq-test-intelligence-quotient-assessment',
            ],
            'proposed_page_blocks' => [
                [
                    'block_key' => 'iq_methodology_boundary_links',
                    'block_type' => 'article_link_cluster',
                    'label' => 'IQ 测试方法与边界',
                    'placement' => 'supporting_methodology_links',
                    'items' => $landingItems,
                ],
            ],
            'guardrails' => [
                'frontend_hardcode_allowed' => false,
                'private_flow_links_allowed' => false,
                'result_or_order_links_allowed' => false,
                'publish_or_indexing_change_allowed_in_this_pr' => false,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $root;
    }

    /**
     * @return list<array<string,string>>
     */
    private function pageDefinitions(): array
    {
        $slugs = [
            'what-is-iq-style-reasoning-test' => '什么是 IQ 风格推理测试？',
            'online-iq-test-vs-professional-assessment' => '在线 IQ 风格测试和专业智力测评有什么区别？',
            'iq-test-score-meaning-boundary' => 'IQ 风格测试里的原始分、正确率和完成时间说明什么？',
            'matrix-reasoning-pattern-recognition-guide' => '矩阵推理和模式识别题在测什么？',
            'why-fermatmind-iq-v1-not-certification' => '为什么 FermatMind IQ V1 是非认证测试？',
            'iq-test-privacy-data-boundary' => 'IQ 风格测试的数据和隐私边界是什么？',
            'iq-expert-review-disclosure' => 'FermatMind 如何审查 IQ 风格测试内容？',
        ];

        $pages = [];
        foreach ($slugs as $slug => $title) {
            $pages[] = [
                'slug' => $slug,
                'title' => $title,
                'excerpt' => '解释 IQ 风格推理测试的方法、边界和信任层，保持非官方、非临床、非认证表达。',
                'seo_title' => mb_substr($title.' 方法边界说明', 0, 70),
                'seo_description' => '了解 FermatMind IQ V1 的方法边界：原创 30 题、约 20 分钟，只解释原始分、正确率、完成时间和维度表现。',
                'category' => $slug === 'matrix-reasoning-pattern-recognition-guide' ? '能力与认知' : '测评方法与边界',
                'content_md' => implode("\n\n", [
                    'IQ 风格推理测试是一类用图形、矩阵和规律补全任务观察推理表现的在线测试。FermatMind IQ V1 使用原创 30 题，约 20 分钟，重点解释本次任务中的原始分、正确率、完成时间和维度表现。它不是外部证明，也不是医疗或教育决策工具。',
                    '## 这是什么 / 这不是什么',
                    '这是一套在线 IQ 风格推理任务，不是正式智力结论、外部认证或临床测评。',
                    '## 方法解释',
                    '页面只解释公开方法与边界，不公开题目 ID、答案、解题步骤、评分表或后端评分规则。',
                    '## FAQ',
                    '### 这是正式智力测评吗？',
                    '不是。它用于理解本次推理任务表现。',
                ]),
            ];
        }

        return $pages;
    }

    /**
     * @return array<string,mixed>
     */
    private function draftState(): array
    {
        return [
            'status' => 'draft_review_only',
            'is_public' => false,
            'is_indexable' => false,
            'robots' => 'noindex,follow',
            'sitemap_eligible' => false,
            'llms_eligible' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array{0:int,1:array<string,mixed>}
     */
    private function callPublishGate(array $options): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('articles:iq-method-pages-publish-gate', $options, $output);
        $decoded = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return [$exit, $decoded];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array{0:int,1:array<string,mixed>}
     */
    private function callReviewApproval(array $options): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('articles:iq-method-pages-review-approval', $options, $output);
        $decoded = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return [$exit, $decoded];
    }
}
