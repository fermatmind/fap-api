<?php

declare(strict_types=1);

namespace Tests\Feature\PersonalityCms;

use App\PersonalityCms\DesktopClone\MbtiResultChapterCopy;
use App\PersonalityCms\DesktopClone\MbtiZhResultContentPackage;
use Tests\TestCase;

final class MbtiZhResultContentPackageTest extends TestCase
{
    private const SHARED_SCIENCE_COPY_ALLOWLIST = [
        '结果用于自我探索，不用于诊断、招聘筛选或能力定级。',
        '把职业段落当作环境与工作方式的探索线索，优先用真实任务、反馈和持续表现验证，不把类型代码当作录用或胜任力依据。',
        '建议描述的是可尝试的行为，不是治疗方案。选择一个高频场景做小幅调整，并观察结果是否改善；若涉及持续困扰，应寻求合格专业支持。',
        'A/T 是费马测试 / FermatMind 对压力、反馈敏感度与自我确认方式的扩展观察，不是官方 MBTI 第五轴，也不等同于抗压能力或心理健康水平。',
    ];

    public function test_all_scenario_modules_preserve_reviewed_copy_and_stable_identifiers(): void
    {
        $baseline = json_decode((string) file_get_contents(base_path('../content_baselines/personality/mbti.zh-CN.json')), true, flags: JSON_THROW_ON_ERROR);
        $variants = collect($baseline['variants'])->keyBy('runtime_type_code');
        $paths = [
            'growth.motivators' => 'growth.what_energizes',
            'growth.drainers' => 'growth.what_drains',
            'relationships.rel_advantages' => 'relationships.superpowers',
            'relationships.rel_risks' => 'relationships.pitfalls',
        ];
        $count = 0;
        foreach (app(MbtiZhResultContentPackage::class)->compile()['rows'] as $row) {
            $code = $row['full_code'];
            $sections = collect($variants[$code]['section_overrides'])->keyBy('section_key');
            foreach ($paths as $sourcePath => $path) {
                $context = $code.'.'.$path;
                $source = $sections[$sourcePath]['payload_json'];
                $module = data_get($row, 'content_json.chapters.'.$path);
                $this->assertSame('insight_list_v1', $module['schema_version'], $context);
                $this->assertSame($source['intro'], $module['intro'], $context);
                $this->assertSame($source['intro'], $source['teaser'], $context);
                $this->assertSame($source['intro'], $sections[$sourcePath]['body_md'], $context);
                $this->assertCount(3, $module['items'], $context);
                $this->assertSame(array_column($source['items'], 'id'), array_column($module['items'], 'id'), $context);
                $chapter = explode('.', $path)[0];
                $summaryItems = array_merge(
                    data_get($row, 'content_json.chapters.'.$chapter.'.strengths.items', []),
                    data_get($row, 'content_json.chapters.'.$chapter.'.weaknesses.items', []),
                );
                foreach ($module['items'] as $index => $item) {
                    $this->assertContains('scenario_editorial_v1', $item['tags'], $context);
                    $this->assertCount(1, $item['signals'], $context);
                    $this->assertSame($item['body'], $item['description'], $context);
                    foreach (['title', 'body', 'description', 'why_it_matters', 'signals', 'actions', 'tags'] as $field) {
                        $this->assertSame($source['items'][$index][$field], $item[$field], $context.'.'.$field);
                    }
                    foreach ([$item['title'], $item['body'], $item['why_it_matters'], $item['signals'][0], $item['actions']['do'], $item['actions']['avoid']] as $text) {
                        $this->assertNotSame('', trim($text), $context);
                        $this->assertDoesNotMatchRegularExpression('/视角：|[。！？；][，。；]/u', $text, $context);
                    }
                    $this->assertNotContains($item['title'], array_column($summaryItems, 'title'), $context);
                    $this->assertNotContains($item['body'], array_column($summaryItems, 'description'), $context);
                    $count++;
                }
            }
        }
        $this->assertSame(384, $count);
    }

    public function test_package_is_deterministic_complete_and_has_no_consumable_media(): void
    {
        $first = app(MbtiZhResultContentPackage::class)->compile();
        $second = app(MbtiZhResultContentPackage::class)->compile();

        $this->assertSame($first['package_hash'], $second['package_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['package_hash']);
        $this->assertSame(32, $first['record_count']);
        $this->assertCount(32, $first['source_manifest']);
        $this->assertSame(
            array_values(array_unique(array_column($first['source_manifest'], 'full_code'))),
            array_column($first['source_manifest'], 'full_code'),
        );

        $disabledSlots = 0;
        foreach ($first['rows'] as $row) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $row['source_hash']);
            $this->assertCount(4, $row['content_json']['faq']);
            foreach (['career', 'growth', 'relationships'] as $chapter) {
                $this->assertIsArray($row['content_json']['chapters'][$chapter]);
            }
            foreach ($row['asset_slots_json'] as $slot) {
                $this->assertSame('disabled', $slot['status']);
                $this->assertNull($slot['asset_ref']);
                $this->assertSame('', $slot['alt']);
                $disabledSlots++;
            }
        }
        $this->assertSame(224, $disabledSlots);
    }

