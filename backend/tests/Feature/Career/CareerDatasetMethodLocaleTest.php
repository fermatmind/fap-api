<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Support\PublicProjectionCache;
use Tests\TestCase;

final class CareerDatasetMethodLocaleTest extends TestCase
{
    public function test_chinese_method_copy_and_structured_data_are_localized_without_mutating_authority_or_cache(): void
    {
        $payload = $this->cachedMethod();
        foreach (['zh', 'zh-CN'] as $locale) {
            $response = $this->getJson('/api/v0.5/career/datasets/occupations/method?locale='.$locale)
                ->assertOk()
                ->assertJsonPath('title', '职业数据库方法说明')
                ->assertJsonPath('structured_data.article.headline', '职业数据库方法说明');
            foreach (['dataset_key', 'dataset_scope', 'scope_summary', 'publication', 'method_url', 'hub_url'] as $field) {
                $this->assertSame($payload[$field], $response->json($field));
            }
            $this->assertSame($response->json('summary'), $response->json('structured_data.article.description'));
            $this->assertStringNotContainsString('release-ledger', json_encode($response->json('review_discipline_summary')));
            $this->assertSame($payload, PublicProjectionCache::get(PublicCareerAuthorityResponseCache::DATASET_METHOD_CACHE_KEY));
        }
        $this->getJson('/api/v0.5/career/datasets/occupations/method?locale=en')
            ->assertOk()->assertExactJson($payload);
    }

    public function test_default_unknown_and_non_scalar_locales_preserve_the_existing_contract(): void
    {
        $payload = $this->cachedMethod();
        foreach (['', '?locale=en', '?locale=fr', '?locale[]=zh'] as $query) {
            $this->getJson('/api/v0.5/career/datasets/occupations/method'.$query)
                ->assertOk()->assertExactJson($payload);
        }
    }

    private function cachedMethod(): array
    {
        $payload = [
            'contract_kind' => 'career_public_dataset_method',
            'contract_version' => 'career.dataset_public_method.v1',
            'dataset_key' => 'career_all_342_occupations_dataset',
            'dataset_scope' => 'career_all_342',
            'title' => 'Occupations dataset method',
            'summary' => 'Method summary fixture.',
            'source_summary' => 'Source summary fixture.',
            'review_discipline_summary' => 'Review summary fixture.',
            'included' => ['Included fixture.'],
            'excluded' => ['Excluded fixture.'],
            'boundary_notes' => ['Boundary fixture.'],
            'method_url' => 'https://fermatmind.com/en/datasets/occupations/method',
            'hub_url' => 'https://fermatmind.com/en/datasets/occupations',
            'scope_summary' => ['member_count' => 1045, 'included_count' => 3, 'excluded_count' => 1042],
            'publication' => ['publisher' => ['name' => 'FermatMind']],
            'structured_data' => ['article' => ['headline' => 'Occupations dataset method']],
        ];
        PublicProjectionCache::forever(PublicCareerAuthorityResponseCache::DATASET_METHOD_CACHE_KEY, $payload);

        return $payload;
    }
}
