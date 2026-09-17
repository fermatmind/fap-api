<?php

namespace Tests\Feature;

use Database\Seeders\ScaleRegistrySeeder;
use ReflectionMethod;
use Tests\TestCase;

class AssessmentLandingContentSeoPublicationTest extends TestCase
{
    private function package(): array
    {
        return json_decode(file_get_contents(database_path('data/assessment_landing_content_seo_20260917.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function baseline(): array
    {
        return [
            'zh' => [
                'why_choose' => ['items' => [['id' => 'riasec-scoring', 'title' => '保留方法', 'body' => '保留正文']]],
                'faq' => [['id' => 'faq-riasec-model', 'q' => '保留问题', 'a' => '保留回答']],
                'unrelated' => '保留中文字段',
            ],
            'en' => [
                'why_choose' => ['items' => [['id' => 'riasec-scoring', 'title' => 'Keep method', 'body' => 'Keep body']]],
                'faq' => [['id' => 'faq-riasec-model', 'q' => 'Keep question', 'a' => 'Keep answer']],
                'unrelated' => 'Keep English field',
            ],
            'fr' => ['title' => 'Conserver'],
        ];
    }

    private function publish(array &$attributes): void
    {
        $method = new ReflectionMethod(ScaleRegistrySeeder::class, 'applyLandingContentSeo');
        $method->invokeArgs(new ScaleRegistrySeeder, [&$attributes]);
    }

    public function test_adds_only_reviewed_bilingual_version_content_and_is_idempotent(): void
    {
        $attributes = ['code' => 'RIASEC', 'content_i18n_json' => $this->baseline()];
        $this->publish($attributes);
        $first = $attributes;
        $this->publish($attributes);
        $this->assertSame($first, $attributes);

        foreach ($this->package()['scales']['RIASEC']['locales'] as $locale => $localizedPackage) {
            $content = $attributes['content_i18n_json'][$locale];
            $this->assertSame($localizedPackage['version_comparison'], $content['version_comparison']);
            $this->assertSame($localizedPackage['version_item'], collect($content['why_choose']['items'])->firstWhere('id', 'versions'));
            $this->assertCount(2, $content['why_choose']['items']);
        }

        $this->assertSame($this->baseline()['fr'], $attributes['content_i18n_json']['fr']);
    }

    public function test_non_target_scale_is_unchanged(): void
    {
        $attributes = ['code' => 'MBTI', 'content_i18n_json' => $this->baseline()];
        $before = $attributes;
        $this->publish($attributes);

        $this->assertSame($before, $attributes);
    }
}
