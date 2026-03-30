<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\DataPage;
use App\Models\DataPageSeoMeta;
use App\Models\MethodPage;
use App\Models\MethodPageSeoMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MethodDataPublicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_method_list_filters_visibility_locale_and_org_scope(): void
    {
        $visible = $this->createMethod([
            'method_code' => 'fermat-facet-matrix',
            'slug' => 'fermat-facet-matrix',
            'title' => 'Fermat Facet Matrix',
            'excerpt' => 'Defines the 30-facet interpretation model.',
            'status' => MethodPage::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinutes(10),
        ]);
        $this->createMethodSeoMeta($visible, [
            'seo_title' => 'Fermat Facet Matrix',
            'seo_description' => 'Method definition for the 30-facet matrix.',
        ]);

        $this->createMethod([
            'method_code' => 'fermat-facet-matrix',
            'slug' => 'fermat-facet-matrix',
            'locale' => 'zh-CN',
            'title' => '费马分面矩阵',
            'status' => MethodPage::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinutes(9),
        ]);
        $this->createMethod([
            'method_code' => 'draft-method',
            'slug' => 'draft-method',
            'title' => 'Draft Method',
            'status' => MethodPage::STATUS_DRAFT,
            'is_public' => true,
        ]);
        $this->createMethod([
            'method_code' => 'private-method',
            'slug' => 'private-method',
            'title' => 'Private Method',
            'status' => MethodPage::STATUS_PUBLISHED,
            'is_public' => false,
            'published_at' => now()->subMinutes(8),
        ]);
        $this->createMethod([
            'org_id' => 7,
            'method_code' => 'tenant-method',
            'slug' => 'tenant-method',
            'title' => 'Tenant Method',
            'status' => MethodPage::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinutes(7),
        ]);

        $this->getJson('/api/v0.5/methods?locale=en')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.slug', 'fermat-facet-matrix')
            ->assertJsonPath('items.0.method_code', 'fermat-facet-matrix')
            ->assertJsonPath('landing_surface_v1.entry_surface', 'method_index');

        $this->getJson('/api/v0.5/methods?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.title', '费马分面矩阵');

        $this->getJson('/api/v0.5/methods?locale=en&org_id=7')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.title', 'Tenant Method');
    }

    public function test_method_detail_and_seo_endpoints_return_contract_payloads(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $page = $this->createMethod([
            'method_code' => 'fermat-facet-matrix',
            'slug' => 'fermat-facet-matrix',
            'title' => 'Fermat Facet Matrix',
            'subtitle' => '30-facet interpretation model',
            'excerpt' => 'Defines the 30-facet interpretation model.',
            'body_md' => '# Method body',
            'definition_summary_md' => 'Definition summary text.',
            'boundary_notes_md' => 'Boundary notes text.',
            'status' => MethodPage::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createMethodSeoMeta($page, [
            'seo_title' => 'Fermat Facet Matrix | FermatMind',
            'seo_description' => 'Method definition for the 30-facet matrix.',
            'robots' => 'index,follow',
        ]);

        $this->getJson('/api/v0.5/methods/fermat-facet-matrix?locale=en')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('page.slug', 'fermat-facet-matrix')
            ->assertJsonPath('page.definition_summary_md', 'Definition summary text.')
            ->assertJsonPath('page.boundary_notes_md', 'Boundary notes text.')
            ->assertJsonPath('landing_surface_v1.entry_surface', 'method_detail')
            ->assertJsonPath('answer_surface_v1.surface_type', 'method_detail')
            ->assertJsonPath('seo_surface_v1.surface_type', 'method_public_detail')
            ->assertJsonPath('seo_meta.seo_title', 'Fermat Facet Matrix | FermatMind');

        $this->getJson('/api/v0.5/methods/fermat-facet-matrix/seo?locale=en')
            ->assertOk()
            ->assertJsonPath('meta.canonical', 'https://staging.fermatmind.com/en/methods/fermat-facet-matrix')
            ->assertJsonPath('meta.robots', 'index,follow')
            ->assertJsonPath('jsonld.@type', 'Article')
            ->assertJsonPath('seo_surface_v1.surface_type', 'method_public_detail');
    }

    public function test_data_list_filters_visibility_locale_and_org_scope(): void
    {
        $visible = $this->createDataPage([
            'data_code' => 'china-youth-career-report-2026',
            'slug' => 'china-youth-career-report-2026',
            'title' => 'China Youth Career Report 2026',
            'excerpt' => 'Aggregated trends across the 2026 youth sample.',
            'sample_size_label' => 'n=12,480',
            'time_window_label' => '2025-01 to 2026-02',
            'status' => DataPage::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinutes(10),
        ]);
        $this->createDataSeoMeta($visible, [
            'seo_title' => 'China Youth Career Report 2026',
            'seo_description' => 'Aggregated sample report.',
        ]);

        $this->createDataPage([
            'data_code' => 'china-youth-career-report-2026',
            'slug' => 'china-youth-career-report-2026',
            'locale' => 'zh-CN',
            'title' => '中国青年职业报告 2026',
            'status' => DataPage::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinutes(9),
        ]);
        $this->createDataPage([
            'data_code' => 'draft-data',
            'slug' => 'draft-data',
            'title' => 'Draft Data',
            'status' => DataPage::STATUS_DRAFT,
            'is_public' => true,
        ]);
        $this->createDataPage([
            'data_code' => 'private-data',
            'slug' => 'private-data',
            'title' => 'Private Data',
            'status' => DataPage::STATUS_PUBLISHED,
            'is_public' => false,
            'published_at' => now()->subMinutes(8),
        ]);
        $this->createDataPage([
            'org_id' => 7,
            'data_code' => 'tenant-data',
            'slug' => 'tenant-data',
            'title' => 'Tenant Data',
            'status' => DataPage::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinutes(7),
        ]);

        $this->getJson('/api/v0.5/data?locale=en')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.slug', 'china-youth-career-report-2026')
            ->assertJsonPath('items.0.sample_size_label', 'n=12,480')
            ->assertJsonPath('landing_surface_v1.entry_surface', 'data_index');

        $this->getJson('/api/v0.5/data?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.title', '中国青年职业报告 2026');

        $this->getJson('/api/v0.5/data?locale=en&org_id=7')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.title', 'Tenant Data');
    }

    public function test_data_detail_and_seo_endpoints_return_contract_payloads(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $page = $this->createDataPage([
            'data_code' => 'china-youth-career-report-2026',
            'slug' => 'china-youth-career-report-2026',
            'title' => 'China Youth Career Report 2026',
            'subtitle' => 'Anonymous aggregate sample report',
            'excerpt' => 'Aggregated trends across the 2026 youth sample.',
            'summary_statement_md' => 'Core finding summary.',
            'body_md' => '# Data body',
            'sample_size_label' => 'n=12,480',
            'time_window_label' => '2025-01 to 2026-02',
            'methodology_md' => 'Grouped by normalized profile clusters.',
            'limitations_md' => 'Group-level trend only.',
            'status' => DataPage::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createDataSeoMeta($page, [
            'seo_title' => 'China Youth Career Report 2026 | FermatMind',
            'seo_description' => 'Aggregated sample report.',
            'robots' => 'index,follow',
        ]);

        $this->getJson('/api/v0.5/data/china-youth-career-report-2026?locale=en')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('page.slug', 'china-youth-career-report-2026')
            ->assertJsonPath('page.summary_statement_md', 'Core finding summary.')
            ->assertJsonPath('page.sample_size_label', 'n=12,480')
            ->assertJsonPath('page.time_window_label', '2025-01 to 2026-02')
            ->assertJsonPath('page.methodology_md', 'Grouped by normalized profile clusters.')
            ->assertJsonPath('page.limitations_md', 'Group-level trend only.')
            ->assertJsonPath('landing_surface_v1.entry_surface', 'data_detail')
            ->assertJsonPath('answer_surface_v1.surface_type', 'data_detail')
            ->assertJsonPath('seo_surface_v1.surface_type', 'data_public_detail')
            ->assertJsonPath('seo_meta.seo_title', 'China Youth Career Report 2026 | FermatMind');

        $this->getJson('/api/v0.5/data/china-youth-career-report-2026/seo?locale=en')
            ->assertOk()
            ->assertJsonPath('meta.canonical', 'https://staging.fermatmind.com/en/data/china-youth-career-report-2026')
            ->assertJsonPath('meta.robots', 'index,follow')
            ->assertJsonPath('jsonld.@type', 'Article')
            ->assertJsonPath('seo_surface_v1.surface_type', 'data_public_detail');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createMethod(array $overrides = []): MethodPage
    {
        return MethodPage::query()->create(array_merge([
            'org_id' => 0,
            'method_code' => 'default-method',
            'slug' => 'default-method',
            'locale' => 'en',
            'title' => 'Default Method',
            'subtitle' => null,
            'excerpt' => 'Default method excerpt.',
            'hero_kicker' => null,
            'body_md' => 'Method body',
            'body_html' => null,
            'definition_summary_md' => 'Default definition summary.',
            'boundary_notes_md' => 'Default boundary notes.',
            'cover_image_url' => null,
            'status' => MethodPage::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => true,
            'published_at' => null,
            'scheduled_at' => null,
            'schema_version' => 'v1',
            'sort_order' => 0,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createMethodSeoMeta(MethodPage $page, array $overrides = []): MethodPageSeoMeta
    {
        return MethodPageSeoMeta::query()->create(array_merge([
            'method_page_id' => (int) $page->id,
            'seo_title' => 'Method SEO Title',
            'seo_description' => 'Method SEO description.',
            'canonical_url' => null,
            'og_title' => 'Method OG Title',
            'og_description' => 'Method OG Description',
            'og_image_url' => 'https://cdn.fermatmind.test/method-og.png',
            'twitter_title' => 'Method Twitter Title',
            'twitter_description' => 'Method Twitter Description',
            'twitter_image_url' => 'https://cdn.fermatmind.test/method-twitter.png',
            'robots' => 'index,follow',
            'jsonld_overrides_json' => ['keywords' => ['method']],
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createDataPage(array $overrides = []): DataPage
    {
        return DataPage::query()->create(array_merge([
            'org_id' => 0,
            'data_code' => 'default-data',
            'slug' => 'default-data',
            'locale' => 'en',
            'title' => 'Default Data',
            'subtitle' => null,
            'excerpt' => 'Default data excerpt.',
            'hero_kicker' => null,
            'body_md' => 'Data body',
            'body_html' => null,
            'sample_size_label' => 'n=1,000',
            'time_window_label' => '2025-01 to 2025-12',
            'methodology_md' => 'Default methodology.',
            'limitations_md' => 'Default limitations.',
            'summary_statement_md' => 'Default summary statement.',
            'cover_image_url' => null,
            'status' => DataPage::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => true,
            'published_at' => null,
            'scheduled_at' => null,
            'schema_version' => 'v1',
            'sort_order' => 0,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createDataSeoMeta(DataPage $page, array $overrides = []): DataPageSeoMeta
    {
        return DataPageSeoMeta::query()->create(array_merge([
            'data_page_id' => (int) $page->id,
            'seo_title' => 'Data SEO Title',
            'seo_description' => 'Data SEO description.',
            'canonical_url' => null,
            'og_title' => 'Data OG Title',
            'og_description' => 'Data OG Description',
            'og_image_url' => 'https://cdn.fermatmind.test/data-og.png',
            'twitter_title' => 'Data Twitter Title',
            'twitter_description' => 'Data Twitter Description',
            'twitter_image_url' => 'https://cdn.fermatmind.test/data-twitter.png',
            'robots' => 'index,follow',
            'jsonld_overrides_json' => ['keywords' => ['data']],
        ], $overrides));
    }
}
