<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\ContentGrowthAttributionPage;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\CareerGuide;
use App\Models\CareerGuideSeoMeta;
use App\Models\CareerJob;
use App\Models\CareerJobSeoMeta;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class ContentGrowthAttributionPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('app.frontend_url', 'https://frontend.example.test');
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_content_growth_attribution_page_requires_org_selection(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
        ]);

        $this->withSession([
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/content-growth-attribution')
            ->assertRedirectContains('/ops/select-org');
    }

    public function test_content_growth_attribution_page_renders_growth_matrix_for_selected_org_and_global_content(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
        ]);
        $selectedOrg = $this->createOrganization('Growth Org');
        $otherOrg = $this->createOrganization('Other Growth Org');

        $article = Article::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'growth-article',
            'locale' => 'en',
            'title' => 'Growth Article',
            'excerpt' => 'Growth article excerpt',
            'content_md' => 'Body',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::now()->subDays(4),
        ]);
        ArticleSeoMeta::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'article_id' => (int) $article->id,
            'locale' => 'en',
            'seo_title' => 'Growth Article SEO',
            'seo_description' => 'Growth article description',
            'canonical_url' => 'https://frontend.example.test/en/articles/growth-article',
            'og_title' => 'Growth Article OG',
            'og_description' => 'Growth Article OG Description',
            'og_image_url' => 'https://frontend.example.test/images/growth-article.png',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        Article::query()->create([
            'org_id' => (int) $otherOrg->id,
            'slug' => 'other-org-article',
            'locale' => 'en',
            'title' => 'Other Org Article',
            'excerpt' => 'Other org article excerpt',
            'content_md' => 'Body',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::now()->subDays(3),
        ]);

        $guide = CareerGuide::query()->create([
            'org_id' => 0,
            'guide_code' => 'growth-guide',
            'slug' => 'growth-guide',
            'locale' => 'en',
            'title' => 'Growth Guide',
            'excerpt' => 'Growth guide excerpt',
            'category_slug' => 'career-planning',
            'body_md' => 'Guide body',
            'body_html' => '<p>Guide body</p>',
            'related_industry_slugs_json' => ['technology'],
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => false,
            'sort_order' => 0,
            'schema_version' => 'v1',
            'published_at' => Carbon::now()->subDays(2),
        ]);
        CareerGuideSeoMeta::query()->create([
            'career_guide_id' => (int) $guide->id,
            'seo_title' => 'Growth Guide SEO',
            'seo_description' => 'Growth guide description',
            'canonical_url' => '',
            'og_title' => 'Growth Guide OG',
            'og_description' => 'Growth Guide OG Description',
            'og_image_url' => 'https://frontend.example.test/images/growth-guide.png',
            'twitter_title' => 'Growth Guide Twitter',
            'twitter_description' => 'Growth Guide Twitter Description',
            'twitter_image_url' => 'https://frontend.example.test/images/growth-guide-twitter.png',
            'robots' => 'noindex,follow',
        ]);

        $job = CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => 'growth-job',
            'slug' => 'growth-job',
            'locale' => 'en',
            'title' => 'Growth Job',
            'excerpt' => 'Growth job excerpt',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'schema_version' => 'v1',
            'sort_order' => 0,
            'published_at' => Carbon::now()->subDay(),
        ]);
        CareerJobSeoMeta::query()->create([
            'job_id' => (int) $job->id,
            'seo_title' => 'Growth Job SEO',
            'seo_description' => 'Growth job description',
            'canonical_url' => 'https://frontend.example.test/en/career/jobs/growth-job',
            'og_title' => 'Growth Job OG',
            'og_description' => 'Growth Job OG Description',
            'og_image_url' => 'https://frontend.example.test/images/growth-job.png',
            'twitter_title' => 'Growth Job Twitter',
            'twitter_description' => 'Growth Job Twitter Description',
            'twitter_image_url' => 'https://frontend.example.test/images/growth-job-twitter.png',
            'robots' => 'index,follow',
        ]);

        Event::query()->create([
            'id' => (string) Str::uuid(),
            'event_code' => 'content_entry',
            'event_name' => 'content_entry',
            'org_id' => (int) $selectedOrg->id,
            'meta_json' => [
                'landing_path' => '/en/articles/growth-article',
                'share_click_id' => 'click_growth_article',
            ],
            'share_id' => 'share_growth_article',
            'occurred_at' => Carbon::now()->subDays(3),
        ]);

        Event::query()->create([
            'id' => (string) Str::uuid(),
            'event_code' => 'content_entry',
            'event_name' => 'content_entry',
            'org_id' => (int) $selectedOrg->id,
            'meta_json' => [
                'landing_path' => '/en/career/jobs/growth-job',
            ],
            'occurred_at' => Carbon::now()->subDay(),
        ]);

        Order::query()->create([
            'id' => (string) Str::uuid(),
            'order_no' => 'ord_growth_article',
            'provider' => 'stripe',
            'status' => 'paid',
            'payment_state' => 'paid',
            'grant_state' => 'not_started',
            'amount_total' => 1299,
            'amount_cents' => 1299,
            'currency' => 'USD',
            'item_sku' => 'MBTI_REPORT_FULL',
            'sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'anon_id' => 'anon_growth_article',
            'org_id' => (int) $selectedOrg->id,
            'meta_json' => [
                'attribution' => [
                    'landing_path' => '/en/articles/growth-article',
                    'share_id' => 'share_growth_article',
                    'share_click_id' => 'click_growth_article',
                    'entrypoint' => 'article_detail',
                ],
            ],
            'paid_at' => Carbon::now()->subDays(2),
        ]);

        Order::query()->create([
            'id' => (string) Str::uuid(),
            'order_no' => 'ord_growth_guide',
            'provider' => 'stripe',
            'status' => 'fulfilled',
            'payment_state' => 'paid',
            'grant_state' => 'granted',
            'amount_total' => 2599,
            'amount_cents' => 2599,
            'currency' => 'USD',
            'item_sku' => 'MBTI_REPORT_FULL',
            'sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'anon_id' => 'anon_growth_guide',
            'org_id' => (int) $selectedOrg->id,
            'meta_json' => [
                'attribution' => [
                    'landing_path' => '/en/career/guides/growth-guide',
                    'entrypoint' => 'career_guide_detail',
                ],
            ],
            'paid_at' => Carbon::now()->subDay(),
        ]);

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/content-growth-attribution')
            ->assertOk()
            ->assertSee('Content growth attribution')
            ->assertSee('Growth dashboard')
            ->assertSee('Growth diagnostics')
            ->assertSee('Attribution matrix')
            ->assertSee('Growth Article')
            ->assertSee('Growth Guide')
            ->assertSee('Growth Job')
            ->assertDontSee('Other Org Article');

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/content-growth-attribution', 'GET'));

        $context = app(OrgContext::class);
        $context->set((int) $selectedOrg->id, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);

        Livewire::test(ContentGrowthAttributionPage::class)
            ->assertOk()
            ->assertSet('headlineFields.0.value', '2')
            ->assertSet('headlineFields.1.value', '3')
            ->assertSet('headlineFields.2.value', '2')
            ->assertSet('headlineFields.3.value', '2')
            ->assertSet('headlineFields.4.value', '$38.98')
            ->assertSet('diagnosticCards.0.value', '1')
            ->assertSet('diagnosticCards.1.value', '1')
            ->assertSet('matrixRows.0.title', 'Growth Article')
            ->assertSet('matrixRows.0.paid_orders', 1)
            ->assertSet('matrixRows.0.share_assisted_orders', 1)
            ->assertSet('matrixRows.1.title', 'Growth Guide')
            ->assertSet('matrixRows.1.paid_orders', 1);
    }

    public function test_content_growth_attribution_tracks_share_only_conversion_lineage_and_strict_canonical_health(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
        ]);
        $selectedOrg = $this->createOrganization('Growth Lineage Org');

        $article = Article::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'lineage-article',
            'locale' => 'en',
            'title' => 'Lineage Article',
            'excerpt' => 'Lineage article excerpt',
            'content_md' => 'Body',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::now()->subDays(2),
        ]);

        ArticleSeoMeta::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'article_id' => (int) $article->id,
            'locale' => 'en',
            'seo_title' => 'Lineage Article SEO',
            'seo_description' => 'Lineage article description',
            'canonical_url' => 'https://frontend.example.test/en/articles/not-the-real-slug',
            'og_title' => 'Lineage Article OG',
            'og_description' => 'Lineage Article OG Description',
            'og_image_url' => 'https://frontend.example.test/images/lineage-article.png',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        Event::query()->create([
            'id' => (string) Str::uuid(),
            'event_code' => 'share_generate',
            'event_name' => 'share_generate',
            'org_id' => (int) $selectedOrg->id,
            'meta_json' => [
                'landing_path' => '/en/articles/lineage-article',
                'share_click_id' => 'lineage_click_1',
            ],
            'share_id' => 'lineage_share_1',
            'occurred_at' => Carbon::now()->subDays(2),
        ]);

        Event::query()->create([
            'id' => (string) Str::uuid(),
            'event_code' => 'share_click',
            'event_name' => 'share_click',
            'org_id' => (int) $selectedOrg->id,
            'meta_json' => [
                'entry_page' => 'share_page',
                'share_click_id' => 'lineage_click_1',
            ],
            'share_id' => 'lineage_share_1',
            'occurred_at' => Carbon::now()->subDay(),
        ]);

        Order::query()->create([
            'id' => (string) Str::uuid(),
            'order_no' => 'ord_lineage_article',
            'provider' => 'stripe',
            'status' => 'paid',
            'payment_state' => 'paid',
            'grant_state' => 'not_started',
            'amount_total' => 4999,
            'amount_cents' => 4999,
            'currency' => 'USD',
            'item_sku' => 'MBTI_REPORT_FULL',
            'sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'anon_id' => 'anon_lineage_article',
            'org_id' => (int) $selectedOrg->id,
            'meta_json' => [
                'attribution' => [
                    'share_id' => 'lineage_share_1',
                    'share_click_id' => 'lineage_click_1',
                    'entrypoint' => 'share_page',
                ],
            ],
            'paid_at' => Carbon::now()->subHours(8),
        ]);

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/content-growth-attribution', 'GET'));

        $context = app(OrgContext::class);
        $context->set((int) $selectedOrg->id, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);

        Livewire::test(ContentGrowthAttributionPage::class)
            ->assertOk()
            ->assertSet('headlineFields.0.value', '0')
            ->assertSet('headlineFields.1.value', '1')
            ->assertSet('headlineFields.2.value', '2')
            ->assertSet('headlineFields.3.value', '1')
            ->assertSet('headlineFields.4.value', '$49.99')
            ->assertSet('diagnosticCards.1.value', '1')
            ->assertSet('matrixRows.0.title', 'Lineage Article')
            ->assertSet('matrixRows.0.paid_orders', 1)
            ->assertSet('matrixRows.0.share_assisted_orders', 1)
            ->assertSet('matrixRows.0.share_touchpoints', 2)
            ->assertSet('matrixRows.0.seo_label', 'Canonical gap');
    }

    private function createOrganization(string $name): Organization
    {
        return Organization::query()->create([
            'name' => $name,
            'owner_user_id' => random_int(1000, 9999),
            'status' => 'active',
            'domain' => Str::slug($name).'.example.test',
            'timezone' => 'Asia/Shanghai',
            'locale' => 'en',
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'ops_'.Str::lower(Str::random(6)),
            'email' => 'ops_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $role = Role::query()->create([
            'name' => 'role_'.Str::lower(Str::random(8)),
            'description' => null,
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['description' => null],
            );

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }

    /**
     * @return array{ops_org_id:int,ops_admin_totp_verified_user_id:int}
     */
    private function opsSession(AdminUser $admin, Organization $selectedOrg): array
    {
        return [
            'ops_org_id' => (int) $selectedOrg->id,
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ];
    }
}
