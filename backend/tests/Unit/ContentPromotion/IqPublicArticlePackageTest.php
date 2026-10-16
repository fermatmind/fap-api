<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Services\Cms\ArticleBodyHeadingGuard;
use App\Services\ContentPromotion\IqPublicArticlePackage;
use DomainException;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class IqPublicArticlePackageTest extends TestCase
{
    private ?string $temporaryRoot = null;

    public function test_matrix_package_binds_only_its_new_pair_and_preserves_the_three_existing_guide_examples(): void
    {
        $rows = $this->reader()->read(dirname(__DIR__, 3), IqPublicArticlePackage::MATRIX_SHA256);
        self::assertSame(['IQ-03', 'IQ-03', 'IQ-08', 'IQ-08'], array_column($rows, 'page_id'));
        self::assertSame(['existing_public_update', 'existing_public_update', 'new_source_pair', 'new_source_pair'], array_column($rows, 'operation'));
        foreach (array_slice($rows, 0, 2) as $index => $guide) {
            $baseline = $this->reader()->read(dirname(__DIR__, 3), IqPublicArticlePackage::PARITY_SHA256)[2 + $index];
            $body = preg_replace('~^(?:若你能跟上箭头方向|If the arrow rule is clear)[^\n]+\n\n~mu', '', $guide['snapshot']['content_md']);
            self::assertSame($baseline['snapshot']['content_md'], $body);
            foreach (['title', 'excerpt', 'seo_title', 'seo_description', 'faq_items'] as $field) {
                self::assertSame($baseline['snapshot'][$field], $guide['snapshot'][$field]);
            }
        }
        self::assertTrue(IqPublicArticlePackage::launchesDiscoverability(IqPublicArticlePackage::MATRIX_SHA256, IqPublicArticlePackage::MATRIX_SLUG));
        self::assertFalse(IqPublicArticlePackage::launchesDiscoverability(IqPublicArticlePackage::MATRIX_SHA256, 'iq-test-tool-guide'));
        self::assertFalse(IqPublicArticlePackage::supportsPublishedDigest(IqPublicArticlePackage::MATRIX_SHA256, 'iq-test-growth-guide'));
        self::assertSame(4, IqPublicArticlePackage::rowCountForSha(IqPublicArticlePackage::MATRIX_SHA256));
    }

    public function test_comparison_package_retains_the_complete_guide_except_one_contextual_link(): void
    {
        $rows = $this->reader()->read(dirname(__DIR__, 3), IqPublicArticlePackage::COMPARISON_SHA256);
        $previous = $this->reader()->read(dirname(__DIR__, 3), IqPublicArticlePackage::MATRIX_SHA256);
        self::assertSame(['IQ-03', 'IQ-03', 'IQ-09', 'IQ-09'], array_column($rows, 'page_id'));
        foreach (array_slice($rows, 0, 2) as $index => $guide) {
            $body = preg_replace('~^(?:If you are choosing between providers|如果你还在挑选网站)[^\n]+\n\n~mu', '', $guide['snapshot']['content_md']);
            self::assertSame($previous[$index]['snapshot']['content_md'], $body);
            foreach (['title', 'excerpt', 'seo_title', 'seo_description', 'faq_items'] as $field) {
                self::assertSame($previous[$index]['snapshot'][$field], $guide['snapshot'][$field]);
            }
        }
        self::assertTrue(IqPublicArticlePackage::launchesDiscoverability(IqPublicArticlePackage::COMPARISON_SHA256, IqPublicArticlePackage::COMPARISON_SLUG));
        self::assertFalse(IqPublicArticlePackage::launchesDiscoverability(IqPublicArticlePackage::COMPARISON_SHA256, 'iq-test-tool-guide'));
        self::assertFalse(IqPublicArticlePackage::supportsPublishedDigest(IqPublicArticlePackage::COMPARISON_SHA256, IqPublicArticlePackage::MATRIX_SLUG));
    }

    public function test_mensa_package_is_an_independently_reviewed_pair_without_existing_article_updates(): void
    {
        $rows = $this->reader()->read(dirname(__DIR__, 3), IqPublicArticlePackage::MENSA_SHA256);
        self::assertSame(['IQ-07', 'IQ-07'], array_column($rows, 'page_id'));
        self::assertSame(['new_source_pair', 'new_source_pair'], array_column($rows, 'operation'));
        self::assertSame(['zh-CN', 'en'], array_column(array_column($rows, 'identity'), 'locale'));
        self::assertSame([5, 5], array_map(static fn (array $row): int => count($row['snapshot']['faq_items']), $rows));
        self::assertTrue(IqPublicArticlePackage::supportsPublishedDigest(IqPublicArticlePackage::MENSA_SHA256, IqPublicArticlePackage::MENSA_SLUG));
        self::assertFalse(IqPublicArticlePackage::supportsPublishedDigest(IqPublicArticlePackage::MENSA_SHA256, 'iq-test-tool-guide'));
        self::assertFalse(IqPublicArticlePackage::supportsPublishedDigest(IqPublicArticlePackage::SHA256, IqPublicArticlePackage::MENSA_SLUG));
    }

    protected function tearDown(): void
    {
        if ($this->temporaryRoot !== null) {
            (new Filesystem)->deleteDirectory($this->temporaryRoot);
        }
        parent::tearDown();
    }

    public function test_full_reviewed_bilingual_cohort_retains_source_bytes_and_all_faqs(): void
    {
        $rows = $this->reader()->read(dirname(__DIR__, 3), IqPublicArticlePackage::SHA256);
        self::assertCount(10, $rows);
        self::assertSame(['IQ-02', 'IQ-02', 'IQ-03', 'IQ-03', 'IQ-04', 'IQ-04', 'IQ-05', 'IQ-05', 'IQ-06', 'IQ-06'], array_column($rows, 'page_id'));
        self::assertSame(['zh-CN', 'en', 'zh-CN', 'en', 'zh-CN', 'en', 'zh-CN', 'en', 'zh-CN', 'en'], array_column(array_column($rows, 'identity'), 'locale'));
        self::assertSame([7, 7, 5, 5, 5, 5, 5, 5, 9, 9], array_map(static fn (array $row): int => count($row['snapshot']['faq_items']), $rows));
        $review = json_decode(file_get_contents(dirname(__DIR__, 3).'/'.IqPublicArticlePackage::PACKAGE.'/iq-article-exact-payload-review.json'), true, 32, JSON_THROW_ON_ERROR);
        foreach ($rows as $row) {
            $matches = array_values(array_filter($review['targets'], static fn (array $target): bool => $target['page_id'] === $row['page_id'] && $target['locale'] === $row['identity']['locale']));
            self::assertCount(1, $matches);
            self::assertSame($matches[0]['body_sha256'], hash('sha256', $row['snapshot']['content_md']));
            self::assertSame($matches[0]['candidate_json_sha256'], $row['metadata_sha256']);
            self::assertSame($matches[0]['snapshot_json_sha256'], $row['snapshot_sha256']);
        }
        self::assertSame('existing_public_update', $rows[0]['operation']);
        self::assertSame('new_source_pair', $rows[8]['operation']);
        self::assertSame('new_source_pair', $rows[9]['operation']);
    }

    public function test_translation_edition_retains_guide_examples_and_current_scoring_copy(): void
    {
        $rows = $this->reader()->read(dirname(__DIR__, 3), IqPublicArticlePackage::PARITY_SHA256);
        self::assertSame(['IQ-02', 'IQ-02', 'IQ-03', 'IQ-03', 'IQ-05', 'IQ-05'], array_column($rows, 'page_id'));
        $source = $this->reader()->read(dirname(__DIR__, 3), IqPublicArticlePackage::SEO_SHA256)[0];
        self::assertSame(explode('## 免费智商测试', $source['snapshot']['content_md'])[0], explode('## 免费智商测试', $rows[2]['snapshot']['content_md'])[0]);
        self::assertStringContainsString('IQ得分为126分', $rows[2]['snapshot']['content_md']);
        self::assertStringContainsString('an IQ score of 126', $rows[3]['snapshot']['content_md']);
        self::assertStringContainsString('·', $rows[3]['snapshot']['content_md']);
        self::assertSame(0, preg_match('/\p{sc:Han}/u', $rows[3]['snapshot']['content_md']));
        self::assertSame(6, IqPublicArticlePackage::rowCountForSha(IqPublicArticlePackage::PARITY_SHA256));
        self::assertFalse(IqPublicArticlePackage::supportsPublishedDigest(IqPublicArticlePackage::PARITY_SHA256, 'iq-test-narrative-portrait'));
        self::assertTrue(IqPublicArticlePackage::supportsPublishedDigest(IqPublicArticlePackage::PREVIOUS_PARITY_SHA256, 'iq-test-tool-guide'));
        self::assertSame(IqPublicArticlePackage::PARITY_PACKAGE, IqPublicArticlePackage::pathForSha(IqPublicArticlePackage::PREVIOUS_PARITY_SHA256));
    }

    public function test_bilingual_published_conversion_tables_match_the_current_runtime_model(): void
    {
        $rows = $this->reader()->read(dirname(__DIR__, 3), IqPublicArticlePackage::PARITY_SHA256);
        foreach (array_slice($rows, 0, 2) as $row) {
            $body = $row['snapshot']['content_md'];
            $input = json_decode(file_get_contents(dirname(__DIR__, 3).'/'.IqPublicArticlePackage::PARITY_PACKAGE.'/IQ-02-'.$row['identity']['locale'].'.json'), true, 32, JSON_THROW_ON_ERROR);
            if (isset($input['excerpt'])) {
                self::assertSame($row['snapshot']['excerpt'], $input['excerpt']);
            }
            self::assertStringNotContainsString('5.096', $body);
            self::assertStringNotContainsString('2.034', $body);
            preg_match_all('/^\| (\d+) \| (\d+) \| ([\d.]+)% \|$/m', $body, $matches, PREG_SET_ORDER);
            self::assertCount(31, $matches);
            foreach ($matches as $raw => $match) {
                $score = (new \App\Services\Iq\IqBetaReferenceScore)->fromScore([
                    'status' => 'scored', 'bank_id' => 'IQ_OWNER_ORIGINAL_30', 'raw_score' => $raw,
                    'expected_item_count' => 30, 'answer_count' => 30,
                ]);
                self::assertSame($raw, (int) $match[1]);
                self::assertSame($score['display_score'], (int) $match[2]);
                self::assertSame(number_format($score['percentile'], 2, '.', ''), $match[3]);
            }
        }
    }

    public function test_a_different_authorized_package_digest_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('iq_article_package_digest_mismatch');
        $this->reader()->read(dirname(__DIR__, 3), str_repeat('a', 64));
    }

    public function test_reviewed_seo_refresh_is_limited_to_the_existing_bilingual_guide(): void
    {
        $rows = $this->reader()->read(dirname(__DIR__, 3), IqPublicArticlePackage::SEO_SHA256);
        self::assertSame([
            ['org_id' => 0, 'slug' => 'iq-test-tool-guide', 'locale' => 'zh-CN'],
            ['org_id' => 0, 'slug' => 'iq-test-tool-guide', 'locale' => 'en'],
        ], array_column($rows, 'identity'));
        self::assertSame(['existing_public_update', 'existing_public_update'], array_column($rows, 'operation'));
        self::assertSame(2, IqPublicArticlePackage::rowCountForSha(IqPublicArticlePackage::SEO_SHA256));
        self::assertSame(IqPublicArticlePackage::SEO_PACKAGE, IqPublicArticlePackage::pathForSha(IqPublicArticlePackage::SEO_SHA256));
        self::assertTrue(IqPublicArticlePackage::supportsPublishedDigest(IqPublicArticlePackage::SHA256, 'what-is-iq-and-how-it-is-measured'));
        self::assertTrue(IqPublicArticlePackage::supportsPublishedDigest(IqPublicArticlePackage::SEO_SHA256, 'iq-test-tool-guide'));
        self::assertFalse(IqPublicArticlePackage::supportsPublishedDigest(IqPublicArticlePackage::SEO_SHA256, 'iq-test-score-and-limits-explained'));
        self::assertFalse(IqPublicArticlePackage::supportsPublishedDigest(str_repeat('f', 64), 'iq-test-tool-guide'));
    }

    public function test_changed_seo_refresh_cannot_reuse_the_frozen_review(): void
    {
        $this->temporaryRoot = sys_get_temp_dir().'/iq-guide-refresh-test-'.bin2hex(random_bytes(8));
        (new Filesystem)->copyDirectory(dirname(__DIR__, 3).'/'.IqPublicArticlePackage::SEO_PACKAGE, $this->temporaryRoot.'/'.IqPublicArticlePackage::SEO_PACKAGE);
        file_put_contents($this->temporaryRoot.'/'.IqPublicArticlePackage::SEO_PACKAGE.'/IQ-03-en.md', "\nChanged copy\n", FILE_APPEND);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('iq_article_file_digest_mismatch');
        $this->reader()->read($this->temporaryRoot, IqPublicArticlePackage::SEO_SHA256);
    }

    public function test_changed_body_is_rejected_before_any_candidate_is_returned(): void
    {
        $root = $this->copyPackage();
        file_put_contents($root.'/'.IqPublicArticlePackage::PACKAGE.'/IQ-06-en.md', "\nChanged public copy\n", FILE_APPEND);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('iq_article_file_digest_mismatch');
        $this->reader()->read($root, IqPublicArticlePackage::SHA256);
    }

    public function test_changed_metadata_cannot_reuse_the_original_review(): void
    {
        $root = $this->copyPackage();
        $path = $root.'/'.IqPublicArticlePackage::PACKAGE.'/IQ-03-en.json';
        $metadata = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $metadata['metadata']['description'] = 'A different reader promise.';
        file_put_contents($path, json_encode($metadata, JSON_THROW_ON_ERROR));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('iq_article_file_digest_mismatch');
        $this->reader()->read($root, IqPublicArticlePackage::SHA256);
    }

    public function test_unexpected_files_are_rejected(): void
    {
        $root = $this->copyPackage();
        file_put_contents($root.'/'.IqPublicArticlePackage::PACKAGE.'/unreviewed.json', '{}');
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('iq_article_package_inventory_invalid');
        $this->reader()->read($root, IqPublicArticlePackage::SHA256);
    }

    public function test_symlinked_parent_directory_cannot_supply_identical_bytes(): void
    {
        $root = $this->copyPackage();
        rename($root.'/content_assets/iq_public/articles', $root.'/same-articles');
        symlink($root.'/same-articles', $root.'/content_assets/iq_public/articles');
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('iq_article_package_path_invalid');
        $this->reader()->read($root, IqPublicArticlePackage::SHA256);
    }

    public function test_hardlinked_candidate_cannot_supply_mutable_external_bytes(): void
    {
        $root = $this->copyPackage();
        $path = $root.'/'.IqPublicArticlePackage::PACKAGE.'/IQ-06-en.md';
        link($path, $root.'/shared-body.md');
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('iq_article_package_path_invalid');
        $this->reader()->read($root, IqPublicArticlePackage::SHA256);
    }

    private function reader(): IqPublicArticlePackage
    {
        return new IqPublicArticlePackage(new ArticleBodyHeadingGuard);
    }

    private function copyPackage(): string
    {
        $this->temporaryRoot = sys_get_temp_dir().'/iq-article-package-test-'.bin2hex(random_bytes(8));
        (new Filesystem)->copyDirectory(dirname(__DIR__, 3).'/'.IqPublicArticlePackage::PACKAGE, $this->temporaryRoot.'/'.IqPublicArticlePackage::PACKAGE);

        return $this->temporaryRoot;
    }
}
