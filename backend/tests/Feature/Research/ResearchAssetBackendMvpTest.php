<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\ResearchReport;
use App\Models\Role;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ResearchAssetBackendMvpTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_research_api_exposes_only_published_approved_public_indexable_records(): void
    {
        $visible = $this->createReport([
            'slug' => 'mbti-salary-turnover',
            'status' => ResearchReport::STATUS_PUBLISHED,
            'review_state' => ResearchReport::REVIEW_APPROVED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 5, 18, 0, 0, 0, 'UTC'),
        ]);

        $this->createReport([
            'slug' => 'draft-report',
            'status' => ResearchReport::STATUS_DRAFT,
            'review_state' => ResearchReport::REVIEW_RESEARCH,
            'is_public' => false,
            'is_indexable' => false,
        ]);

        $this->createReport([
            'slug' => 'noindex-report',
            'status' => ResearchReport::STATUS_PUBLISHED,
            'review_state' => ResearchReport::REVIEW_APPROVED,
            'is_public' => true,
            'is_indexable' => false,
        ]);

        $response = $this->getJson('/api/v0.5/research?locale=en');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('page_entity_type', 'research_report')
            ->assertJsonPath('exposure_gate', 'published_approved_public_indexable_only')
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.slug', (string) $visible->slug)
            ->assertJsonPath('items.0.page_entity_type', 'research_report')
            ->assertJsonPath('items.0.research_type', 'salary_turnover')
            ->assertJsonPath('items.0.methodology', 'Methods are documented before publish.')
            ->assertJsonPath('items.0.sample_disclaimer', 'Aggregates only; no individual user data.')
            ->assertJsonPath('items.0.claim_boundary', 'Descriptive research only; no hiring, diagnosis, or guaranteed outcome claims.');

        $this->assertStringNotContainsString('draft-report', (string) $response->getContent());
        $this->assertStringNotContainsString('noindex-report', (string) $response->getContent());

        $this->getJson('/api/v0.5/research/draft-report?locale=en')->assertNotFound();
        $this->getJson('/api/v0.5/research/noindex-report?locale=en')->assertNotFound();
    }

    public function test_public_research_detail_requires_publish_gate_and_returns_safe_metadata(): void
    {
        $this->createReport([
            'slug' => 'mbti-salary-turnover',
            'status' => ResearchReport::STATUS_PUBLISHED,
            'review_state' => ResearchReport::REVIEW_APPROVED,
            'is_public' => true,
            'is_indexable' => true,
            'references' => ['Internal aggregate methodology memo'],
            'last_reviewed_at' => Carbon::create(2026, 5, 18, 0, 0, 0, 'UTC'),
            'published_at' => Carbon::create(2026, 5, 18, 1, 0, 0, 'UTC'),
        ]);

        $response = $this->getJson('/api/v0.5/research/mbti-salary-turnover?locale=en');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('report.slug', 'mbti-salary-turnover')
            ->assertJsonPath('report.page_entity_type', 'research_report')
            ->assertJsonPath('report.references.0', 'Internal aggregate methodology memo')
            ->assertJsonPath('report.downloadable_asset_placeholder', 'asset pending')
            ->assertJsonMissingPath('report.status')
            ->assertJsonMissingPath('report.review_state')
            ->assertJsonMissingPath('report.search_channel_eligible');
    }

    public function test_internal_cms_update_enforces_draft_publish_gate(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_WRITE]);

        $this->withSession(['ops_org_id' => 0])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->putJson('/api/v0.5/internal/research-reports/draft-public-leak?locale=en', [
                'title' => 'Draft public leak',
                'executive_summary' => 'Should not become public.',
                'body_md' => 'Draft body.',
                'research_type' => 'salary_turnover',
                'methodology' => 'Methods.',
                'sample_disclaimer' => 'Aggregate-only sample disclaimer.',
                'claim_boundary' => 'No diagnosis or hiring claims.',
                'locale' => 'en',
                'status' => 'draft',
                'review_state' => 'research_review',
                'is_public' => true,
                'is_indexable' => true,
            ])->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'draft or archived research reports cannot be public or indexable.');

        $this->withSession(['ops_org_id' => 0])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->putJson('/api/v0.5/internal/research-reports/unapproved-publish?locale=en', [
                'title' => 'Unapproved publish',
                'executive_summary' => 'Should not publish.',
                'body_md' => 'Body.',
                'research_type' => 'salary_turnover',
                'methodology' => 'Methods.',
                'sample_disclaimer' => 'Aggregate-only sample disclaimer.',
                'claim_boundary' => 'No diagnosis or hiring claims.',
                'locale' => 'en',
                'status' => 'published',
                'review_state' => 'claim_review',
                'is_public' => true,
                'is_indexable' => true,
            ])->assertStatus(422)
            ->assertJsonPath('errors.review_state.0', 'published research reports must be approved.');

        $response = $this->withSession(['ops_org_id' => 0])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->putJson('/api/v0.5/internal/research-reports/approved-research?locale=en', [
                'title' => 'Approved research',
                'executive_summary' => 'Summary.',
                'body_md' => 'Body.',
                'research_type' => 'salary_turnover',
                'methodology' => 'Methods.',
                'sample_disclaimer' => 'Aggregate-only sample disclaimer.',
                'claim_boundary' => 'No diagnosis or hiring claims.',
                'author_name' => 'Research Team',
                'reviewer_name' => 'Claim Reviewer',
                'references' => ['Reference A'],
                'downloadable_asset_placeholder' => 'asset pending',
                'locale' => 'en',
                'status' => 'published',
                'review_state' => 'approved',
                'is_public' => true,
                'is_indexable' => true,
                'last_reviewed_at' => '2026-05-18T00:00:00Z',
                'published_at' => '2026-05-18T01:00:00Z',
                'seo_title' => 'Approved research',
                'seo_description' => 'Research description.',
                'canonical_path' => '/research/approved-research',
            ]);

        $response->assertOk()
            ->assertJsonPath('report.slug', 'approved-research')
            ->assertJsonPath('report.status', 'published')
            ->assertJsonPath('report.review_state', 'approved')
            ->assertJsonPath('report.sitemap_eligible', false)
            ->assertJsonPath('report.llms_eligible', false)
            ->assertJsonPath('report.search_channel_eligible', false);

        $this->assertDatabaseHas('research_reports', [
            'slug' => 'approved-research',
            'status' => 'published',
            'review_state' => 'approved',
            'is_public' => true,
            'is_indexable' => true,
        ]);
    }

    public function test_internal_cms_reads_and_updates_use_trusted_ops_org_context(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);

        $this->createReport([
            'org_id' => 41,
            'slug' => 'trusted-org-report',
            'title' => 'Trusted org report',
        ]);
        $this->createReport([
            'org_id' => 99,
            'slug' => 'victim-org-report',
            'title' => 'Victim org report',
        ]);

        $indexResponse = $this->withSession(['ops_org_id' => 41])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/internal/research-reports?locale=en&org_id=99');

        $indexResponse->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.slug', 'trusted-org-report');
        $this->assertStringNotContainsString('victim-org-report', (string) $indexResponse->getContent());

        $this->withSession(['ops_org_id' => 41])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->getJson('/api/v0.5/internal/research-reports/victim-org-report?locale=en&org_id=99')
            ->assertNotFound();

        $updateResponse = $this->withSession(['ops_org_id' => 41])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->putJson('/api/v0.5/internal/research-reports/trusted-write?locale=en', $this->researchUpdatePayload([
                'title' => 'Trusted write',
                'org_id' => 99,
            ]));

        $updateResponse->assertOk()
            ->assertJsonPath('report.slug', 'trusted-write');

        $this->assertDatabaseHas('research_reports', [
            'org_id' => 41,
            'slug' => 'trusted-write',
            'title' => 'Trusted write',
        ]);
        $this->assertDatabaseMissing('research_reports', [
            'org_id' => 99,
            'slug' => 'trusted-write',
        ]);
    }

    public function test_research_artifact_records_no_publish_or_search_exposure_in_this_pr(): void
    {
        $artifact = json_decode((string) file_get_contents(base_path('docs/seo/generated/research-asset-backend-mvp.v1.json')), true);

        $this->assertSame('research-asset-backend-mvp.v1', $artifact['version'] ?? null);
        $this->assertSame('research_report', data_get($artifact, 'entity.page_entity_type'));
        $this->assertSame('/api/v0.5/research/{slug}', data_get($artifact, 'entity.api_routes.public_show'));
        $this->assertTrue((bool) data_get($artifact, 'draft_publish_gate.public_read_requires.is_public'));
        $this->assertTrue((bool) data_get($artifact, 'draft_publish_gate.public_read_requires.is_indexable'));
        $this->assertFalse((bool) data_get($artifact, 'seo_search_boundary.sitemap_eligible_in_this_pr'));
        $this->assertFalse((bool) data_get($artifact, 'seo_search_boundary.llms_eligible_in_this_pr'));
        $this->assertFalse((bool) data_get($artifact, 'seo_search_boundary.search_channel_queue_eligible_in_this_pr'));
        $this->assertFalse((bool) data_get($artifact, 'seo_search_boundary.research_publish_in_this_pr'));
        $this->assertFalse((bool) data_get($artifact, 'operations_not_performed.collector_write'));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createReport(array $overrides = []): ResearchReport
    {
        return ResearchReport::query()->create(array_merge([
            'org_id' => 0,
            'slug' => 'research-report',
            'locale' => 'en',
            'title' => 'Research Report',
            'executive_summary' => 'Executive summary.',
            'body_md' => 'Body.',
            'research_type' => 'salary_turnover',
            'methodology' => 'Methods are documented before publish.',
            'sample_disclaimer' => 'Aggregates only; no individual user data.',
            'claim_boundary' => 'Descriptive research only; no hiring, diagnosis, or guaranteed outcome claims.',
            'author_name' => 'Research Team',
            'reviewer_name' => 'Claim Reviewer',
            'references' => [],
            'downloadable_asset_placeholder' => 'asset pending',
            'status' => ResearchReport::STATUS_DRAFT,
            'review_state' => ResearchReport::REVIEW_DRAFT,
            'is_public' => false,
            'is_indexable' => false,
            'canonical_path' => '/research/research-report',
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function researchUpdatePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Approved research',
            'executive_summary' => 'Summary.',
            'body_md' => 'Body.',
            'research_type' => 'salary_turnover',
            'methodology' => 'Methods.',
            'sample_disclaimer' => 'Aggregate-only sample disclaimer.',
            'claim_boundary' => 'No diagnosis or hiring claims.',
            'author_name' => 'Research Team',
            'reviewer_name' => 'Claim Reviewer',
            'references' => ['Reference A'],
            'downloadable_asset_placeholder' => 'asset pending',
            'locale' => 'en',
            'status' => 'draft',
            'review_state' => 'research_review',
            'is_public' => false,
            'is_indexable' => false,
            'canonical_path' => '/research/trusted-write',
        ], $overrides);
    }

    /**
     * @param  array<int,string>  $permissions
     */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'admin_'.Str::lower(Str::random(6)),
            'email' => 'admin_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        if ($permissions === []) {
            return $admin;
        }

        $role = Role::query()->create([
            'name' => 'role_'.Str::lower(Str::random(10)),
            'description' => null,
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['description' => null]
            );

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }
}
