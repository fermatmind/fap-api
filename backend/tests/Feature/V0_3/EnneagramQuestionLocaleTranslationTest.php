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
