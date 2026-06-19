<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Models\CareerJobAiImpactAsset;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Services\Career\AiImpactAssets\CareerAiImpactAssetImportService;
use App\Services\Career\AiImpactAssets\CareerAiImpactAssetPreviewService;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class CareerAiImpactAssetPreviewImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seedRuntimeProjectionAuthority([]);
    }

    public function test_ai_impact_asset_sidecar_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('career_job_ai_impact_assets'));
        $this->assertTrue(Schema::hasColumn('career_job_ai_impact_assets', 'asset_payload_json'));
        $this->assertTrue(Schema::hasColumn('career_job_ai_impact_assets', 'asset_row_hash'));
        $this->assertTrue(Schema::hasColumn('career_job_ai_impact_assets', 'preview_allowlisted'));
    }

    public function test_importer_dry_run_validates_preview_rows_without_writing(): void
    {
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $this->assetRow('accountants-and-auditors', 'en'),
        ]);
        $report = storage_path('framework/testing/ai-impact-preview-dry-run.json');

        $exitCode = Artisan::call('career:ai-impact-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--dry-run' => true,
            '--output' => $report,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('career_job_ai_impact_assets', 0);

        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $decoded['decision']);
        $this->assertSame(2, $decoded['validated_preview_rows']);
        $this->assertFalse((bool) ($decoded['production_import_allowed'] ?? true));
        $this->assertFalse((bool) ($decoded['staging_write_performed'] ?? true));
    }

    public function test_importer_dry_run_reports_state_machine_sha_idempotency_and_rollback_policy(): void
    {
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $this->assetRow('accountants-and-auditors', 'en'),
        ]);
        $sha = hash_file('sha256', $file);
        $report = storage_path('framework/testing/ai-impact-preview-state-machine-dry-run.json');

        $exitCode = Artisan::call('career:ai-impact-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--expected-sha256' => $sha,
            '--dry-run' => true,
            '--output' => $report,
        ]);

        $this->assertSame(0, $exitCode);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $decoded['decision']);
        $this->assertSame($sha, $decoded['source_file_sha256']);
        $this->assertTrue((bool) $decoded['source_file_sha256_match']);
        $this->assertSame(0, $decoded['duplicate_key_count']);
        $this->assertSame(['career_job_slug', 'locale', 'asset_version'], $decoded['idempotency']['target_key']);
        $this->assertFalse((bool) $decoded['state_machine']['production_import_without_approved_allowed']);
        $this->assertSame(CareerJobAiImpactAsset::STATUS_APPROVED, $decoded['state_machine']['production_import_requires_from_status']);
        $this->assertTrue((bool) $decoded['rollback_policy']['production_import_requires_approved_status']);
        $this->assertFalse((bool) $decoded['rollback_policy']['dry_run_writes_database']);
    }

    public function test_importer_dry_run_rejects_unexpected_source_sha(): void
    {
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $this->assetRow('accountants-and-auditors', 'en'),
        ]);
        $report = storage_path('framework/testing/ai-impact-preview-sha-mismatch.json');

        $exitCode = Artisan::call('career:ai-impact-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--expected-sha256' => str_repeat('0', 64),
            '--dry-run' => true,
            '--output' => $report,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseCount('career_job_ai_impact_assets', 0);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse((bool) $decoded['source_file_sha256_match']);
        $this->assertStringContainsString('Source JSONL SHA-256 does not match expected artifact SHA', implode(' ', $decoded['errors']));
    }

    public function test_importer_dry_run_validates_full_1046_contract_without_writing(): void
    {
        $slugs = array_map(static fn (int $index): string => 'ai-impact-contract-career-'.$index, range(1, 1046));
        $this->seedCareerJobBundleAuthorities($slugs);

        $rows = [];
        foreach ($slugs as $slug) {
            $rows[] = $this->assetRow($slug, 'zh-CN');
            $rows[] = $this->assetRow($slug, 'en');
        }
        $file = $this->writeJsonl($rows);
        $report = storage_path('framework/testing/ai-impact-preview-full-1046-dry-run.json');

        $exitCode = Artisan::call('career:ai-impact-assets-import-preview', [
            '--file' => $file,
            '--all-slugs-from-file' => true,
            '--dry-run' => true,
            '--output' => $report,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('career_job_ai_impact_assets', 0);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $decoded['decision']);
        $this->assertSame(1046, $decoded['target_slug_count']);
        $this->assertSame(2092, $decoded['validated_preview_rows']);
        $this->assertSame(2092, $decoded['expected_preview_rows']);
        $this->assertSame(1046, $decoded['career_job_bundle_authority']['ready_slug_count']);
    }

    public function test_importer_dry_run_rejects_embedded_search_projection_and_outcome_claims(): void
    {
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $zh = $this->assetRow('accountants-and-auditors', 'zh-CN');
        $zh['search_projection'] = ['candidate_only_not_runtime_seo' => true];
        $en = $this->assetRow('accountants-and-auditors', 'en');
        $en['summary'] = 'This score predicts job loss for Accountants and Auditors.';
        $file = $this->writeJsonl([$zh, $en]);
        $report = storage_path('framework/testing/ai-impact-preview-projection-gate-fail.json');

        $exitCode = Artisan::call('career:ai-impact-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--dry-run' => true,
            '--output' => $report,
        ]);

        $this->assertSame(1, $exitCode);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $errors = implode(' ', $decoded['errors']);
        $this->assertStringContainsString('search_projection must remain in a separate candidate file', $errors);
        $this->assertStringContainsString('blocked AI outcome framing: predicts job loss', $errors);
    }

    public function test_importer_blocks_when_career_job_bundle_authority_is_missing(): void
    {
        $this->seedOccupation('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $this->assetRow('accountants-and-auditors', 'en'),
        ]);
        $report = storage_path('framework/testing/ai-impact-preview-authority-fail.json');

        $exitCode = Artisan::call('career:ai-impact-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--dry-run' => true,
            '--output' => $report,
        ]);

        $this->assertSame(1, $exitCode);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('fail', $decoded['decision']);
        $this->assertStringContainsString('missing_career_job_bundle_authority', implode(' ', $decoded['errors']));
        $this->assertSame(0, $decoded['career_job_bundle_authority']['ready_slug_count']);
    }

    public function test_preview_api_projects_reader_safe_payload_when_enabled(): void
    {
        Config::set('career_ai_impact_assets.staging_preview_enabled', true);
        Config::set('career_ai_impact_assets.preview_slugs', ['accountants-and-auditors']);
        $occupation = $this->seedOccupation('accountants-and-auditors');
        $row = $this->assetRow('accountants-and-auditors', 'en');
        $row['search_projection'] = ['candidate_only_not_runtime_seo' => true];
        $row['score_rationale']['source_ids'] = ['source_should_not_leak'];
        $row['score_rationale']['evidence_ids'] = ['evidence_should_not_leak'];

        CareerJobAiImpactAsset::query()->create([
            'occupation_id' => $occupation->id,
            'career_job_slug' => 'accountants-and-auditors',
            'locale' => 'en',
            'asset_version' => CareerJobAiImpactAsset::ASSET_VERSION_V5,
            'status' => CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
            'preview_allowlisted' => true,
            'asset_payload_json' => $row,
            'sources_json' => $row['sources'],
            'evidence_used_json' => $row['evidence_used'],
            'derived_from_synthesis_json' => $row['derived_from_synthesis'],
            'audit_fields_json' => $row['audit_fields'],
            'asset_row_hash' => $row['audit_fields']['row_hash'],
        ]);

        $response = $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/ai-impact-asset?locale=en')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('preview', true)
            ->assertJsonPath('ai_impact_asset_v1.slug', 'accountants-and-auditors')
            ->assertJsonPath('ai_impact_asset_v1.locale', 'en');

        $asset = $response->json('ai_impact_asset_v1');
        foreach (['audit_fields', 'evidence_used', 'derived_from_synthesis', 'search_projection'] as $internalKey) {
            $this->assertArrayNotHasKey($internalKey, $asset);
        }

        $this->assertArrayNotHasKey('source_id', $asset['sources'][0]);
        $this->assertArrayNotHasKey('used_for', $asset['sources'][0]);
        $this->assertArrayNotHasKey('source_ids', $asset['score_rationale']);
        $this->assertArrayNotHasKey('evidence_ids', $asset['score_rationale']);
        $this->assertSame('O*NET OnLine summary for Accountants and Auditors', $asset['sources'][0]['name']);
    }

    public function test_preview_api_fails_closed_when_disabled_or_not_allowlisted(): void
    {
        Config::set('career_ai_impact_assets.staging_preview_enabled', false);
        Config::set('career_ai_impact_assets.preview_slugs', ['accountants-and-auditors']);
        $occupation = $this->seedOccupation('accountants-and-auditors');
        $row = $this->assetRow('accountants-and-auditors', 'zh-CN');

        CareerJobAiImpactAsset::query()->create([
            'occupation_id' => $occupation->id,
            'career_job_slug' => 'accountants-and-auditors',
            'locale' => 'zh-CN',
            'asset_version' => CareerJobAiImpactAsset::ASSET_VERSION_V5,
            'status' => CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
            'preview_allowlisted' => true,
            'asset_payload_json' => $row,
            'sources_json' => $row['sources'],
            'evidence_used_json' => $row['evidence_used'],
            'derived_from_synthesis_json' => $row['derived_from_synthesis'],
            'audit_fields_json' => $row['audit_fields'],
            'asset_row_hash' => $row['audit_fields']['row_hash'],
        ]);

        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/ai-impact-asset?locale=zh-CN')
            ->assertNotFound();

        Config::set('career_ai_impact_assets.staging_preview_enabled', true);
        Config::set('career_ai_impact_assets.preview_slugs', ['actuaries']);
        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/ai-impact-asset?locale=zh-CN')
            ->assertNotFound();
    }

    private function seedCareerJobBundleAuthority(string $slug): void
    {
        $this->seedCareerJobBundleAuthorities([$slug]);
    }

    /**
     * @param  list<string>  $slugs
     */
    private function seedCareerJobBundleAuthorities(array $slugs): void
    {
        $this->seedRuntimeProjectionAuthority($slugs);

        foreach ($slugs as $slug) {
            if (! Occupation::query()->where('canonical_slug', $slug)->exists()) {
                $this->seedOccupation($slug);
            }

            app(PublicCareerAuthorityResponseCache::class)->forgetJobDetailPayload($slug, 'zh-CN');
            app(PublicCareerAuthorityResponseCache::class)->forgetJobDetailPayload($slug, 'en');
            app(PublicCareerAuthorityResponseCache::class)->warmJobDetailPayload($slug, 'zh-CN', true);
            app(PublicCareerAuthorityResponseCache::class)->warmJobDetailPayload($slug, 'en', true);
        }

        $this->app->forgetInstance(CareerAiImpactAssetImportService::class);
        $this->app->forgetInstance(CareerAiImpactAssetPreviewService::class);
        $this->app->forgetInstance('App\\Console\\Commands\\CareerImportAiImpactAssetsPreview');
    }

    /**
     * @param  list<string>  $slugs
     */
    private function seedRuntimeProjectionAuthority(array $slugs): void
    {
        $detailRouteEnabled = [];
        $robotsIndexable = [];
        $releaseGatePass = [];
        $items = [];

        foreach ($slugs as $slug) {
            $normalizedSlug = strtolower(trim($slug));
            if ($normalizedSlug === '') {
                continue;
            }

            $detailRouteEnabled[$normalizedSlug] = true;
            $robotsIndexable[$normalizedSlug] = true;
            $releaseGatePass[$normalizedSlug] = true;
            foreach (['en', 'zh', 'zh-CN'] as $locale) {
                $items[$normalizedSlug.'|'.$locale] = [
                    'slug' => $normalizedSlug,
                    'locale' => $locale,
                    'public_resolution_type' => 'public_canonical_job',
                    'dataset_visible' => true,
                    'search_visible' => true,
                    'detail_route_enabled' => true,
                    'robots_indexable' => true,
                    'release_gate_pass' => true,
                    'runtime_publish_state' => 'published',
                ];
            }
        }

        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(
                defaultDatasetVisible: false,
                defaultSearchVisible: false,
                defaultDetailRouteEnabled: false,
                defaultRobotsIndexable: false,
                defaultReleaseGatePass: false,
                detailRouteEnabled: $detailRouteEnabled,
                robotsIndexable: $robotsIndexable,
                releaseGatePass: $releaseGatePass,
                items: $items,
            ),
        );
    }

    private function seedOccupation(string $slug): Occupation
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'ai-impact-preview-'.$slug,
            'title_en' => 'AI Impact Preview',
            'title_zh' => 'AI 影响预览',
        ]);

        return Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => $slug,
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'CN',
            'crosswalk_mode' => 'direct_match',
            'canonical_title_en' => 'Accountants and Auditors',
            'canonical_title_zh' => '会计师和审计师',
            'search_h1_zh' => '会计师和审计师',
            'structural_stability' => 0.9,
            'task_prototype_signature' => ['analysis' => 0.8],
            'market_semantics_gap' => 0.1,
            'regulatory_divergence' => 0.1,
            'toolchain_divergence' => 0.1,
            'skill_gap_threshold' => 0.4,
            'trust_inheritance_scope' => ['allow_task_truth' => true],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeJsonl(array $rows): string
    {
        $path = storage_path('framework/testing/ai-impact-preview-'.bin2hex(random_bytes(4)).'.jsonl');
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, implode('', array_map(
            static fn (array $row): string => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
            $rows
        )));

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function assetRow(string $slug, string $locale): array
    {
        $hash = hash('sha256', 'ai-impact|'.$slug.'|'.$locale);
        $isZh = $locale === 'zh-CN';

        return [
            'ledger_type' => 'career-risk-future_asset',
            'asset_version' => 'career_risk_future_ai_impact_v5_batch_001',
            'block_type' => 'career-risk-future-ai-impact',
            'slug' => $slug,
            'locale' => $locale,
            'seed_ordinal' => 1,
            'batch_role' => 'new_50',
            'occupation' => [
                'title_en' => 'Accountants and Auditors',
                'title_zh' => '会计师和审计师',
                'soc_code' => '13-2011',
                'onet_code' => '13-2011.00',
            ],
            'micro_family' => 'audit and assurance workflow',
            'ai_exposure_score' => [
                'score_1_to_10' => 8,
                'exposure_type' => 'mixed',
                'confidence' => 'high',
            ],
            'summary' => $isZh
                ? 'FermatMind 将会计师和审计师评为 8/10 AI 任务暴露度：凭证核对、审计底稿初筛和差异汇总容易被加速，但签字责任、重大错报判断和客户沟通仍由人承担。'
                : 'FermatMind rates Accountants and Auditors at 8/10 AI task exposure: reconciliation, workpaper triage, and variance summaries can be accelerated, while sign-off accountability and materiality judgment remain human-owned.',
            'items' => [
                'most_ai_exposed_workflows' => [[
                    'label' => $isZh ? '审计底稿初筛' : 'Workpaper triage',
                    'body' => $isZh
                        ? 'AI 可把凭证、银行流水和底稿注释整理成可复核的异常清单，帮助审计人员先定位收入确认、费用截止和关联交易线索。'
                        : 'AI can organize invoices, bank activity, and workpaper notes into reviewable exception lists for revenue recognition, cutoff, and related-party follow-up.',
                ]],
                'human_accountability_anchors' => [[
                    'label' => $isZh ? '重大性和签字责任' : 'Materiality and sign-off',
                    'body' => $isZh
                        ? '是否构成重大错报、是否扩大抽样、如何向客户和合伙人解释风险，仍需要会计与审计人员承担专业判断。'
                        : 'Material misstatement calls, sample expansion, and explanations to clients or partners remain professional-accountability decisions.',
                ]],
                'how_to_prepare' => [[
                    'label' => $isZh ? '准备可展示的底稿复盘' : 'Build a workpaper review sample',
                    'body' => $isZh
                        ? '准备一份脱敏的审计底稿复盘，展示输入资料、AI 初筛、人工删改、最终判断和复核理由。'
                        : 'Create a sanitized workpaper review sample showing inputs, AI triage, human edits, final judgment, and review rationale.',
                ]],
                'reader_boundary' => [
                    'label' => $isZh ? 'AI 评分边界' : 'AI score boundary',
                    'body' => $isZh
                        ? '该分数是任务暴露信号，不是岗位消失、收入变化或个人结果预测。'
                        : 'This score is a task-exposure signal, not a prediction of job loss, wage change, or an individual outcome.',
                ],
            ],
            'score_rationale' => [
                'why_not_higher' => $isZh
                    ? '不是 9/10 或 10/10，因为审计意见、重大性判断、客户沟通和责任归属不能由模型端到端完成。'
                    : 'The score is not 9/10 or 10/10 because audit opinions, materiality calls, client communication, and accountability cannot be delegated end-to-end.',
                'why_not_lower' => $isZh
                    ? '不是低分，因为大量凭证核对、底稿摘要、差异说明和报告初稿都属于可被 AI 加速的信息处理任务。'
                    : 'The score is not lower because reconciliation, workpaper summaries, variance explanations, and report drafting are recurring information-processing tasks.',
                'confidence_reason' => $isZh
                    ? 'O*NET 任务、审计工作流和外部 AI 暴露研究共同支持高暴露但强责任边界的判断。'
                    : 'O*NET task evidence, audit workflows, and external AI-exposure research support high exposure with a strong accountability boundary.',
                'drivers' => ['reconciliation workflow', 'workpaper summarization', 'variance explanation'],
                'anchors' => ['materiality judgment', 'audit sign-off', 'client communication'],
                'source_ids' => ['onet_13_2011_00_summary'],
                'evidence_ids' => ['ai_impact_evidence_001'],
            ],
            'sources' => [[
                'source_id' => 'onet_13_2011_00_summary',
                'source_name' => 'O*NET OnLine summary for Accountants and Auditors',
                'source_type' => 'task_taxonomy',
                'source_url' => 'https://www.onetonline.org/link/summary/13-2011.00',
                'used_for' => 'Occupation-specific task and workflow evidence.',
                'captured_fact' => 'Accountants and auditors prepare, examine, and analyze accounting records and financial statements.',
                'boundary' => 'Task taxonomy only; not a personal outcome prediction.',
            ]],
            'evidence_used' => [
                'evidence_row_hash' => str_repeat('a', 64),
                'source_ids' => ['onet_13_2011_00_summary'],
                'workflow_evidence_ids' => ['ai_impact_evidence_001'],
            ],
            'derived_from_synthesis' => [
                'synthesis_row_hash' => str_repeat('b', 64),
            ],
            'audit_fields' => [
                'generated_at' => '2026-06-19T00:00:00Z',
                'row_hash' => $hash,
            ],
        ];
    }
}
