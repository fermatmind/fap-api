<?php

declare(strict_types=1);

namespace Tests\Feature\Personality;

use App\Domain\Personality\Current\PersonalityCurrentAuthorityPackage;
use Tests\TestCase;

final class PersonalityCurrentAtComparisonRuntimeTest extends TestCase
{
    public function test_at_comparison_is_served_from_the_per_page_current_authority(): void
    {
        $response = $this->getJson('/api/v0.5/personality/comparisons/intj-a-vs-intj-t?locale=zh-CN&org_id=0&scale_code=MBTI');

        $expected = json_decode(
            file_get_contents(base_path('content_assets/personality_public/current/pages/mbti/comparison-at/intj-a-vs-intj-t/zh-CN.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $response->assertOk()
            ->assertHeader('X-Fermat-Content-Authority', 'personality.page.content.v1')
            ->assertHeader('X-Fermat-Content-Aggregate', '31d6dce9f57621dbb3882e311c685bbde9498aec78082a3355ebc6e1a0307500')
            ->assertExactJson(['ok' => true, ...$expected['payload']]);
    }

    public function test_runtime_index_binds_the_manifest_without_scanning_all_page_bodies(): void
    {
        $index = (new PersonalityCurrentAuthorityPackage)->runtimeIndex(base_path());

        self::assertCount(364, $index['entries']);
        self::assertArrayHasKey('mbti|comparison_at|intj-a-vs-intj-t|en', $index['entries']);
    }

    public function test_at_comparison_compatibility_copy_contains_the_current_visible_body(): void
    {
        $files = glob(base_path('content_assets/personality_public/current/pages/mbti/comparison-at/*/*.json'));
        self::assertCount(32, $files);

        foreach ($files as $file) {
            $payload = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)['payload'];
            $projection = $payload['comparison_public_projection_v1'];
            self::assertSame($projection, $payload['comparison'], $file);
            self::assertSame(
                $payload['answer_surface_v1']['faq_blocks'][0]['answer'],
                $projection['faq'][0]['answer'],
                $file,
            );

            $visible = array_values(array_filter(
                $payload['sections'],
                fn (array $section): bool => $section['section_key'] !== 'faq',
            ));
            self::assertSame(array_column($visible, 'section_key'), array_column($projection['sections'], 'id'), $file);

            foreach ($visible as $offset => $section) {
                self::assertSame($section['body_md'] ?? '', implode("\n\n", $projection['sections'][$offset]['body']), $file);
            }
        }
    }
}
