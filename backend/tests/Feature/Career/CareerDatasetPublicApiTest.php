<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class CareerDatasetPublicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_public_dataset_hub_contract_with_dataset_structured_data(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $response = $this->getJson('/api/v0.5/career/datasets/occupations')
            ->assertOk()
            ->assertJsonPath('contract_kind', 'career_public_dataset_hub')
            ->assertJsonPath('contract_version', 'career.dataset_public_contract.v1')
            ->assertJsonPath('dataset_key', 'career_all_342_occupations_dataset')
            ->assertJsonPath('dataset_scope', 'career_all_342')
            ->assertJsonPath('publication.publisher.name', 'FermatMind')
            ->assertJsonPath('publication.license.name', 'Proprietary Dataset License')
            ->assertJsonPath('publication.usage.allowed_for_public_display', true)
            ->assertJsonPath('publication.usage.allowed_for_download', true)
            ->assertJsonPath('publication.distribution.download_url', 'https://www.fermatmind.com/datasets/occupations/download')
            ->assertJsonPath('method_url', 'https://www.fermatmind.com/datasets/occupations/method')
            ->assertJsonPath('structured_data.dataset.@type', 'Dataset')
            ->assertJsonPath('structured_data.breadcrumb_list.@type', 'BreadcrumbList')
            ->assertJsonMissingPath('structured_data.article');

        $this->assertSame(
            $response->json('collection_summary.member_count'),
            $response->json('collection_summary.tracking_counts.tracked_total_occupations')
        );
        $this->assertSame(
            $response->json('collection_summary.member_count'),
            count($response->json('members', []))
        );
        $this->assertGreaterThan(0, (int) $response->json('collection_summary.included_count'));
        $this->assertGreaterThanOrEqual(0, (int) $response->json('collection_summary.excluded_count'));

        $this->assertTrue(Cache::has(PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY));
    }

    public function test_it_returns_public_dataset_method_contract_with_article_structured_data(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $response = $this->getJson('/api/v0.5/career/datasets/occupations/method')
            ->assertOk()
            ->assertJsonPath('contract_kind', 'career_public_dataset_method')
            ->assertJsonPath('contract_version', 'career.dataset_public_method.v1')
            ->assertJsonPath('dataset_key', 'career_all_342_occupations_dataset')
            ->assertJsonPath('dataset_scope', 'career_all_342')
            ->assertJsonPath('method_url', 'https://www.fermatmind.com/datasets/occupations/method')
            ->assertJsonPath('hub_url', 'https://www.fermatmind.com/datasets/occupations')
            ->assertJsonPath('structured_data.article.@type', 'Article')
            ->assertJsonPath('structured_data.breadcrumb_list.@type', 'BreadcrumbList')
            ->assertJsonMissingPath('structured_data.dataset');

        $this->assertGreaterThan(0, (int) $response->json('scope_summary.included_count'));
        $this->assertGreaterThanOrEqual(0, (int) $response->json('scope_summary.excluded_count'));

        $this->assertTrue(Cache::has(PublicCareerAuthorityResponseCache::DATASET_METHOD_CACHE_KEY));
    }

    public function test_it_can_serve_current_live_public_truth_from_cached_dataset_authority(): void
    {
        Cache::put(PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY, [
            'contract_kind' => 'career_public_dataset_hub',
            'contract_version' => 'career.dataset_public_contract.v1',
            'dataset_key' => 'career_all_342_occupations_dataset',
            'dataset_scope' => 'career_all_342',
            'collection_summary' => [
                'member_count' => 1046,
                'included_count' => 1046,
                'excluded_count' => 1741,
                'public_detail_indexable_count' => 1046,
                'release_cohort_counts' => [
                    'public_detail_indexable' => 1046,
                ],
            ],
            'members' => [],
        ]);

        $this->getJson('/api/v0.5/career/datasets/occupations')
            ->assertOk()
            ->assertJsonPath('dataset_key', 'career_all_342_occupations_dataset')
            ->assertJsonPath('collection_summary.member_count', 1046)
            ->assertJsonPath('collection_summary.included_count', 1046)
            ->assertJsonPath('collection_summary.excluded_count', 1741)
            ->assertJsonPath('collection_summary.public_detail_indexable_count', 1046)
            ->assertJsonPath('collection_summary.release_cohort_counts.public_detail_indexable', 1046);
    }

    private function materializeCurrentFirstWaveFixture(): void
    {
        Cache::forget(PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY);
        Cache::forget(PublicCareerAuthorityResponseCache::DATASET_METHOD_CACHE_KEY);

        $exitCode = Artisan::call('career:validate-first-wave-publish-ready', [
            '--source' => base_path('tests/Fixtures/Career/authority_wave/first_wave_readiness_summary_subset.csv'),
            '--materialize-missing' => true,
            '--compile-missing' => true,
            '--repair-safe-partials' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }
}
