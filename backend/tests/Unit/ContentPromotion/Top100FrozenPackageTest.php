<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionContextFactory;
use App\Services\ContentPromotion\Top100FrozenCmsBatchAuthority;
use App\Services\ContentPromotion\Top100FrozenPackage;
use DomainException;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

final class Top100FrozenPackageTest extends TestCase
{
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_frozen_package_has_exact_source_sha_targets_families_and_zero_cross_target_links(): void
    {
        $package = app(Top100FrozenPackage::class)->inspect($this->context($this->packageDirectory()));

        self::assertSame(Top100FrozenPackage::PACKAGE_SHA256, $package['package_sha256']);
        self::assertCount(30, $package['targets']);
        self::assertSame(46, $package['deferred_out_of_target_link_source_count']);
        self::assertSame(Top100FrozenPackage::TARGET_PRIORITIES, array_column($package['targets'], 'priority'));
        self::assertEqualsCanonicalizing([
            'article' => 3,
            'big_five' => 4,
            'enneagram_wing' => 12,
            'mbti_comparison' => 3,
            'mbti_profile' => 6,
            'test_landing' => 2,
        ], array_count_values(array_column($package['targets'], 'family')));
        $targetUrls = array_flip(array_column($package['targets'], 'url'));
        foreach ($package['targets'] as $target) {
            foreach ($target['internal_links'] as $link) {
                self::assertArrayHasKey('https://fermatmind.com'.$link['href'], $targetUrls);
                self::assertNotSame($target['url'], 'https://fermatmind.com'.$link['href']);
                self::assertTrue($link['safe_public_route']);
                self::assertSame($link['label'], $link['anchor_text']);
            }
        }
    }

