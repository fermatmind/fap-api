<?php

declare(strict_types=1);

namespace Tests\Feature\Personality;

use App\Domain\Personality\Current\PersonalityCurrentPageReader;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PersonalityCurrentSeoRuntimeTest extends TestCase
{
    private const AGGREGATE = '789c048edd754dce1e46c7cd8d8f6d4c5bcf6df96a51b62e74ead8ef0bb46861';

    #[DataProvider('mbtiIdentityProvider')]
    public function test_all_mbti_seo_endpoints_project_their_per_page_authority(
        string $type,
        string $locale,
        string $pageKind,
        string $entityKey,
    ): void {
        $payload = app(PersonalityCurrentPageReader::class)->payload(
            'mbti',
            $pageKind,
            $entityKey,
            $locale,
        );
        $expectedSurface = $payload['seo_surface_v1'];

        $response = $this->getJson(
            "/api/v0.5/personality/{$type}/seo?locale={$locale}&org_id=0&scale_code=MBTI"
        );

        $response->assertOk()
            ->assertHeader('X-Fermat-Content-Authority', 'personality.page.content.v1')
            ->assertHeader('X-Fermat-Content-Aggregate', self::AGGREGATE)
            ->assertJsonPath('meta.title', $expectedSurface['title'])
            ->assertJsonPath('meta.description', $expectedSurface['description'])
            ->assertJsonPath('meta.canonical', $expectedSurface['canonical_url'])
            ->assertJsonPath('meta.alternates', $expectedSurface['alternates'])
            ->assertJsonPath('meta.robots', $expectedSurface['robots_policy'])
            ->assertJsonPath('jsonld.@type', 'AboutPage')
            ->assertJsonPath('jsonld.about.@type', 'DefinedTerm')
            ->assertJsonPath('jsonld.mainEntityOfPage', $expectedSurface['canonical_url'])
            ->assertJsonPath('seo_surface_v1', $expectedSurface);
    }

    /** @return iterable<string,array{string,string,string,string}> */
    public static function mbtiIdentityProvider(): iterable
    {
        $manifest = json_decode(
            file_get_contents(dirname(__DIR__, 4).'/backend/content_assets/personality_public/current/manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        foreach ($manifest['files'] as $entry) {
            if (($entry['framework'] ?? null) !== 'mbti'
                || ! in_array($entry['page_kind'] ?? null, ['profile', 'variant'], true)) {
                continue;
            }
            $type = (string) $entry['entity_key'];
            $locale = (string) $entry['locale'];
            $pageKind = (string) $entry['page_kind'];

            yield "{$type} {$locale}" => [$type, $locale, $pageKind, $type];
        }
    }

    public function test_current_seo_projection_matches_the_frozen_base_and_variant_contracts(): void
    {
        $this->getJson('/api/v0.5/personality/intj/seo?locale=en&org_id=0&scale_code=MBTI')
            ->assertOk()
            ->assertExactJson([
                'meta' => [
                    'title' => 'INTJ Personality Guide',
                    'description' => 'Discover strengths, weaknesses, relationship patterns, and career direction for the INTJ personality type.',
                    'canonical' => 'https://fermatmind.com/en/personality/intj-a',
                    'alternates' => [
                        'en' => 'https://fermatmind.com/en/personality/intj-a',
                        'zh-CN' => 'https://fermatmind.com/zh/personality/intj-a',
                    ],
                    'og' => [
                        'title' => 'INTJ Personality Guide',
                        'description' => 'Discover strengths, weaknesses, relationship patterns, and career direction for the INTJ personality type.',
                        'image' => null,
                        'type' => 'article',
                    ],
                    'twitter' => [
                        'card' => 'summary_large_image',
                        'title' => 'INTJ Personality Guide',
                        'description' => 'Discover strengths, weaknesses, relationship patterns, and career direction for the INTJ personality type.',
                        'image' => null,
                    ],
                    'robots' => 'index,follow',
                ],
                'jsonld' => [
                    '@context' => 'https://schema.org',
                    '@type' => 'AboutPage',
                    'name' => 'INTJ Personality Guide',
                    'description' => 'Discover strengths, weaknesses, relationship patterns, and career direction for the INTJ personality type.',
                    'about' => [
                        '@type' => 'DefinedTerm',
                        'name' => 'INTJ',
                        'inDefinedTermSet' => 'MBTI',
                    ],
                    'mainEntityOfPage' => 'https://fermatmind.com/en/personality/intj-a',
                ],
                'seo_surface_v1' => json_decode(
                    file_get_contents(base_path('content_assets/personality_public/current/pages/mbti/profile/intj/en.json')),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                )['payload']['seo_surface_v1'],
            ]);

        $this->getJson('/api/v0.5/personality/intj-a/seo?locale=en&org_id=0&scale_code=MBTI')
            ->assertOk()
            ->assertJsonPath('jsonld.name', 'INTJ-A Personality')
            ->assertJsonPath('jsonld.url', '/en/personality/intj-a')
            ->assertJsonPath('jsonld.mainEntityOfPage', 'https://fermatmind.com/en/personality/intj-a');
    }

    public function test_missing_current_seo_identity_fails_closed(): void
    {
        $this->getJson('/api/v0.5/personality/zzzz/seo?locale=en&org_id=0&scale_code=MBTI')
            ->assertNotFound()
            ->assertHeader('X-Fermat-Content-Authority', 'personality.page.content.v1')
            ->assertHeader('X-Fermat-Content-Aggregate', self::AGGREGATE);
    }
}
