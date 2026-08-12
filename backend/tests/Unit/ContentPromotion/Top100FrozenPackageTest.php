<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Services\ContentPromotion\PromotionContext;
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
