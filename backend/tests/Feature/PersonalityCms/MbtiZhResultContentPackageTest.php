<?php

declare(strict_types=1);

namespace Tests\Feature\PersonalityCms;

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
