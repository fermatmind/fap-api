<?php

declare(strict_types=1);

namespace Tests\Feature\Personality;

use App\Domain\Personality\Current\PersonalityCurrentPageReader;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PersonalityCurrentSeoRuntimeTest extends TestCase
{
    private const AGGREGATE = '31d6dce9f57621dbb3882e311c685bbde9498aec78082a3355ebc6e1a0307500';

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

    public function test_base_and_variant_keep_distinct_canonicals_and_visible_titles(): void
    {
        foreach (['intj', 'intj-a'] as $slug) {
            $kind = $slug === 'intj' ? 'profile' : 'variant';
            $payload = app(PersonalityCurrentPageReader::class)->payload('mbti', $kind, $slug, 'en');
            $this->getJson("/api/v0.5/personality/{$slug}/seo?locale=en&org_id=0&scale_code=MBTI")
                ->assertOk()
                ->assertJsonPath('meta.canonical', "https://fermatmind.com/en/personality/{$slug}")
                ->assertJsonPath('meta.alternates.zh-CN', "https://fermatmind.com/zh/personality/{$slug}")
                ->assertJsonPath('jsonld.name', $payload['profile']['title'])
                ->assertJsonPath('jsonld.mainEntityOfPage', "https://fermatmind.com/en/personality/{$slug}");
        }
    }

    public function test_missing_current_seo_identity_fails_closed(): void
    {
        $this->getJson('/api/v0.5/personality/zzzz/seo?locale=en&org_id=0&scale_code=MBTI')
            ->assertNotFound()
            ->assertHeader('X-Fermat-Content-Authority', 'personality.page.content.v1')
            ->assertHeader('X-Fermat-Content-Aggregate', self::AGGREGATE);
    }
}