    public function test_chapter_assets_cover_all_full_types_and_preserve_the_existing_contract(): void
    {
        $assets = app(MbtiResultChapterCopy::class)->load();
        $paragraphs = [];
        foreach (app(MbtiZhResultContentPackage::class)->compile()['rows'] as $row) {
            $code = $row['full_code'];
            $this->assertSame($assets[$code]['faq'], $row['content_json']['faq']);
            foreach (MbtiResultChapterCopy::CHAPTERS as $chapter) {
                $intro = $row['content_json']['chapters'][$chapter]['intro'];
                $this->assertSame($assets[$code]['chapters'][$chapter], $intro);
                $this->assertCount(2, $intro);
                $this->assertGreaterThanOrEqual(300, mb_strlen(implode('', $intro)), $code.'.'.$chapter);
                $this->assertLessThanOrEqual(370, mb_strlen(implode('', $intro)), $code.'.'.$chapter);
                $paragraphs[] = implode('', $intro);
            }
            foreach ($row['content_json']['faq'] as $faq) {
                $this->assertGreaterThanOrEqual(100, mb_strlen($faq['answer']));
            }
        }
        $this->assertCount(96, array_unique($paragraphs));
    }

    public function test_restored_traits_keep_distinct_meanings_and_stable_identifiers(): void
    {
        $package = app(MbtiZhResultContentPackage::class)->compile();
        $manifest = json_decode(
            (string) file_get_contents(base_path('content_assets/personality_public/mbti_zh_result_authority_release.v1.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame($manifest['package_hash'], $package['package_hash']);
        $this->assertSame($manifest['package_id'], $package['package_id']);

        $count = 0;
        foreach ($package['rows'] as $row) {
            $this->assertSame($row['full_code'], data_get($row, 'content_json.hero.profile_identity.code'));
            foreach (['career', 'growth', 'relationships'] as $chapter) {
                $prefix = $chapter === 'relationships' ? 'relationship' : $chapter;
                $content = $row['content_json']['chapters'][$chapter];
                $this->assertCount(4, $content['traits_unlock']['items']);
                foreach ($content['traits_unlock']['items'] as $index => $item) {
                    $context = $row['full_code'].'.'.$chapter.'.'.$item['id'];
                    $this->assertSame($prefix.'_trait_'.($index + 1), $item['id'], $context);
                    $this->assertSame($content['influentialTraits'][$index]['label'], $item['label'], $context);
                    $fields = array_map('trim', [
                        $item['why_it_matters'],
                        $item[$prefix.'_expression'],
                        $item[$prefix.'_advantage'],
                        $item['real_world_signal'],
                    ]);
                    $this->assertNotContains('', $fields, $context);
                    $this->assertCount(4, array_unique($fields), $context);
                    $count++;
                }
            }
        }
        $this->assertSame(384, $count);
    }

    public function test_cross_type_content_similarity_stays_below_frozen_thresholds(): void
    {
        $rows = app(MbtiZhResultContentPackage::class)->compile()['rows'];
        $grams = [];
        foreach ($rows as $row) {
            $grams[$row['full_code']] = $this->ngrams($this->readerText($row['content_json']));
        }

        $codes = array_keys($grams);
        foreach ($codes as $leftIndex => $leftCode) {
            foreach (array_slice($codes, $leftIndex + 1) as $rightCode) {
                $similarity = $this->jaccard($grams[$leftCode], $grams[$rightCode]);
                $threshold = substr($leftCode, 0, 4) === substr($rightCode, 0, 4) ? 0.90 : 0.75;
                $this->assertLessThan($threshold, $similarity, $leftCode.' vs '.$rightCode);
            }
        }
    }

    public function test_long_reader_sentences_are_not_shared_across_unrelated_types(): void
    {
        $occurrences = [];
        foreach (app(MbtiZhResultContentPackage::class)->compile()['rows'] as $row) {
            $this->collectLongReaderStrings($row['content_json'], $row['full_code'], '', $occurrences);
        }

        foreach ($occurrences as $sentence => $codes) {
            if (in_array($sentence, self::SHARED_SCIENCE_COPY_ALLOWLIST, true)) {
                continue;
            }

            $baseTypes = array_unique(array_map(static fn (string $code): string => substr($code, 0, 4), $codes));
            $this->assertLessThanOrEqual(1, count($baseTypes), $sentence.' => '.implode(',', $codes));
        }
    }

    /** @param array<string,list<string>> $occurrences */
    private function collectLongReaderStrings(mixed $value, string $fullCode, string $path, array &$occurrences): void
    {
        if (str_contains($path, 'axis_explainers') || str_contains($path, 'matched_jobs.summary') || str_contains($path, 'finalOffer')) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->collectLongReaderStrings($item, $fullCode, $path.'.'.$key, $occurrences);
            }

            return;
        }
        if (! is_string($value) || mb_strlen(trim($value)) < 12 || preg_match('/[\x{4e00}-\x{9fff}]/u', $value) !== 1) {
            return;
        }

        $occurrences[trim($value)][] = $fullCode;
        $occurrences[trim($value)] = array_values(array_unique($occurrences[trim($value)]));
    }

    /** @param array<string,mixed> $value */
    private function readerText(array $value): string
    {
        $strings = [];
        array_walk_recursive($value, static function (mixed $item) use (&$strings): void {
            if (is_string($item)) {
                $strings[] = $item;
            }
        });

        return preg_replace('/\s+/u', '', implode('', $strings)) ?? '';
    }

    /** @return array<string,true> */
    private function ngrams(string $text): array
    {
        $result = [];
        $length = mb_strlen($text);
        for ($index = 0; $index <= $length - 5; $index++) {
            $result[mb_substr($text, $index, 5)] = true;
        }

        return $result;
    }

    /** @param array<string,true> $left @param array<string,true> $right */
    private function jaccard(array $left, array $right): float
    {
        $intersection = count(array_intersect_key($left, $right));
        $union = count($left) + count($right) - $intersection;

        return $union > 0 ? $intersection / $union : 0.0;
    }
}
