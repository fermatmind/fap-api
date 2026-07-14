<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\Ops\PublicContentRuntimeMetricsService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class CareerIndustryDirectoryApiTest extends TestCase
{
    public function test_it_returns_a_locale_keyed_industry_aggregate_from_the_active_directory_read_model(): void
    {
        $this->putDirectoryVersion('en', 'active-v2', $this->directoryPayload());

        $this->getJson('/api/v0.5/career/industries?locale=en-US')
            ->assertOk()
            ->assertJsonPath('authority_version', 'career.industry_directory.v1')
            ->assertJsonPath('bundle_kind', 'career_industry_directory')
            ->assertJsonPath('bundle_version', 'career.industry_directory.v1')
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('public_detail_indexable_count', 5)
            ->assertJsonPath('industry_count', 2)
            ->assertJsonCount(2, 'industries')
            ->assertJsonPath('industries.0.slug', 'business-finance')
            ->assertJsonPath('industries.0.title', 'Business and Finance')
            ->assertJsonPath('industries.0.count', 4)
            ->assertJsonPath('industries.0.public_detail_count', 4)
            ->assertJsonPath('industries.0.indexable_count', 4)
            ->assertJsonPath('industries.0.canonical_path', '/en/career/industries/business-finance')
            ->assertJsonCount(3, 'industries.0.discovery_jobs')
            ->assertJsonPath('industries.0.discovery_jobs.0.slug', 'accountants')
            ->assertJsonPath('industries.0.discovery_jobs.0.canonical_path', '/en/career/jobs/accountants')
            ->assertJsonPath('industries.1.slug', 'arts-media')
            ->assertJsonPath('industries.1.count', 1)
            ->assertJsonMissingPath('industries.0.discovery_jobs.0.search_text')
            ->assertJsonMissingPath('industries.0.discovery_jobs.0.truth_summary')
            ->assertJsonMissingPath('industries.0.discovery_jobs.0.score_summary')
            ->assertJsonMissingPath('industries.0.discovery_jobs.0.provenance_meta');
    }

    public function test_it_falls_back_to_the_last_known_good_version_and_localizes_titles_and_paths(): void
    {
        Cache::forever($this->pointerKey('zh-CN', 'active'), 'missing-active');
        $this->putDirectoryVersion('zh-CN', 'last-good-v1', $this->directoryPayload(), 'lkg');

        $this->getJson('/api/v0.5/career/industries?locale=zh')
            ->assertOk()
            ->assertJsonPath('locale', 'zh-CN')
            ->assertJsonPath('industries.0.title', '商业与金融')
            ->assertJsonPath('industries.0.canonical_path', '/zh/career/industries/business-finance')
            ->assertJsonPath('industries.0.discovery_jobs.0.title', '会计师')
            ->assertJsonPath('industries.0.discovery_jobs.0.canonical_path', '/zh/career/jobs/accountants');
    }

    public function test_it_returns_a_retryable_service_error_when_no_active_or_lkg_read_model_exists(): void
    {
        $this->getJson('/api/v0.5/career/industries?locale=en')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '60')
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'CAREER_INDUSTRY_DIRECTORY_UNAVAILABLE')
            ->assertJsonMissingPath('exception');
    }

    public function test_it_rejects_unknown_locales(): void
    {
        $this->getJson('/api/v0.5/career/industries?locale=fr')
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');
    }

    public function test_it_is_registered_in_the_public_runtime_observability_catalog(): void
    {
        $this->assertSame(
            ['family' => 'career_industries', 'priority' => 'L3'],
            app(PublicContentRuntimeMetricsService::class)->resolveRoute('api/v0.5/career/industries'),
        );
    }

    /** @return array<string, mixed> */
    private function directoryPayload(): array
    {
        return [
            'read_model_version' => 'career.directory.read-model.v1',
            'locale' => 'en',
            'public_count' => 5,
            'items' => [
                $this->directoryItem('analysts', 'Analysts', '分析师', 'business-finance', 'Business and Finance', '商业与金融'),
                $this->directoryItem('actors', 'Actors', '演员', 'arts-media', 'Arts and Media', '艺术与媒体'),
                $this->directoryItem('auditors', 'Auditors', '审计师', 'business-finance', 'Business and Finance', '商业与金融'),
                $this->directoryItem('accountants', 'Accountants', '会计师', 'business-finance', 'Business and Finance', '商业与金融'),
                $this->directoryItem('actuaries', 'Actuaries', '精算师', 'business-finance', 'Business and Finance', '商业与金融'),
                'malformed-item',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function directoryItem(
        string $slug,
        string $titleEn,
        string $titleZh,
        string $familySlug,
        string $familyTitleEn,
        string $familyTitleZh,
    ): array {
        return [
            'slug' => $slug,
            'title_en' => $titleEn,
            'title_zh' => $titleZh,
            'title' => $titleEn,
            'family' => [
                'slug' => $familySlug,
                'title_en' => $familyTitleEn,
                'title_zh' => $familyTitleZh,
            ],
            'canonical_path' => '/en/career/jobs/'.$slug,
            'indexable' => true,
            'detail_ready' => true,
            'search_text' => strtolower($titleEn),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function putDirectoryVersion(string $locale, string $version, array $payload, string $pointer = 'active'): void
    {
        Cache::forever($this->versionKey($locale, $version), $payload);
        Cache::forever($this->pointerKey($locale, $pointer), $version);
    }

    private function pointerKey(string $locale, string $pointer): string
    {
        return PublicCareerAuthorityResponseCache::DIRECTORY_VERSIONED_CACHE_KEY_PREFIX.':'.$locale.':'.$pointer;
    }

    private function versionKey(string $locale, string $version): string
    {
        return PublicCareerAuthorityResponseCache::DIRECTORY_VERSIONED_CACHE_KEY_PREFIX.':'.$locale.':versions:'.$version;
    }
}
