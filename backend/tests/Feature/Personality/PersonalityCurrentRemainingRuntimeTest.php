<?php

declare(strict_types=1);

namespace Tests\Feature\Personality;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PersonalityCurrentRemainingRuntimeTest extends TestCase
{
    private const AGGREGATE = 'd5d5b65221dde8d5926c143f88e93cefb7312f6e154684b5ad1f1a16a25b3744';

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
        yield 'ISFP English profile' => [
            '/api/v0.5/personality/isfp?locale=en&org_id=0&scale_code=MBTI',
            'content_assets/personality_public/current/pages/mbti/profile/isfp/en.json',
        ];
        foreach (['isfp', 'esfp', 'estj', 'intj', 'estp', 'intp', 'esfj', 'entp', 'infj', 'enfp', 'istp', 'infp'] as $type) {
            yield "{$type} Chinese profile" => [
                "/api/v0.5/personality/{$type}?locale=zh-CN&org_id=0&scale_code=MBTI",
                "content_assets/personality_public/current/pages/mbti/profile/{$type}/zh-CN.json",
            ];
        }
        yield 'INFJ English A/T comparison' => [
            '/api/v0.5/personality/comparisons/infj-a-vs-infj-t?locale=en&org_id=0&scale_code=MBTI',
            'content_assets/personality_public/current/pages/mbti/comparison-at/infj-a-vs-infj-t/en.json',
        ];
        foreach (['gut', 'head', 'heart'] as $center) {
            foreach (['en', 'zh-CN'] as $locale) {
                yield "Enneagram {$center} center {$locale}" => [
                    "/api/v0.5/personality-content-assets/enneagram/center/{$center}?locale={$locale}&org_id=0",
                    "content_assets/personality_public/current/pages/enneagram/center/{$center}/{$locale}.json",
                ];
            }
        }
        yield 'Enneagram Type 1 English' => [
            '/api/v0.5/personality-content-assets/enneagram/core_type/type-1?locale=en&org_id=0',
            'content_assets/personality_public/current/pages/enneagram/core-type/type-1/en.json',
        ];
        foreach (range(2, 9) as $number) {
            yield "Enneagram Type {$number} English" => [
                "/api/v0.5/personality-content-assets/enneagram/core_type/type-{$number}?locale=en&org_id=0",
                "content_assets/personality_public/current/pages/enneagram/core-type/type-{$number}/en.json",
            ];
        }
        foreach (range(1, 9) as $number) {
            yield "Enneagram Type {$number} Chinese" => [
                "/api/v0.5/personality-content-assets/enneagram/core_type/type-{$number}?locale=zh-CN&org_id=0",
                "content_assets/personality_public/current/pages/enneagram/core-type/type-{$number}/zh-CN.json",
            ];
        }
        foreach (['1w2', '1w9', '2w1', '2w3', '3w2', '6w7', '7w6'] as $wing) {
            yield "Enneagram remaining {$wing} Chinese" => [
                "/api/v0.5/personality-content-assets/enneagram/wing/{$wing}?locale=zh-CN&org_id=0",
                "content_assets/personality_public/current/pages/enneagram/wing/{$wing}/zh-CN.json",
            ];
        }
        foreach (['1w2', '1w9', '2w1', '2w3', '3w2', '3w4', '4w3', '4w5', '5w6', '6w5', '6w7', '7w6', '7w8', '8w7', '8w9', '9w1', '9w8'] as $wing) {
            yield "Enneagram {$wing} English" => [
                "/api/v0.5/personality-content-assets/enneagram/wing/{$wing}?locale=en&org_id=0",
                "content_assets/personality_public/current/pages/enneagram/wing/{$wing}/en.json",
            ];
        }
        foreach (['type-1/one-to-one', 'type-1/self-preservation', 'type-1/social', 'type-2/one-to-one', 'type-2/self-preservation', 'type-2/social', 'type-3/one-to-one', 'type-3/self-preservation', 'type-3/social', 'type-4/one-to-one', 'type-4/self-preservation', 'type-4/social', 'type-5/one-to-one', 'type-5/self-preservation', 'type-5/social', 'type-6/one-to-one', 'type-6/self-preservation', 'type-6/social', 'type-7/one-to-one', 'type-7/self-preservation', 'type-7/social', 'type-8/one-to-one', 'type-8/self-preservation', 'type-8/social', 'type-9/one-to-one'] as $subtype) {
            [$type, $instinct] = explode('/', $subtype);
            $code = rawurlencode($subtype);
            foreach (['en', 'zh-CN'] as $locale) {
                $file = "content_assets/personality_public/current/pages/enneagram/instinctual-subtype/{$type}--{$instinct}/{$locale}.json";
                yield "Enneagram subtype {$subtype} query {$locale}" => [
                    "/api/v0.5/personality-content-assets?framework=enneagram&entity_type=instinctual_subtype&code={$code}&locale={$locale}&org_id=0",
                    $file,
                ];
                yield "Enneagram subtype {$subtype} slug {$locale}" => [
                    "/api/v0.5/personality-content-assets/enneagram/enneagram/{$type}/instincts/{$instinct}?locale={$locale}&org_id=0",
                    $file,
                ];
            }
        }
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
        foreach (['3w4', '4w3', '4w5', '5w4', '5w6', '6w5', '7w8', '8w7', '8w9', '9w1', '9w8'] as $wing) {
            yield "Enneagram {$wing} Chinese" => [
                "/api/v0.5/personality-content-assets/enneagram/wing/{$wing}?locale=zh-CN&org_id=0",
                "content_assets/personality_public/current/pages/enneagram/wing/{$wing}/zh-CN.json",
            ];
        }
    }

    #[DataProvider('neutralCareerCases')]
    public function test_chinese_profiles_do_not_publish_unsupported_job_or_strength_claims(string $type): void
    {
        $response = $this->getJson("/api/v0.5/personality/{$type}?locale=zh-CN&org_id=0&scale_code=MBTI");
        $response->assertOk()->assertJsonPath('profile.slug', $type);

        foreach (['mbti_public_projection_v1', 'personality_public_projection_v1'] as $projection) {
            $sections = array_column($response->json("{$projection}.sections"), null, 'key');
            $this->assertArrayNotHasKey('career.preferred_roles', $sections);
            $this->assertArrayNotHasKey('growth.strengths', $sections);
            $this->assertNotEmpty($sections['work_style']['body_md']);
            $this->assertStringContainsString('不能仅凭类型', $sections['sources_and_method']['body_md']);
            $this->assertSame([], $response->json("{$projection}.dimensions"));
            $this->assertNull($response->json("{$projection}.profile.rarity"));
        }
    }

    /** @return iterable<string,array{string}> */
    public static function neutralCareerCases(): iterable
    {
        foreach (['isfp', 'esfp', 'estj', 'intj', 'estp', 'intp', 'esfj', 'entp', 'infj', 'enfp', 'istp', 'infp'] as $type) {
            yield $type => [$type];
        }
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
            ->assertJsonPath('sections.0.section_key', 'quick_answer')
            ->assertJsonPath('sections.4.section_key', 'sources_and_method')
            ->assertJsonPath('seo_meta.canonical_url', 'https://fermatmind.com/zh/personality')
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

        $this->getJson('/api/v0.5/personality-content-assets/enneagram/center/not-real?locale=zh-CN&org_id=0')
            ->assertNotFound()
            ->assertHeader('X-Fermat-Content-Authority', 'personality.page.content.v1');

        $this->getJson('/api/v0.5/personality-content-assets?framework=enneagram&entity_type=instinctual_subtype&code=type-1%2Fnot-real&locale=en&org_id=0')
            ->assertNotFound()
            ->assertHeader('X-Fermat-Content-Authority', 'personality.page.content.v1');

        $this->getJson('/api/v0.5/personality-content-assets/enneagram/enneagram/type-1/instincts/not-real?locale=zh-CN&org_id=0')
            ->assertNotFound()
            ->assertHeader('X-Fermat-Content-Authority', 'personality.page.content.v1');
    }
}
