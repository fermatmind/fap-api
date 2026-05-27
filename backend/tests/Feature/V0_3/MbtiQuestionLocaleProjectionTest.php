<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class MbtiQuestionLocaleProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('content_packs.mbti_response_cache_store', 'array');
        Config::set('content_packs.loader_cache_store', 'array');
        Cache::store('array')->flush();

        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');
        $this->artisan('fap:scales:sync-slugs');
    }

    public function test_mbti_93_projects_english_display_fields_without_changing_count_or_scoring(): void
    {
        $response = $this->getJson('/api/v0.3/scales/MBTI/questions?locale=en&form_code=mbti_93');

        $response->assertOk();
        $response->assertJsonPath('locale', 'en');
        $response->assertJsonPath('form_code', 'mbti_93');

        $items = (array) data_get($response->json(), 'questions.items', []);
        $this->assertCount(93, $items);
        $this->assertSame('You often make new friends.', data_get($items, '0.text'));
        $this->assertSame('你经常结交新朋友。', data_get($items, '0.text_zh'));
        $this->assertSame('Strongly agree', data_get($items, '0.options.0.text'));
        $this->assertSame('非常认同', data_get($items, '0.options.0.text_zh'));
        $this->assertSame(2, data_get($items, '0.options.0.score'));

        $this->assertDisplayedFieldsDoNotContainChinese($items);
    }

    public function test_mbti_144_projects_english_display_fields_without_changing_count_or_scoring(): void
    {
        $response = $this->getJson('/api/v0.3/scales/MBTI/questions?locale=en&form_code=mbti_144');

        $response->assertOk();
        $response->assertJsonPath('locale', 'en');
        $response->assertJsonPath('form_code', 'mbti_144');

        $items = (array) data_get($response->json(), 'questions.items', []);
        $this->assertCount(144, $items);
        $this->assertSame('You often make new friends.', data_get($items, '0.text'));
        $this->assertSame('Strongly disagree', data_get($items, '0.options.4.text'));
        $this->assertSame(-2, data_get($items, '0.options.4.score'));

        $this->assertDisplayedFieldsDoNotContainChinese($items);
    }

    public function test_mbti_zh_locale_keeps_chinese_display_fields(): void
    {
        $response = $this->getJson('/api/v0.3/scales/MBTI/questions?locale=zh-CN&form_code=mbti_93');

        $response->assertOk();
        $response->assertJsonPath('locale', 'zh-CN');
        $response->assertJsonPath('form_code', 'mbti_93');

        $items = (array) data_get($response->json(), 'questions.items', []);
        $this->assertCount(93, $items);
        $this->assertSame('你经常结交新朋友。', data_get($items, '0.text'));
        $this->assertSame('非常认同', data_get($items, '0.options.0.text'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function assertDisplayedFieldsDoNotContainChinese(array $items): void
    {
        foreach ($items as $item) {
            $this->assertDoesNotMatchRegularExpression('/\\p{Han}/u', (string) ($item['text'] ?? ''));

            foreach ((array) ($item['options'] ?? []) as $option) {
                if (! is_array($option)) {
                    continue;
                }

                $this->assertDoesNotMatchRegularExpression('/\\p{Han}/u', (string) ($option['text'] ?? ''));
            }
        }
    }
}
