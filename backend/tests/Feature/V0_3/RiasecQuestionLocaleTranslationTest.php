<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RiasecQuestionLocaleTranslationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');
        $this->artisan('fap:scales:sync-slugs');
    }

    public function test_riasec_60_english_questions_are_translated_without_changing_count_or_scoring(): void
    {
        $response = $this->getJson('/api/v0.3/scales/RIASEC/questions?locale=en&form_code=riasec_60');

        $response->assertOk();
        $response->assertJsonPath('locale', 'en');
        $response->assertJsonPath('form_code', 'riasec_60');
        $response->assertJsonPath('dir_version', 'v1-standard-60');

        $items = (array) data_get($response->json(), 'questions.items', []);
        $this->assertCount(60, $items);
        $this->assertSame('I enjoy building, repairing, or modifying things by hand.', data_get($items, '0.text'));
        $this->assertSame('我喜欢动手搭建、修理或改装东西。', data_get($items, '0.text_zh'));
        $this->assertSame('Strongly dislike', data_get($items, '0.options.0.text'));
        $this->assertSame('非常不喜欢', data_get($items, '0.options.0.text_zh'));
        $this->assertSame(1, data_get($items, '0.options.0.score'));

        $this->assertDisplayedFieldsDoNotContainChinese($items);
    }

    public function test_riasec_140_english_questions_are_translated_without_changing_count_or_scoring(): void
    {
        $response = $this->getJson('/api/v0.3/scales/RIASEC/questions?locale=en&form_code=riasec_140');

        $response->assertOk();
        $response->assertJsonPath('locale', 'en');
        $response->assertJsonPath('form_code', 'riasec_140');
        $response->assertJsonPath('dir_version', 'v1-enhanced-140');

        $items = (array) data_get($response->json(), 'questions.items', []);
        $this->assertCount(140, $items);
        $this->assertSame('I enjoy building, repairing, or modifying things by hand.', data_get($items, '0.text'));
        $this->assertSame("To confirm that you are answering attentively, please choose '3 Unsure / neutral' for this item.", data_get($items, '132.text'));
        $this->assertSame('Dislike', data_get($items, '136.options.1.text'));
        $this->assertSame(2, data_get($items, '136.options.1.score'));

        $this->assertDisplayedFieldsDoNotContainChinese($items);
    }

    public function test_riasec_zh_locale_keeps_chinese_display_fields(): void
    {
        $response = $this->getJson('/api/v0.3/scales/RIASEC/questions?locale=zh-CN&form_code=riasec_60');

        $response->assertOk();
        $response->assertJsonPath('locale', 'zh-CN');
        $response->assertJsonPath('form_code', 'riasec_60');

        $items = (array) data_get($response->json(), 'questions.items', []);
        $this->assertCount(60, $items);
        $this->assertSame('我喜欢动手搭建、修理或改装东西。', data_get($items, '0.text'));
        $this->assertSame('非常不喜欢', data_get($items, '0.options.0.text'));
        $this->assertSame('I enjoy building, repairing, or modifying things by hand.', data_get($items, '0.text_en'));
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
