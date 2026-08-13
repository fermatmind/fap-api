<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Mbti;

use App\Support\Mbti\MbtiZhResultContentPolicy;
use Tests\TestCase;

final class MbtiZhResultContentPolicyTest extends TestCase
{
    public function test_exact_fixture_percentages_have_stable_scientific_clarity_states(): void
    {
        $this->assertSame([
            50 => 'tie',
            51 => 'close_call',
            55 => 'close_call',
            60 => 'clear',
            75 => 'very_clear',
        ], array_combine(
            [50, 51, 55, 60, 75],
            array_map(MbtiZhResultContentPolicy::clarityState(...), [50, 51, 55, 60, 75]),
        ));
    }

    public function test_zh_projection_removes_rarity_normalizes_faq_and_marks_at_as_extension(): void
    {
        $projection = MbtiZhResultContentPolicy::normalizeProjection([
            'profile' => ['rarity' => '约 1–3%'],
            'dimensions' => [
                ['id' => 'EI', 'pct' => 50],
                ['id' => 'SN', 'pct' => 51],
                ['id' => 'TF', 'pct' => 55],
                ['id' => 'JP', 'pct' => 60],
                ['id' => 'AT', 'pct' => 75, 'label' => '身份层'],
            ],
            'sections' => [[
                'key' => 'faq',
                'title' => 'Frequently asked questions',
                'payload' => [
                    'title' => 'Frequently asked questions',
                    'items' => array_map(static fn (int $index): array => [
                        'question' => '问题 '.$index,
                        'answer' => '答案 '.$index,
                    ], range(1, 12)),
                ],
            ]],
        ], 'zh-CN');

        $this->assertNull(data_get($projection, 'profile.rarity'));
        $this->assertSame(
            ['tie', 'close_call', 'close_call', 'clear', 'very_clear'],
            array_column($projection['dimensions'], 'clarity_state'),
        );
        $this->assertTrue((bool) data_get($projection, 'dimensions.0.tie_break_applied'));
        $this->assertSame('压力与反馈风格（FermatMind 扩展）', data_get($projection, 'dimensions.4.label'));
        $this->assertSame('常见问题', data_get($projection, 'sections.0.title'));
        $this->assertSame('常见问题', data_get($projection, 'sections.0.payload.title'));
        $this->assertCount(4, (array) data_get($projection, 'sections.0.payload.items'));
        $this->assertStringContainsString('不是官方 MBTI', (string) data_get($projection, 'scientific_context.at_dimension.status'));
        $this->assertStringContainsString('不是回答一致性', (string) data_get($projection, 'scientific_context.metric_definition'));
        $this->assertStringContainsString('招聘', implode('', (array) data_get($projection, 'scientific_context.use_limits')));
        $this->assertStringContainsString('职业结果保证', implode('', (array) data_get($projection, 'scientific_context.use_limits')));
    }

    public function test_all_32_zh_assets_have_consistent_schema_and_no_english_residue(): void
    {
        $clone = $this->decodeBaseline('personality_clone/mbti_desktop_clone.zh-CN.json');
        $personality = $this->decodeBaseline('personality/mbti.zh-CN.json');

        $cloneVariants = (array) ($clone['variants'] ?? []);
        $profileVariants = (array) ($personality['variants'] ?? []);
        $this->assertCount(32, $cloneVariants);
        $this->assertCount(32, $profileVariants);
        $this->assertCount(32, array_unique(array_column($cloneVariants, 'full_code')));
        $this->assertCount(32, array_unique(array_column($profileVariants, 'runtime_type_code')));

        foreach ($cloneVariants as $variant) {
            $fullCode = (string) ($variant['full_code'] ?? 'unknown');
            $content = (array) ($variant['content_json'] ?? []);
            $this->assertNull(data_get($content, 'hero.profile_identity.rarity'), $fullCode.' rarity must be absent');
            $this->assertIsArray($content['traits'] ?? null, $fullCode.' traits missing');
            foreach (['career', 'growth', 'relationships'] as $chapter) {
                $this->assertIsArray(data_get($content, 'chapters.'.$chapter), $fullCode.' '.$chapter.' missing');
            }

            $slots = (array) ($variant['asset_slots_json'] ?? []);
            $this->assertCount(7, $slots, $fullCode.' asset slot count');
            foreach ($slots as $slot) {
                $this->assertSame('', (string) ($slot['alt'] ?? ''), $fullCode.' decorative alt');
                $this->assertDoesNotMatchRegularExpression('/desktop clone|[A-Za-z]{3,}/i', (string) ($slot['label'] ?? ''), $fullCode.' label');
            }
        }

        foreach ($profileVariants as $variant) {
            $fullCode = (string) ($variant['runtime_type_code'] ?? 'unknown');
            $this->assertNull(data_get($variant, 'profile_overrides.rarity_text'), $fullCode.' rarity must be absent');
            $faq = collect((array) ($variant['section_overrides'] ?? []))
                ->firstWhere('section_key', 'faq');
            $this->assertIsArray($faq, $fullCode.' faq missing');
            $this->assertSame('常见问题', data_get($faq, 'title'));
            $this->assertSame('常见问题', data_get($faq, 'payload_json.title'));
            $this->assertCount(4, (array) data_get($faq, 'payload_json.items'));
        }

        $serialized = json_encode([$clone, $personality], JSON_UNESCAPED_UNICODE) ?: '';
        $this->assertStringNotContainsString('Frequently asked questions', $serialized);
        $this->assertStringNotContainsString('desktop clone', strtolower($serialized));
        $this->assertStringNotContainsString('费马心理', $serialized);
        $this->assertStringNotContainsString('不爱空喊口号', $serialized);
        $this->assertStringNotContainsString('它让成长从口号变成可重复的方法', $serialized);
    }

    /** @return array<string, mixed> */
    private function decodeBaseline(string $path): array
    {
        $raw = file_get_contents(base_path('../content_baselines/'.$path));
        $this->assertIsString($raw);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
