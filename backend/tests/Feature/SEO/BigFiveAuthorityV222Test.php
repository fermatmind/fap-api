<?php

namespace Tests\Feature\SEO;

use Tests\TestCase;

class BigFiveAuthorityV222Test extends TestCase
{
    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packagePath = base_path('../generated/big-five-authority-v2/big5-authority-v2-article-refresh-22');
    }

    public function test_package_refreshes_only_the_exact_nine_article_locale_records(): void
    {
        $package = $this->readJson('article-refresh-candidates.json');
        $expected = [
            'en:big-five-conscientiousness-low-procrastination-task-plan',
            'en:big-five-emotional-stability-stress-recovery-communication',
            'en:big-five-personality-test-vs-mbti',
            'zh-CN:big-five-conscientiousness-low-procrastination-task-plan',
            'zh-CN:big-five-emotional-stability-stress-recovery-communication',
            'zh-CN:big-five-growth-guide',
            'zh-CN:big-five-narrative-portrait',
            'zh-CN:big-five-personality-test-vs-mbti',
            'zh-CN:big-five-tool-guide',
        ];
        $actual = array_map(fn (array $item): string => "{$item['locale']}:{$item['slug']}", $package['candidates']);
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    public function test_every_article_has_the_full_refresh_structure_and_visible_limited_sources(): void
    {
        $package = $this->readJson('article-refresh-candidates.json');
        $required = ['direct_opening', 'logic', 'example', 'counterexample', 'action_framework', 'boundary', 'sources', 'next_steps'];

        foreach ($package['candidates'] as $candidate) {
            $this->assertSame($required, array_column($candidate['sections'], 'key'));
            $this->assertNotEmpty($candidate['locked_intent']);
            $this->assertNotEmpty($candidate['source_mapping']);
            $this->assertGreaterThanOrEqual(3, count($candidate['internal_link_targets']));
            foreach ($candidate['source_mapping'] as $source) {
                $this->assertStringStartsWith('https://doi.org/', $source['public_url']);
                $this->assertNotEmpty($source['limitation']);
            }
        }
    }

    public function test_titles_and_cms_attribution_do_not_invent_brand_or_people(): void
    {
        $articles = $this->readJson('article-refresh-candidates.json');
        $hubs = $this->readJson('topic-hub-candidates.json');

        foreach ([...$articles['candidates'], ...$hubs['candidates']] as $candidate) {
            $this->assertSame('pending_manual_review', $candidate['review_status']);
            $this->assertNull($candidate['cms_authority']['author']);
            $this->assertNull($candidate['cms_authority']['reviewer']);
            $this->assertNull($candidate['cms_authority']['published_at']);
            $this->assertNull($candidate['cms_authority']['updated_at']);
            $this->assertStringNotContainsString('FermatMind', $candidate['title']);
            $this->assertStringNotContainsString('费马测试', $candidate['title']);
        }
    }

    public function test_topic_hubs_are_backend_enumerated_and_have_no_hardcoded_entries(): void
    {
        $package = $this->readJson('topic-hub-candidates.json');

        $this->assertSame(['/en/topics/big-five', '/zh/topics/big-five'], array_column($package['candidates'], 'route'));
        foreach ($package['candidates'] as $candidate) {
            $this->assertSame('backend_public_api', $candidate['enumeration']['source']);
            $this->assertSame(['published', 'eligible'], $candidate['enumeration']['required_states']);
            $this->assertSame([], $candidate['enumeration']['hardcoded_entries']);
            $this->assertContains('pending_manual_review', $candidate['enumeration']['exclude']);
        }
    }

    public function test_qa_proves_zero_write_publish_index_and_new_wave_mutations(): void
    {
        $qa = $this->readJson('qa_report.json');

        $this->assertSame('PASS_PENDING_MANUAL_REVIEW', $qa['status']);
        $this->assertSame(['article_candidates' => 9, 'en_articles' => 3, 'zh_cn_articles' => 6, 'topic_hubs' => 2, 'total_surfaces' => 11], $qa['counts']);
        $this->assertSame(0, $qa['checks']['cms_attribution_values_synthesized']);
        $this->assertSame(0, $qa['checks']['topic_hub_hardcoded_entries']);
        $this->assertSame(0, $qa['checks']['cms_writes']);
        $this->assertSame(0, $qa['checks']['publication_changes']);
        $this->assertSame(0, $qa['checks']['indexability_changes']);
        $this->assertSame(0, $qa['checks']['pr24_33_articles_added']);
    }

    /** @return array<string, mixed> */
    private function readJson(string $file): array
    {
        $contents = file_get_contents($this->packagePath.'/'.$file);
        $this->assertNotFalse($contents, "Unable to read {$file}");

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }
}