    public function test_missing_duplicate_or_extra_target_and_source_or_payload_drift_fail_closed(): void
    {
        foreach (['missing', 'duplicate', 'extra', 'source', 'payload'] as $mutation) {
            $directory = $this->copyPackage();
            $targets = json_decode((string) File::get($directory.'/targets.json'), true, 512, JSON_THROW_ON_ERROR);
            if ($mutation === 'missing') {
                array_pop($targets['targets']);
            } elseif ($mutation === 'duplicate') {
                $targets['targets'][1] = $targets['targets'][0];
            } elseif ($mutation === 'extra') {
                $targets['targets'][] = $targets['targets'][0];
            } elseif ($mutation === 'source') {
                $targets['source_sha256'] = str_repeat('0', 64);
            } else {
                $targets['targets'][0]['proposed_title'] .= ' drift';
            }
            File::put($directory.'/targets.json', json_encode($targets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

            try {
                app(Top100FrozenPackage::class)->inspect($this->context($directory));
                self::fail('Expected '.$mutation.' mutation to fail closed.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_runtime_uses_controlled_article_publisher_and_public_projection_readbacks(): void
    {
        $authority = (string) File::get(dirname(__DIR__, 3).'/app/Services/ContentPromotion/Top100FrozenCmsBatchAuthority.php');

        self::assertStringContainsString('ArticlePublishService $articlePublisher', $authority);
        self::assertStringContainsString('$this->articlePublisher->promoteExistingWorkingRevision(', $authority);
        self::assertStringContainsString('$this->articleRevisionMatchesTarget($existing, $article, $target)', $authority);
        self::assertStringContainsString("'lock_links' => \$lock", $authority);
        self::assertStringContainsString("(bool) (\$resolved['lock_links'] ?? false)", $authority);
        self::assertStringContainsString('$this->assertPublicApiReadback($target);', $authority);
        self::assertStringContainsString('$this->assertLiveHtmlReadback($target);', $authority);
        self::assertStringContainsString('$this->discoverabilityCache->flushArticleDiscoverabilityCaches(false);', $authority);
        self::assertStringContainsString('$this->personalityReviewBinder->assertApproved($context, $personalityReviewTargets);', $authority);
        self::assertStringContainsString('$this->assertPersonalityRevisionMatchesTarget($context, $target, $resolved)', $authority);
        self::assertStringContainsString('$this->assertMbtiRevisionMatchesTarget($context, $target, $resolved);', $authority);
        self::assertStringContainsString("'deferred_out_of_target_link_source_count' => (int) \$package['deferred_out_of_target_link_source_count']", $authority);
        self::assertStringContainsString("\$target['slug'] === 'big-five-personality-test-ocean-model'", $authority);
        self::assertStringContainsString("'is_indexable' => false", $authority);
        self::assertStringContainsString("'created_from_missing' => \$target['model_kind'] === 'test_landing' && \$target['model_id'] === 0", $authority);
        self::assertStringContainsString('top100_frozen_created_landing_rollback_identity_invalid', $authority);
        self::assertStringNotContainsString("throw new DomainException('top100_frozen_article_foreign_working_revision');", $authority);
        self::assertStringContainsString('top100_frozen_article_foreign_working_revision_drift', $authority);
        self::assertStringContainsString("'working_revision_id' => \$preservedWorkingRevisionId", $authority);
        self::assertStringNotContainsString("throw new DomainException('top100_frozen_personality_foreign_working_revision');", $authority);
        self::assertStringContainsString('top100_frozen_personality_foreign_working_revision_drift', $authority);
        self::assertStringContainsString("'working_revision' => \$this->personalityWorkingRevisionState(\$model)", $authority);
        self::assertStringContainsString("unset(\$assetMutable['working_revision']);", $authority);
    }

    public function test_rollback_guard_accepts_only_pre_draft_or_exact_published_state(): void
    {
        $authority = (new ReflectionClass(Top100FrozenCmsBatchAuthority::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass($authority))->getMethod('rollbackStateIsOwned');
        $row = [
            'before' => ['mutable' => ['title' => 'before', 'working_revision_id' => null]],
            'desired' => ['title' => 'published', 'working_revision_id' => null],
        ];

        self::assertTrue($method->invoke($authority, ['current' => ['mutable' => ['title' => 'before', 'working_revision_id' => 31]]], $row));
        self::assertTrue($method->invoke($authority, ['current' => ['mutable' => ['title' => 'published', 'working_revision_id' => null]]], $row));
        self::assertFalse($method->invoke($authority, ['current' => ['mutable' => ['title' => 'newer edit', 'working_revision_id' => null]]], $row));
    }

    public function test_article_links_are_idempotent_and_api_values_are_compared_structurally(): void
    {
        $authority = (new ReflectionClass(Top100FrozenCmsBatchAuthority::class))->newInstanceWithoutConstructor();
        $append = (new ReflectionClass($authority))->getMethod('appendMarkdownLinks');
        $links = [['anchor_text' => '中文链接', 'href' => '/zh/personality/intp-a-vs-intp-t']];
        $once = $append->invoke($authority, 'Body', $links);

        self::assertSame($once, $append->invoke($authority, $once, $links));
        self::assertSame(1, substr_count($once, '/zh/personality/intp-a-vs-intp-t'));

        $flatten = (new ReflectionClass($authority))->getMethod('publicScalarValues');
        self::assertContains('/zh/personality/intp-a-vs-intp-t', $flatten->invoke($authority, [
            'data' => ['links' => [['href' => '/zh/personality/intp-a-vs-intp-t']], 'title' => '中文链接'],
        ]));
        $projected = (new ReflectionClass($authority))->getMethod('publicValueProjected');
        self::assertTrue($projected->invoke($authority, '中文开头', ['中文开头，其余正文']));
        self::assertFalse($projected->invoke($authority, '不存在', ['中文开头，其余正文']));

        $linkProjected = (new ReflectionClass($authority))->getMethod('publicLinkProjected');
        self::assertTrue($linkProjected->invoke($authority, [
            'data' => ['links' => [['href' => '/zh/personality/intp-a-vs-intp-t', 'anchor_text' => '中文链接']]],
        ], '中文链接', '/zh/personality/intp-a-vs-intp-t'));
        self::assertTrue($linkProjected->invoke($authority, ['body' => '[中文链接](/zh/personality/intp-a-vs-intp-t)'], '中文链接', '/zh/personality/intp-a-vs-intp-t'));
        self::assertFalse($linkProjected->invoke($authority, [
            'data' => ['links' => [['href' => '/zh/personality/intp-a-vs-intp-t', 'anchor_text' => '错误锚文本']]],
            'unrelated' => '中文链接',
        ], '中文链接', '/zh/personality/intp-a-vs-intp-t'));

        $htmlLinkProjected = (new ReflectionClass($authority))->getMethod('liveHtmlLinkProjected');
        self::assertTrue($htmlLinkProjected->invoke($authority, '<a class="link" href="/zh/personality/intp-a-vs-intp-t"><span>中文链接</span></a>', '中文链接', '/zh/personality/intp-a-vs-intp-t'));
        self::assertFalse($htmlLinkProjected->invoke($authority, '<a href="/zh/personality/intp-a-vs-intp-t">错误锚文本</a><p>中文链接</p>', '中文链接', '/zh/personality/intp-a-vs-intp-t'));

        $replaceIntro = (new ReflectionClass($authority))->getMethod('replaceFirstParagraph');
        self::assertSame("## 保留标题\n\n新导语\n\n后续正文", $replaceIntro->invoke($authority, "## 保留标题\n\n旧导语\n\n后续正文", '新导语'));

        $htmlDocument = (new ReflectionClass($authority))->getMethod('htmlDocument');
        [$document, $xpath] = $htmlDocument->invoke($authority, '<html><head><title>旧标题</title></head><body><h1>旧标题</h1><script>{"title":"新标题","intro":"新导语"}</script><p>旧导语</p></body></html>');
        $xpathText = (new ReflectionClass($authority))->getMethod('xpathTextProjected');
        $visibleText = (new ReflectionClass($authority))->getMethod('visibleTextProjected');
        self::assertFalse($xpathText->invoke($authority, $xpath, '//title', '新标题'));
        self::assertFalse($xpathText->invoke($authority, $xpath, '//h1', '新标题'));
        self::assertFalse($visibleText->invoke($authority, $document, $xpath, '新导语'));
    }

    public function test_article_revision_publish_guard_rejects_post_import_payload_drift(): void
    {
        $authority = (new ReflectionClass(Top100FrozenCmsBatchAuthority::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass($authority))->getMethod('articleRevisionMatchesTarget');
        $article = new Article(['published_revision_id' => 41]);
        $target = [
            'source_row_sha256' => str_repeat('a', 64),
            'desired' => [
                'article' => ['title' => 'Approved title', 'excerpt' => 'Approved excerpt', 'content_md' => 'Approved body', 'working_revision_id' => 777],
                'seo' => ['seo_title' => 'Approved SEO', 'seo_description' => 'Approved description', 'og_title' => 'Approved SEO'],
                'revision_statuses' => [777 => ArticleTranslationRevision::STATUS_APPROVED],
            ],
        ];
        $revision = new ArticleTranslationRevision([
            'supersedes_revision_id' => 41,
            'authority_source_hash' => str_repeat('a', 64),
            'authority_metadata_json' => ['desired_payload_sha256' => hash('sha256', PromotionContextFactory::canonicalJson([
                'article' => ['title' => 'Approved title', 'excerpt' => 'Approved excerpt', 'content_md' => 'Approved body'],
                'seo' => ['seo_title' => 'Approved SEO', 'seo_description' => 'Approved description'],
            ]))],
            'source_version_hash' => hash('sha256', PromotionContextFactory::canonicalJson([
                'title' => 'Approved title', 'excerpt' => 'Approved excerpt', 'content_md' => 'Approved body',
            ])),
            'title' => 'Approved title',
            'excerpt' => 'Approved excerpt',
            'content_md' => 'Approved body',
            'seo_title' => 'Approved SEO',
            'seo_description' => 'Approved description',
        ]);

        self::assertTrue($method->invoke($authority, $revision, $article, $target));
        $revision->content_md = 'Concurrent unapproved edit';
        self::assertFalse($method->invoke($authority, $revision, $article, $target));
    }

    public function test_personality_working_revision_state_binds_identity_status_and_snapshot(): void
    {
        $authority = (new ReflectionClass(Top100FrozenCmsBatchAuthority::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass($authority))->getMethod('personalityRevisionState');
        $revision = new PersonalityPublicContentAssetRevision([
            'asset_id' => 41,
            'workflow_state' => PersonalityPublicContentAssetRevision::STATE_DRAFT,
            'authority_asset_key' => 'other-controlled-operation',
            'authority_package_sha256' => str_repeat('a', 64),
            'source_package' => 'other/package',
            'source_hash' => str_repeat('b', 64),
            'snapshot_json' => ['title' => 'Concurrent working draft'],
        ]);
        $revision->id = 73;

        $state = $method->invoke($authority, $revision);
        self::assertSame(73, $state['id']);
        self::assertSame(41, $state['asset_id']);
        self::assertSame(PersonalityPublicContentAssetRevision::STATE_DRAFT, $state['workflow_state']);
        self::assertSame(hash('sha256', PromotionContextFactory::canonicalJson(['title' => 'Concurrent working draft'])), $state['snapshot_sha256']);

        $revision->workflow_state = 'published';
        self::assertNotSame($state, $method->invoke($authority, $revision));
    }

    public function test_v2_receipt_schema_accepts_top100_lane_package_and_evidence_fields(): void
    {
        $schema = json_decode((string) File::get(dirname(__DIR__, 3).'/docs/schemas/content-promotion-receipt.v2.schema.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertMatchesRegularExpression('~'.$schema['properties']['lane']['pattern'].'~', 'TOP100');
        self::assertMatchesRegularExpression('~'.$schema['properties']['package_path']['pattern'].'~', 'content_assets/seo-top100/SEO-TOP100-FROZEN-20260812-v1');
        foreach (['batch_id', 'target_count', 'planned_changed_count', 'planned_unchanged_count', 'unknown_count',
            'hold_write_count', 'control_write_count', 'media_mutation_count', 'canonical_mutation_count',
            'hreflang_mutation_count', 'schema_type_mutation_count', 'deferred_out_of_target_link_source_count',
            'target_state_sha256', 'approved_prestate_sha256', 'public_api_readback_count', 'live_html_readback_count'] as $property) {
            self::assertArrayHasKey($property, $schema['properties']);
        }
    }

    private function packageDirectory(): string
    {
        return dirname(__DIR__, 3).'/content_assets/seo-top100/'.Top100FrozenPackage::BATCH_ID;
    }

    private function copyPackage(): string
    {
        $directory = sys_get_temp_dir().'/top100-frozen-'.bin2hex(random_bytes(8));
        File::copyDirectory($this->packageDirectory(), $directory);
        $this->directories[] = $directory;

        return $directory;
    }

    private function context(string $directory): PromotionContext
    {
        return new PromotionContext($directory, Top100FrozenPackage::PACKAGE_SHA256, 'TOP100', Top100FrozenPackage::SUBSCOPE, str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '1', 1, str_repeat('d', 64), 30, str_repeat('e', 64));
    }
}
