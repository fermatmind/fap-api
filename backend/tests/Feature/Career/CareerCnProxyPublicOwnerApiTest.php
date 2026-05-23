<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerCnProxyPublicOwnerApiTest extends TestCase
{
    public function test_it_exposes_reviewed_cn_proxy_public_owner_surface_as_noindex_noncanonical_page(): void
    {
        [$planPath, $manifestPath] = $this->writeReviewedPublicOwnerArtifacts();
        config()->set('fap.career.cn_proxy_public_owner_plan_path', $planPath);
        config()->set('fap.career.cn_proxy_trust_manifest_path', $manifestPath);

        $this->getJson('/api/v0.5/career/cn-proxy/cn-1-01-00-01?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('bundle_kind', 'career_cn_proxy_public_owner')
            ->assertJsonPath('identity.canonical_slug', 'cn-1-01-00-01')
            ->assertJsonPath('identity.public_resolution_type', 'public_cn_proxy_page')
            ->assertJsonPath('public_owner_policy.route_owner_enabled', true)
            ->assertJsonPath('public_owner_policy.canonical_job_detail', false)
            ->assertJsonPath('public_owner_policy.indexable', false)
            ->assertJsonPath('public_owner_policy.sitemap_eligible', false)
            ->assertJsonPath('public_owner_policy.llms_eligible', false)
            ->assertJsonPath('public_owner_policy.llms_full_eligible', false)
            ->assertJsonPath('public_owner_policy.us_canonical_job_schema_returned', false)
            ->assertJsonPath('seo_contract.canonical_path', '/career/cn-proxy/cn-1-01-00-01')
            ->assertJsonPath('seo_contract.index_eligible', false)
            ->assertJsonPath('seo_contract.robots_policy', 'noindex,follow')
            ->assertJsonPath('structured_data.occupation', [])
            ->assertJsonPath('trust_manifest.review_decision', 'approve_noindex_public_cn_proxy_page');
    }

    public function test_existing_job_detail_api_falls_back_to_cn_proxy_surface_without_canonical_job_schema(): void
    {
        [$planPath, $manifestPath] = $this->writeReviewedPublicOwnerArtifacts();
        config()->set('fap.career.cn_proxy_public_owner_plan_path', $planPath);
        config()->set('fap.career.cn_proxy_trust_manifest_path', $manifestPath);

        $this->getJson('/api/v0.5/career/jobs/cn-1-01-00-01?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('bundle_kind', 'career_cn_proxy_public_owner')
            ->assertJsonPath('identity.canonical_slug', 'cn-1-01-00-01')
            ->assertJsonPath('seo_contract.robots_policy', 'noindex,follow')
            ->assertJsonPath('public_owner_policy.canonical_job_detail', false)
            ->assertJsonPath('structured_data.occupation', []);
    }

    public function test_it_fails_closed_when_reviewed_manifest_is_missing(): void
    {
        [$planPath] = $this->writeReviewedPublicOwnerArtifacts(writeManifest: false);
        config()->set('fap.career.cn_proxy_public_owner_plan_path', $planPath);
        config()->set('fap.career.cn_proxy_trust_manifest_path', storage_path('framework/testing/missing-cn-proxy-manifest.json'));

        $this->getJson('/api/v0.5/career/cn-proxy/cn-1-01-00-01')
            ->assertNotFound();
    }

    public function test_it_fails_closed_until_public_route_release_gate_is_approved(): void
    {
        [$planPath, $manifestPath] = $this->writeReviewedPublicOwnerArtifacts(publicRouteAllowed: false);
        config()->set('fap.career.cn_proxy_public_owner_plan_path', $planPath);
        config()->set('fap.career.cn_proxy_trust_manifest_path', $manifestPath);

        $this->getJson('/api/v0.5/career/cn-proxy/cn-1-01-00-01')
            ->assertNotFound();

        $this->getJson('/api/v0.5/career/jobs/cn-1-01-00-01?locale=zh-CN')
            ->assertNotFound();
    }

    public function test_it_rejects_manifest_that_attempts_indexable_cn_proxy_publication(): void
    {
        [$planPath, $manifestPath] = $this->writeReviewedPublicOwnerArtifacts(indexable: true);
        config()->set('fap.career.cn_proxy_public_owner_plan_path', $planPath);
        config()->set('fap.career.cn_proxy_trust_manifest_path', $manifestPath);

        $this->getJson('/api/v0.5/career/cn-proxy/cn-1-01-00-01')
            ->assertNotFound();
    }

    /**
     * @return array{0: string, 1?: string}
     */
    private function writeReviewedPublicOwnerArtifacts(
        bool $writeManifest = true,
        bool $indexable = false,
        bool $publicRouteAllowed = true,
    ): array {
        $dir = storage_path('framework/testing/cn-proxy-public-owner');
        File::ensureDirectoryExists($dir);

        $manifestPath = $dir.'/reviewed-manifest.json';
        $planPath = $dir.'/public-owner-plan.json';

        if ($writeManifest) {
            File::put($manifestPath, (string) json_encode([
                'schema_version' => 'career_2786_cn_proxy_trust_manifest_draft.v1',
                'status' => 'reviewed_noindex_non_indexable',
                'claims' => [
                    [
                        'claim_id' => 'career-2786-cn-proxy-cn-1-01-00-01',
                        'row_number' => 183,
                        'slug' => 'cn-1-01-00-01',
                        'source_slug' => 'cn-1-01-00-01',
                        'public_resolution_type' => 'public_cn_proxy_page',
                        'public_eligible' => false,
                        'claim_text' => 'CN proxy occupation boundary claim for 中国共产党机关负责人.',
                        'claim_locale' => 'zh-CN,en',
                        'source_authority_model' => 'career_2786_public_resolution_plan_d23b_cn_proxy_scope',
                        'evidence_refs' => [
                            [
                                'artifact' => '/tmp/career_2786_public_resolution_partition_after_occupation_apply.json',
                                'source_row_number' => 183,
                                'source_code' => '11-1011.00',
                                'title_en' => 'Chinese Communist Party Agency Managers',
                                'title_zh' => '中国共产党机关负责人',
                            ],
                        ],
                        'evidence_strength' => 'source_plan_scope_with_reviewed_proxy_mapping',
                        'reviewer' => 'reviewer',
                        'reviewed_at' => '2026-05-15T21:30:56Z',
                        'review_status' => 'human_reviewed',
                        'schema_policy' => 'public_cn_proxy_page_noindex_non_sitemap_non_llms_until_release_gate',
                        'indexability' => $indexable ? 'indexable' : 'noindex',
                        'sitemap_eligible' => false,
                        'llms_eligible' => false,
                        'llms_full_eligible' => false,
                        'boundary_disclaimer' => 'This CN occupation proxy page describes a China-source occupation boundary and is not a US canonical job page.',
                        'rollback_condition' => 'Remove or keep blocked if reviewer approval, source authority evidence, disclaimer, noindex policy, or release gate validation fails.',
                        'last_validated_at' => '2026-05-15T21:25:03Z',
                        'review_decision' => 'approve_noindex_public_cn_proxy_page',
                        'review_notes' => 'human review confirmed; preserve noindex/non-sitemap/non-llms policy',
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            File::delete($manifestPath);
        }

        File::put($planPath, (string) json_encode([
            'status' => 'validated',
            'command' => 'career:validate-cn-proxy-public-owner',
            'dry_run' => true,
            'did_write' => false,
            'manifest_path' => $manifestPath,
            'cn_proxy_rows' => 1663,
            'reviewed_trust_manifest_rows' => 1663,
            'reviewed_trust_manifest_complete' => true,
            'public_owner_plan_ready' => true,
            'route_owner_enabled' => $publicRouteAllowed,
            'public_pages_exposed' => $publicRouteAllowed ? 1663 : 0,
            'public_route_allowed' => $publicRouteAllowed,
            'release_gate_approved' => $publicRouteAllowed,
            'release_gate_approval_required' => ! $publicRouteAllowed,
            'guarded_public_owner_state' => 'reviewed_noindex_public_cn_proxy_page_ready_for_separate_owner_train',
            'public_cn_proxy_page_rows' => 1663,
            'indexable_CN_proxy_rows' => 0,
            'sitemap_CN_urls' => 0,
            'llms_CN_urls' => 0,
            'llms_full_CN_urls' => 0,
            'display_asset_delta' => 0,
            'career_job_display_assets_delta' => 0,
            'occupations_delta' => 0,
            'occupation_crosswalks_delta' => 0,
            'CN_proxy_can_masquerade_as_US_canonical_job' => false,
            'US_canonical_job_schema_returned' => false,
            'noindex_default' => true,
            'blockers' => [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $writeManifest ? [$planPath, $manifestPath] : [$planPath];
    }
}
