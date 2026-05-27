<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EnneagramQuestionLocaleTranslationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');
        $this->artisan('fap:scales:sync-slugs');
    }

    public function test_forced_choice_144_english_options_are_translated_without_changing_count_or_scoring(): void
    {
        $response = $this->getJson('/api/v0.3/scales/ENNEAGRAM/questions?locale=en&form_code=enneagram_forced_choice_144');

        $response->assertOk();
        $response->assertJsonPath('locale', 'en');
        $response->assertJsonPath('form_code', 'enneagram_forced_choice_144');
        $response->assertJsonPath('dir_version', 'v1-forced-choice-144');

        $items = (array) data_get($response->json(), 'questions.items', []);
        $this->assertCount(144, $items);
        $this->assertSame('', data_get($items, '0.text'));
        $this->assertSame('A', data_get($items, '0.options.0.code'));
        $this->assertSame('T1', data_get($items, '0.options.0.type_code'));
        $this->assertSame('I notice where things could still be improved.', data_get($items, '0.options.0.text'));
        $this->assertSame('我会留意哪里还能再改进。', data_get($items, '0.options.0.text_zh'));
        $this->assertSame('When I make decisions, I also consider what could go wrong.', data_get($items, '0.options.1.text'));
        $this->assertSame('T6', data_get($items, '0.options.1.type_code'));

        $this->assertDisplayedOptionFieldsDoNotContainChinese($items);
    }

    public function test_forced_choice_144_zh_locale_keeps_chinese_display_fields_with_english_sidecar(): void
    {
        $response = $this->getJson('/api/v0.3/scales/ENNEAGRAM/questions?locale=zh-CN&form_code=enneagram_forced_choice_144');

        $response->assertOk();
        $response->assertJsonPath('locale', 'zh-CN');
        $response->assertJsonPath('form_code', 'enneagram_forced_choice_144');

        $items = (array) data_get($response->json(), 'questions.items', []);
        $this->assertCount(144, $items);
        $this->assertSame('我会留意哪里还能再改进。', data_get($items, '0.options.0.text'));
        $this->assertSame('I notice where things could still be improved.', data_get($items, '0.options.0.text_en'));
        $this->assertSame('我做决定时会把“万一呢”也考虑进去。', data_get($items, '0.options.1.text'));
        $this->assertSame('When I make decisions, I also consider what could go wrong.', data_get($items, '0.options.1.text_en'));
    }

    public function test_likert_105_english_locale_remains_translated(): void
    {
        $response = $this->getJson('/api/v0.3/scales/ENNEAGRAM/questions?locale=en&form_code=enneagram_likert_105');

        $response->assertOk();
        $response->assertJsonPath('locale', 'en');
        $response->assertJsonPath('form_code', 'enneagram_likert_105');
        $response->assertJsonPath('dir_version', 'v1-likert-105');

        $items = (array) data_get($response->json(), 'questions.items', []);
        $this->assertCount(105, $items);
        $this->assertSame('I usually strive to do things as perfectly as possible.', data_get($items, '0.text'));
        $this->assertDisplayedFieldsDoNotContainChinese($items);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function assertDisplayedFieldsDoNotContainChinese(array $items): void
    {
        foreach ($items as $item) {
            $this->assertDoesNotMatchRegularExpression('/\p{Han}/u', (string) ($item['text'] ?? ''));
        }

        $this->assertDisplayedOptionFieldsDoNotContainChinese($items);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function assertDisplayedOptionFieldsDoNotContainChinese(array $items): void
    {
        foreach ($items as $item) {
            foreach ((array) ($item['options'] ?? []) as $option) {
                if (! is_array($option)) {
                    continue;
                }

                $this->assertDoesNotMatchRegularExpression('/\p{Han}/u', (string) ($option['text'] ?? ''));
            }
        }
    }
}
