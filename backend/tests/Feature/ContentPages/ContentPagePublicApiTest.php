<?php

declare(strict_types=1);

namespace Tests\Feature\ContentPages;

use App\Models\AdminUser;
use App\Models\ContentPage;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ContentPagePublicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_baseline_import_creates_public_company_and_policy_pages(): void
    {
        $this->artisan('content-pages:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/content_pages',
        ])
            ->expectsOutputToContain('files_found=4')
            ->expectsOutputToContain('pages_found=28')
            ->expectsOutputToContain('will_create=28')
            ->assertExitCode(0);

        $this->assertSame(28, ContentPage::query()->withoutGlobalScopes()->count());

        $about = ContentPage::query()
            ->withoutGlobalScopes()
            ->where('slug', 'about')
            ->where('locale', 'zh-CN')
            ->firstOrFail();

        $this->assertSame('关于费马测试', (string) $about->title);
        $this->assertSame('company', (string) $about->kind);
        $this->assertTrue((bool) $about->is_public);
        $this->assertContains('我们是谁', $about->headings_json ?? []);

        $privacy = ContentPage::query()
            ->withoutGlobalScopes()
            ->where('slug', 'privacy')
            ->where('locale', 'zh-CN')
            ->firstOrFail();

        $this->assertSame('policy', (string) $privacy->kind);
        $this->assertSame('2026-04-19', $privacy->effective_at?->format('Y-m-d'));

        $helpFaq = ContentPage::query()
            ->withoutGlobalScopes()
            ->where('slug', 'help-faq')
            ->where('locale', 'zh-CN')
            ->firstOrFail();

        $this->assertSame('help', (string) $helpFaq->kind);
        $this->assertSame('/help/faq', (string) $helpFaq->path);

        $methodBoundaries = ContentPage::query()
            ->withoutGlobalScopes()
            ->where('slug', 'method-boundaries')
            ->where('locale', 'zh-CN')
            ->firstOrFail();

        $this->assertSame('policy', (string) $methodBoundaries->kind);
        $this->assertSame('/method-boundaries', (string) $methodBoundaries->path);
        $this->assertTrue((bool) $methodBoundaries->publish_allowed);
        $this->assertSame('passed', (string) $methodBoundaries->claim_gate_status);
        $this->assertNotNull($methodBoundaries->operator_approved_at);
        $this->assertContains('四、医学与高风险场景边界', $methodBoundaries->headings_json ?? []);
    }

    public function test_public_api_returns_content_page_without_frontend_fallback(): void
    {
        $this->artisan('content-pages:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/content_pages',
        ])->assertExitCode(0);

        $this->getJson('/api/v0.5/content-pages/about?locale=zh-CN&org_id=0')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('page.slug', 'about')
            ->assertJsonPath('page.title', '关于费马测试')
            ->assertJsonPath('page.locale', 'zh-CN')
            ->assertJsonPath('page.is_public', true)
            ->assertJsonPath('page.is_indexable', true)
            ->assertJsonPath('page.headings.0', '我们是谁');

        $this->getJson('/api/v0.5/content-pages/missing-page?locale=zh-CN&org_id=0')
            ->assertNotFound()
            ->assertJsonPath('ok', false);

        $this->getJson('/api/v0.5/content-pages/help-faq?locale=zh-CN&org_id=0')
            ->assertOk()
            ->assertJsonPath('page.slug', 'help-faq')
            ->assertJsonPath('page.path', '/help/faq')
            ->assertJsonPath('page.canonical_path', '/help/faq');

        $zhMethodBoundaries = $this->getJson('/api/v0.5/content-pages/method-boundaries?locale=zh-CN&org_id=0')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('page.slug', 'method-boundaries')
            ->assertJsonPath('page.title', '测评方法与使用边界')
            ->assertJsonPath('page.locale', 'zh-CN')
            ->assertJsonPath('page.is_public', true)
            ->assertJsonPath('page.is_indexable', true)
            ->assertJsonPath('page.path', '/method-boundaries')
            ->assertJsonPath('page.canonical_path', '/method-boundaries');

        $zhContent = (string) $zhMethodBoundaries->json('page.content_md');
        $this->assertStringContainsString('不是医学诊断', $zhContent);
        $this->assertStringContainsString('不承诺升学、就业', $zhContent);
        $this->assertStringContainsString('数据与隐私边界', $zhContent);

        $enMethodBoundaries = $this->getJson('/api/v0.5/content-pages/method-boundaries?locale=en&org_id=0')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('page.slug', 'method-boundaries')
            ->assertJsonPath('page.title', 'Assessment Method and Boundaries')
            ->assertJsonPath('page.locale', 'en')
            ->assertJsonPath('page.path', '/method-boundaries')
            ->assertJsonPath('page.canonical_path', '/method-boundaries');

        $enContent = (string) $enMethodBoundaries->json('page.content_md');
        $this->assertStringContainsString('not a medical diagnosis', $enContent);
        $this->assertStringContainsString('do not guarantee school admission, employment', $enContent);
        $this->assertStringContainsString('Data and privacy boundaries', $enContent);
    }

    public function test_public_api_hides_science_content_pages_until_public_readiness_gate_passes(): void
    {
        $sciencePage = ContentPage::withoutEvents(fn (): ContentPage => ContentPage::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => 'reliability-validity',
            'path' => '/reliability-validity',
            'kind' => ContentPage::KIND_POLICY,
            'page_type' => 'methodology',
            'title' => 'Reliability and validity',
            'summary' => 'Science review page.',
            'template' => 'policy',
            'animation_profile' => 'policy',
            'locale' => 'en',
            'published_at' => '2026-06-09',
            'is_public' => true,
            'is_indexable' => true,
            'review_state' => 'approved',
            'content_md' => "## Reliability\n\nDraft science content.",
            'content_html' => '',
            'seo_title' => 'Reliability and validity',
            'meta_description' => 'Science review page.',
            'status' => ContentPage::STATUS_PUBLISHED,
            'publish_allowed' => false,
            'operator_approval_required' => true,
            'operator_approved_at' => null,
            'claim_gate_status' => 'not_reviewed',
            'forbidden_claims' => [],
            'faq_schema_eligible' => false,
            'schema_eligibility_reviewed_at' => null,
        ]));

        $this->getJson('/api/v0.5/content-pages/reliability-validity?locale=en&org_id=0')
            ->assertNotFound()
            ->assertJsonPath('ok', false);

        $sciencePage->forceFill([
            'publish_allowed' => true,
            'operator_approved_at' => '2026-06-09 00:00:00',
            'claim_gate_status' => 'passed',
            'forbidden_claims' => [],
            'faq_schema_eligible' => false,
            'schema_eligibility_reviewed_at' => null,
        ])->save();

        $this->getJson('/api/v0.5/content-pages/reliability-validity?locale=en&org_id=0')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('page.slug', 'reliability-validity')
            ->assertJsonPath('page.publish_allowed', true)
            ->assertJsonPath('page.claim_gate_status', 'passed');

        ContentPage::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => 'brand',
            'path' => '/brand',
            'kind' => ContentPage::KIND_COMPANY,
            'page_type' => 'company',
            'title' => 'Brand',
            'summary' => 'Brand page.',
            'template' => 'brand',
            'animation_profile' => 'brand',
            'locale' => 'en',
            'published_at' => '2026-06-09',
            'is_public' => true,
            'is_indexable' => true,
            'review_state' => 'approved',
            'content_md' => "## Brand\n\nCompany content.",
            'content_html' => '',
            'seo_title' => 'Brand',
            'meta_description' => 'Brand page.',
            'status' => ContentPage::STATUS_PUBLISHED,
            'publish_allowed' => false,
            'claim_gate_status' => 'not_reviewed',
        ]);

        $this->getJson('/api/v0.5/content-pages/brand?locale=en&org_id=0')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('page.slug', 'brand');
    }

    public function test_english_content_page_cannot_be_indexable_without_seo_title_and_meta_description(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);

        $payload = [
            'title' => 'Science hub',
            'kicker' => 'Science',
            'summary' => 'Science content.',
            'kind' => 'policy',
            'page_type' => 'science',
            'template' => 'policy',
            'animation_profile' => 'policy',
            'locale' => 'en',
            'status' => ContentPage::STATUS_DRAFT,
            'review_state' => 'draft',
            'published_at' => null,
            'updated_at' => '2026-06-09',
            'effective_at' => null,
            'source_doc' => 'science-contentpage-en-review-draft-2026-06-09',
            'is_public' => false,
            'is_indexable' => true,
            'content_md' => "## Science\n\nDraft content.",
            'content_html' => '',
            'seo_title' => '',
            'meta_description' => '',
            'seo_description' => '',
            'canonical_path' => '/science',
        ];

        $this->withSession(['ops_org_id' => 0])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->putJson('/api/v0.5/internal/content-pages/science', $payload)
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'CONTENT_PAGE_PUBLISH_GATE_FAILED')
            ->assertJsonValidationErrors([
                'seo_title',
                'meta_description',
            ]);

        $this->assertDatabaseMissing('content_pages', [
            'slug' => 'science',
            'locale' => 'en',
        ]);
    }

    public function test_model_level_content_page_gate_blocks_public_science_without_passed_claim_gate(): void
    {
        $this->expectException(ValidationException::class);

        ContentPage::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => 'data-privacy',
            'path' => '/data-privacy',
            'kind' => ContentPage::KIND_POLICY,
            'page_type' => 'science',
            'title' => 'Data notes',
            'summary' => 'Data notes.',
            'template' => 'policy',
            'animation_profile' => 'policy',
            'locale' => 'en',
            'published_at' => '2026-06-09',
            'is_public' => true,
            'is_indexable' => true,
            'review_state' => 'approved',
            'content_md' => "## Data\n\nContent.",
            'content_html' => '',
            'seo_title' => 'Data notes',
            'meta_description' => 'Data notes.',
            'status' => ContentPage::STATUS_PUBLISHED,
            'publish_allowed' => true,
            'operator_approval_required' => false,
            'operator_approved_at' => null,
            'claim_gate_status' => 'not_reviewed',
            'forbidden_claims' => [],
            'faq_schema_eligible' => false,
            'schema_eligibility_reviewed_at' => null,
        ]);
    }

    public function test_ops_list_and_update_are_backed_by_content_pages_table(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);

        $this->artisan('content-pages:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/content_pages',
        ])->assertExitCode(0);

        $this->withSession(['ops_org_id' => 0])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/internal/content-pages?locale=zh-CN&org_id=0&kind=company')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(5, 'items');

        $this->withSession(['ops_org_id' => 0])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->putJson('/api/v0.5/internal/content-pages/about', [
                'title' => '关于费马测试更新',
                'kicker' => 'Company',
                'summary' => '后台更新后的公司页摘要。',
                'kind' => 'company',
                'template' => 'company',
                'animation_profile' => 'mission',
                'locale' => 'zh-CN',
                'published_at' => '2026-04-19',
                'updated_at' => '2026-04-19',
                'effective_at' => null,
                'source_doc' => '01_关于费马测试.docx',
                'is_public' => true,
                'is_indexable' => true,
                'content_md' => "## 新标题\n\n后台保存正文。",
                'content_html' => '',
                'seo_title' => '关于费马测试更新',
                'meta_description' => '后台更新后的公司页摘要。',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('page.title', '关于费马测试更新')
            ->assertJsonPath('page.headings.0', '新标题');

        $this->assertDatabaseHas('content_pages', [
            'slug' => 'about',
            'locale' => 'zh-CN',
            'title' => '关于费马测试更新',
        ]);

        $this->withSession(['ops_org_id' => 0])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->putJson('/api/v0.5/internal/content-pages/help-contact', [
                'title' => '联系支持更新',
                'kicker' => 'Help',
                'summary' => '后台更新后的帮助页摘要。',
                'kind' => 'help',
                'template' => 'help',
                'animation_profile' => 'editorial',
                'locale' => 'zh-CN',
                'published_at' => '2026-04-19',
                'updated_at' => '2026-04-19',
                'effective_at' => null,
                'source_doc' => '帮助_联系支持.docx',
                'is_public' => true,
                'is_indexable' => true,
                'content_md' => "## 支持入口\n\n先完成正式流程。",
                'content_html' => '',
                'seo_title' => '联系支持更新',
                'meta_description' => '后台更新后的帮助页摘要。',
            ])
            ->assertOk()
            ->assertJsonPath('page.slug', 'help-contact')
            ->assertJsonPath('page.path', '/help/contact')
            ->assertJsonPath('page.canonical_path', '/help/contact');
    }

    public function test_help_service_fields_are_first_class_content_page_contracts(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);

        $payload = [
            'title' => 'Help support',
            'kicker' => 'Help',
            'summary' => 'Help service support summary.',
            'kind' => 'help',
            'template' => 'help',
            'animation_profile' => 'editorial',
            'locale' => 'en',
            'status' => ContentPage::STATUS_DRAFT,
            'review_state' => 'owner_review',
            'published_at' => null,
            'updated_at' => '2026-06-05',
            'effective_at' => null,
            'source_doc' => 'help-service-content-drafts-01',
            'is_public' => false,
            'is_indexable' => false,
            'content_md' => "## Support\n\nEmail support for help.",
            'content_html' => '',
            'seo_title' => 'Help support',
            'meta_description' => 'Help support summary.',
            'canonical_path' => '/help/support',
            'support_contact' => 'support@fermatmind.com',
            'policy_version' => 'help_service_policy.v1',
            'reviewer' => 'operator_review',
            'schema_enabled' => true,
            'faq_items' => [
                [
                    'question' => 'How do I contact support?',
                    'answer' => 'Email support.',
                ],
            ],
        ];

        $this->withSession(['ops_org_id' => 0])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->putJson('/api/v0.5/internal/content-pages/help-support', $payload)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('page.slug', 'help-support')
            ->assertJsonPath('page.support_contact', 'support@fermatmind.com')
            ->assertJsonPath('page.policy_version', 'help_service_policy.v1')
            ->assertJsonPath('page.reviewer', 'operator_review')
            ->assertJsonPath('page.schema_enabled', true)
            ->assertJsonPath('page.faq_items.0.question', 'How do I contact support?')
            ->assertJsonPath('page.faq_items.0.answer', 'Email support.');

        $this->assertDatabaseHas('content_pages', [
            'slug' => 'help-support',
            'locale' => 'en',
            'support_contact' => 'support@fermatmind.com',
            'policy_version' => 'help_service_policy.v1',
            'reviewer' => 'operator_review',
            'schema_enabled' => true,
            'is_public' => false,
            'is_indexable' => false,
            'status' => ContentPage::STATUS_DRAFT,
        ]);

        $page = ContentPage::query()
            ->withoutGlobalScopes()
            ->where('slug', 'help-support')
            ->where('locale', 'en')
            ->firstOrFail();

        $this->assertSame([
            [
                'question' => 'How do I contact support?',
                'answer' => 'Email support.',
            ],
        ], $page->faq_items);
    }

    public function test_help_service_importer_materializes_service_fields_on_draft_rows(): void
    {
        $this->artisan('content-pages:import-local-baseline', [
            '--upsert' => true,
            '--status' => ContentPage::STATUS_DRAFT,
            '--source-dir' => 'docs/help/import-packages/content-pages-draft-source',
        ])
            ->expectsOutputToContain('files_found=1')
            ->expectsOutputToContain('pages_found=12')
            ->expectsOutputToContain('will_create=12')
            ->assertExitCode(0);

        $targetSlugs = [
            'help-unlock-failure',
            'help-payment-refund',
            'help-result-recovery',
            'help-privacy-data',
            'help-use-boundaries',
            'help-data-deletion',
        ];

        $rows = ContentPage::query()
            ->withoutGlobalScopes()
            ->whereIn('slug', $targetSlugs)
            ->whereIn('locale', ['zh-CN', 'en'])
            ->get();

        $this->assertCount(12, $rows);

        foreach ($rows as $row) {
            $this->assertSame(ContentPage::STATUS_DRAFT, (string) $row->status);
            $this->assertFalse((bool) $row->is_public);
            $this->assertFalse((bool) $row->is_indexable);
            $this->assertNull($row->published_at);
            $this->assertSame('owner_review', (string) $row->review_state);
            $this->assertSame('support@fermatmind.com', (string) $row->support_contact);
            $this->assertSame('help_service_policy.v1', (string) $row->policy_version);
            $this->assertSame('Unknown', (string) $row->reviewer);
            $this->assertFalse((bool) $row->schema_enabled);
            $this->assertCount(4, $row->faq_items ?? []);
            $this->assertArrayHasKey('question', ($row->faq_items ?? [])[0] ?? []);
            $this->assertArrayHasKey('answer', ($row->faq_items ?? [])[0] ?? []);
        }
    }

    public function test_science_content_page_publish_requires_first_class_safety_fields(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);

        $basePayload = [
            'title' => 'Science hub',
            'kicker' => 'Science',
            'summary' => 'Draft science hub.',
            'kind' => 'policy',
            'page_type' => 'science',
            'template' => 'policy',
            'animation_profile' => 'policy',
            'locale' => 'en',
            'status' => ContentPage::STATUS_PUBLISHED,
            'review_state' => 'science_review',
            'published_at' => '2026-06-08',
            'updated_at' => '2026-06-08',
            'effective_at' => null,
            'source_doc' => 'science-contentpage-cms-draft-package',
            'is_public' => true,
            'is_indexable' => true,
            'content_md' => "## Science\n\nDraft-only science content.",
            'content_html' => '',
            'seo_title' => 'Science hub',
            'meta_description' => 'Draft science hub.',
            'canonical_path' => '/science',
            'science_review_required' => true,
            'legal_review_required' => true,
            'schema_enabled' => true,
            'faq_items' => [
                [
                    'question' => 'Is this reviewed?',
                    'answer' => 'Not yet.',
                ],
            ],
        ];

        $this->withSession(['ops_org_id' => 0])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->putJson('/api/v0.5/internal/content-pages/science', $basePayload)
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'CONTENT_PAGE_PUBLISH_GATE_FAILED')
            ->assertJsonValidationErrors([
                'publish_allowed',
                'operator_approved_at',
                'review_state',
                'legal_review_required',
                'science_review_required',
                'claim_gate_status',
                'faq_schema_eligible',
            ]);

        $this->assertDatabaseMissing('content_pages', [
            'slug' => 'science',
            'locale' => 'en',
        ]);

        $approvedPayload = array_merge($basePayload, [
            'review_state' => 'approved',
            'science_review_required' => false,
            'legal_review_required' => false,
            'publish_allowed' => true,
            'operator_approval_required' => true,
            'operator_approved_at' => '2026-06-08',
            'claim_gate_status' => 'passed',
            'forbidden_claims' => [],
            'faq_schema_eligible' => true,
            'schema_eligibility_reviewed_at' => '2026-06-08',
        ]);

        $this->withSession(['ops_org_id' => 0])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->putJson('/api/v0.5/internal/content-pages/science', $approvedPayload)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('page.slug', 'science')
            ->assertJsonPath('page.status', ContentPage::STATUS_PUBLISHED)
            ->assertJsonPath('page.publish_allowed', true)
            ->assertJsonPath('page.operator_approval_required', true)
            ->assertJsonPath('page.operator_approved_at', '2026-06-08')
            ->assertJsonPath('page.claim_gate_status', 'passed')
            ->assertJsonPath('page.faq_schema_eligible', true)
            ->assertJsonPath('page.schema_eligibility_reviewed_at', '2026-06-08');

        $this->assertDatabaseHas('content_pages', [
            'slug' => 'science',
            'locale' => 'en',
            'status' => ContentPage::STATUS_PUBLISHED,
            'is_public' => true,
            'publish_allowed' => true,
            'claim_gate_status' => 'passed',
            'faq_schema_eligible' => true,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'admin_'.Str::lower(Str::random(6)),
            'email' => 'admin_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $role = Role::query()->create([
            'name' => 'role_'.Str::lower(Str::random(10)),
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
}
