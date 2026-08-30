<?php

declare(strict_types=1);

namespace Tests\Feature\Personality;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PersonalityCurrentRemainingRuntimeTest extends TestCase
{
    private const AGGREGATE = '789c048edd754dce1e46c7cd8d8f6d4c5bcf6df96a51b62e74ead8ef0bb46861';

    #[DataProvider('detailCases')]
    public function test_public_detail_is_served_from_its_per_page_authority(string $url, string $file): void
    {
        $expected = json_decode(file_get_contents(base_path($file)), true, 512, JSON_THROW_ON_ERROR);

        $this->getJson($url)
            ->assertOk()
            ->assertHeader('X-Fermat-Content-Authority', 'personality.page.content.v1')
            ->assertHeader('X-Fermat-Content-Aggregate', self::AGGREGATE)
            ->assertExactJson(['ok' => true, ...$expected['payload']]);
    }

    /** @return iterable<string,array{string,string}> */
    public static function detailCases(): iterable
    {
        yield 'cross type' => [
            '/api/v0.5/personality/comparisons/intj-vs-intp?locale=en&org_id=0&scale_code=MBTI',
            'content_assets/personality_public/current/pages/mbti/comparison-cross/intj-vs-intp/en.json',
        ];
        yield 'MBTI profile' => [
            '/api/v0.5/personality/intj?locale=zh-CN&org_id=0&scale_code=MBTI',
            'content_assets/personality_public/current/pages/mbti/profile/intj/zh-CN.json',
        ];
        yield 'MBTI variant' => [
            '/api/v0.5/personality/intj-a?locale=en&org_id=0&scale_code=MBTI',
            'content_assets/personality_public/current/pages/mbti/variant/intj-a/en.json',
        ];
        yield 'Big Five' => [
            '/api/v0.5/personality-content-assets/big_five/domain/openness?locale=zh-CN&org_id=0',
            'content_assets/personality_public/current/pages/big-five/domain/openness/zh-CN.json',
        ];
        yield 'Enneagram' => [
            '/api/v0.5/personality-content-assets/enneagram/wing/5w4?locale=en&org_id=0',
            'content_assets/personality_public/current/pages/enneagram/wing/5w4/en.json',
        ];
    }

    public function test_enneagram_subtype_index_projects_per_page_files(): void
    {
        $response = $this->getJson(
            '/api/v0.5/personality-content-assets?locale=zh-CN&framework=enneagram&entity_type=instinctual_subtype&per_page=100&org_id=0',
        );

        $response->assertOk()
            ->assertHeader('X-Fermat-Content-Authority', 'personality.page.content.v1')
            ->assertJsonPath('pagination.total', 27)
            ->assertJsonCount(27, 'items');
    }

    public function test_mbti_indexes_are_projected_without_database_identity(): void
    {
        $this->getJson('/api/v0.5/personality?locale=zh-CN&org_id=0&scale_code=MBTI&per_page=100')
            ->assertOk()
            ->assertHeader('X-Fermat-Content-Authority', 'personality.page.content.v1')
            ->assertHeader('X-Fermat-Content-Aggregate', self::AGGREGATE)
            ->assertJsonPath('pagination.total', 16)
            ->assertJsonPath('items.0.slug', 'intj')
            ->assertJsonMissingPath('items.0.id');

        $this->getJson('/api/v0.5/personality?locale=en&org_id=0&scale_code=MBTI&per_page=100&include_variants=1')
            ->assertOk()
            ->assertHeader('X-Fermat-Content-Authority', 'personality.page.content.v1')
            ->assertJsonPath('pagination.total', 32)
            ->assertJsonPath('items.0.public_route_slug', 'intj-a')
            ->assertJsonPath('items.1.public_route_slug', 'intj-t')
            ->assertJsonMissingPath('items.0.variant_id');
    }

    public function test_mbti_comparison_index_is_projected_from_per_page_files(): void
    {
        $this->getJson('/api/v0.5/personality/comparisons?locale=zh-CN&org_id=0&scale_code=MBTI')
            ->assertOk()
            ->assertHeader('X-Fermat-Content-Authority', 'personality.page.content.v1')
            ->assertHeader('X-Fermat-Content-Aggregate', self::AGGREGATE)
            ->assertJsonCount(16, 'at_comparisons')
            ->assertJsonCount(7, 'cross_type_comparisons')
            ->assertJsonPath('at_comparisons.0.slug', 'intj-a-vs-intj-t')
            ->assertJsonPath('cross_type_comparisons.0.slug', 'enfp-vs-entp');
    }

    public function test_big_five_and_enneagram_hubs_are_current_pages(): void
    {
        $this->getJson('/api/v0.5/personality-content-assets/big_five/hub/big-five?locale=zh-CN&org_id=0')
            ->assertOk()
            ->assertHeader('X-Fermat-Content-Authority', 'personality.page.content.v1');

        $this->getJson('/api/v0.5/personality-content-assets/enneagram/hub/enneagram?locale=en&org_id=0')
            ->assertOk()
            ->assertHeader('X-Fermat-Content-Authority', 'personality.page.content.v1');
    }

    public function test_missing_public_canonical_identity_does_not_fall_back_to_database(): void
    {
        $this->getJson('/api/v0.5/personality/zzzz?locale=en&org_id=0&scale_code=MBTI')
            ->assertNotFound()
            ->assertHeader('X-Fermat-Content-Authority', 'personality.page.content.v1');

        $this->getJson('/api/v0.5/personality-content-assets/big_five/domain/not-real?locale=en&org_id=0')
            ->assertNotFound()
            ->assertHeader('X-Fermat-Content-Authority', 'personality.page.content.v1');
    }
}
