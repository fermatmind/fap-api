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
            ->assertHeader('X-Fermat-Content-Aggregate', '789c048edd754dce1e46c7cd8d8f6d4c5bcf6df96a51b62e74ead8ef0bb46861')
            ->assertExactJson(['ok' => true, ...$expected['payload']]);
    }

    public function test_runtime_index_binds_the_manifest_without_scanning_all_page_bodies(): void
    {
        $index = (new PersonalityCurrentAuthorityPackage)->runtimeIndex(base_path());

        self::assertCount(364, $index['entries']);
        self::assertArrayHasKey('mbti|comparison_at|intj-a-vs-intj-t|en', $index['entries']);
    }
}
