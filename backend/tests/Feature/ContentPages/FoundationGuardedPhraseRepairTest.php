<?php

declare(strict_types=1);

namespace Tests\Feature\ContentPages;

use Tests\TestCase;

final class FoundationGuardedPhraseRepairTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function foundationPage(string $locale): array
    {
        $path = dirname(__DIR__, 4)."/content_baselines/content_pages/content_pages.{$locale}.json";

        $this->assertFileExists($path);

        $pages = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        foreach ($pages as $page) {
            if (($page['slug'] ?? null) === 'foundation') {
                return $page;
            }
        }

        $this->fail("Foundation baseline page missing for {$locale}.");
    }

    public function test_english_foundation_baseline_removes_guarded_relationship_and_entity_phrases(): void
    {
        $page = $this->foundationPage('en');
        $publicText = $this->publicText($page);

        foreach ($this->englishGuardedPhrases() as $phrase) {
            $this->assertStringNotContainsStringIgnoringCase($phrase, $publicText);
        }

        $this->assertStringContainsString("FermatMind's own public record", $publicText);
        $this->assertStringContainsString('does not ask users to donate', $publicText);
    }

    public function test_chinese_foundation_baseline_removes_guarded_relationship_and_entity_phrases(): void
    {
        $page = $this->foundationPage('zh-CN');
        $publicText = $this->publicText($page);

        foreach ($this->chineseGuardedPhrases() as $phrase) {
            $this->assertStringNotContainsString($phrase, $publicText);
        }

        $this->assertStringContainsString('自行发起、执行和记录', $publicText);
        $this->assertStringContainsString('不面向用户收取款项', $publicText);
    }

    /**
     * @param  array<string, mixed>  $page
     */
    private function publicText(array $page): string
    {
        return implode("\n", array_filter([
            $page['title'] ?? '',
            $page['summary'] ?? '',
            $page['seoTitle'] ?? '',
            $page['metaDescription'] ?? '',
            $page['contentMd'] ?? '',
        ]));
    }

    /**
     * @return list<string>
     */
    private function englishGuardedPhrases(): array
    {
        return [
            'formal relationship',
            'certification',
            'affiliation',
            'approval',
            'endorsement',
            'public-benefit legal entity',
            'registered foundation',
            'nonprofit legal entity',
            'charitable organization',
            'fundraising',
            'sponsorship',
            'official partnership',
            'official cooperation',
            'authorized by',
            'approved by',
            'certified by',
            'endorsed by',
            'donation on behalf of',
        ];
    }

    /**
     * @return list<string>
     */
    private function chineseGuardedPhrases(): array
    {
        return [
            '正式关系',
            '正式合作',
            '官方合作',
            '官方认证',
            '认证',
            '背书',
            '授权',
            '批准',
            '隶属',
            '代表募款',
            '代募',
            '募资',
            '公益法人',
            '慈善组织',
            '注册基金会',
            '法定公益组织',
            '非营利实体',
        ];
    }
}
