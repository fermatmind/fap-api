<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerNormalizeLegacyDisplayAssetsCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const AFFECTED_SLUGS = [
        'actors',
        'accountants-and-auditors',
        'actuaries',
        'architectural-and-engineering-managers',
        'biomedical-engineers',
        'civil-engineers',
        'data-scientists',
        'dentists',
        'financial-analysts',
        'high-school-teachers',
        'market-research-analysts',
        'registered-nurses',
    ];

    /** @var list<string> */
    private const RELEASE_GATE_SLUGS = [
        'accountants-and-auditors',
        'actuaries',
        'architectural-and-engineering-managers',
        'biomedical-engineers',
        'civil-engineers',
        'data-scientists',
        'dentists',
        'financial-analysts',
        'high-school-teachers',
        'market-research-analysts',
        'registered-nurses',
    ];

    /** @var list<string> */
    private const LINEAGE_SAFE_SLUGS = [
        'accountants-and-auditors',
        'actors',
        'actuaries',
        'architectural-and-engineering-managers',
        'biomedical-engineers',
        'civil-engineers',
        'data-scientists',
        'dentists',
        'financial-analysts',
        'high-school-teachers',
        'market-research-analysts',
        'registered-nurses',
    ];

    #[Test]
    public function dry_run_reports_legacy_normalization_without_writing(): void
    {
        $this->seedLegacyAssets();
        $before = $this->snapshotAssets();

        [$exitCode, $report] = $this->runNormalize($this->allSlugs(), ['--dry-run' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('pass', $report['decision']);
        $this->assertSame('dry_run', $report['mode']);
        $this->assertTrue($report['read_only']);
        $this->assertFalse($report['writes_database']);
        $this->assertFalse($report['did_write']);
        $this->assertSame(12, $report['validated_count']);
        $this->assertSame(12, $report['would_update_count']);
        $this->assertSame(1, $report['actor_shape_normalized_count']);
        $this->assertSame(22, $report['release_gates_removed_count']);
        $this->assertSame(12, $report['lineage_backfilled_count']);
        $this->assertSame(0, $report['lineage_hold_count']);
        $this->assertSame(1, $report['Product_schema_removed_count']);
        $this->assertFalse($report['release_gates_changed']);
        $this->assertSame($before, $this->snapshotAssets());
    }

    #[Test]
    public function force_normalizes_only_allowed_json_fields_and_is_idempotent(): void
    {
        $this->seedLegacyAssets();
        $beforeCounts = $this->counts();
        $beforeGuard = $this->guardSnapshot('actuaries');
        $backupPath = tempnam(sys_get_temp_dir(), 'd14b-backup-');
        $this->assertIsString($backupPath);

        [$exitCode, $report] = $this->runNormalize($this->allSlugs(), [
            '--force' => true,
            '--backup-path' => $backupPath,
        ]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('pass', $report['decision']);
        $this->assertSame('force', $report['mode']);
        $this->assertFalse($report['read_only']);
        $this->assertTrue($report['writes_database']);
        $this->assertTrue($report['did_write']);
        $this->assertSame(12, $report['updated_count']);
        $this->assertSame(1, $report['actor_shape_normalized_count']);
        $this->assertSame(22, $report['release_gates_removed_count']);
        $this->assertSame(12, $report['lineage_backfilled_count']);
        $this->assertSame(0, $report['lineage_hold_count']);
        $this->assertSame(1, $report['Product_schema_removed_count']);
        $this->assertFileExists($backupPath);
        $this->assertCount(12, json_decode((string) file_get_contents($backupPath), true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame($beforeCounts, $this->counts());

        $actors = $this->asset('actors');
        $this->assertIsArray(data_get($actors->page_payload_json, 'page.en'));
        $this->assertIsArray(data_get($actors->page_payload_json, 'page.zh'));
        $this->assertArrayNotHasKey('en', $actors->page_payload_json);
        $this->assertArrayNotHasKey('zh', $actors->page_payload_json);
        $this->assertIsString(data_get($actors->metadata_json, 'row_fingerprint'));
        $this->assertIsString(data_get($actors->metadata_json, 'workbook_sha256'));
        $this->assertSame(basename($this->lineageWorkbook()), data_get($actors->metadata_json, 'workbook_basename'));
        $this->assertSame(3, data_get($actors->metadata_json, 'row_number'));
        $this->assertSame('career_legacy_display_asset_normalizer_v0.1', data_get($actors->metadata_json, 'mapper_version'));
        $this->assertStringNotContainsString('"@type":"Product"', json_encode($actors->structured_data_json, JSON_THROW_ON_ERROR));

        foreach (self::RELEASE_GATE_SLUGS as $slug) {
            $asset = $this->asset($slug);
            $this->assertNull(data_get($asset->page_payload_json, 'page.en.boundary_notice.release_gates'));
            $this->assertNull(data_get($asset->page_payload_json, 'page.zh.boundary_notice.release_gates'));
            $this->assertSame('Visible EN boundary copy.', data_get($asset->page_payload_json, 'page.en.boundary_notice.copy'));
            $this->assertSame('可见中文边界提示。', data_get($asset->page_payload_json, 'page.zh.boundary_notice.copy'));
            $this->assertSame(false, data_get($asset->metadata_json, 'release_gates.sitemap'));
            $encodedPublicPayload = json_encode([
                $asset->page_payload_json,
                $asset->structured_data_json,
                $asset->implementation_contract_json,
            ], JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('release_gates', $encodedPublicPayload);
            $this->assertStringNotContainsString('"@type":"Product"', $encodedPublicPayload);
        }

        foreach (self::LINEAGE_SAFE_SLUGS as $slug) {
            $this->assertIsString(data_get($this->asset($slug)->metadata_json, 'row_fingerprint'));
        }

        $this->assertSame($beforeGuard, $this->guardSnapshot('actuaries'));

        [$exitCode, $report] = $this->runNormalize($this->allSlugs(), ['--dry-run' => true]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('pass', $report['decision']);
        $this->assertSame(0, $report['would_update_count']);
        $this->assertSame(12, $report['skipped_count']);
        $this->assertFalse($report['did_write']);
        $this->assertSame($beforeCounts, $this->counts());
    }

    #[Test]
    public function force_adds_missing_zh_module_placeholders_only_for_report_authorized_subset_slugs(): void
    {
        $slug = 'module-subset-career';
        $missingModules = [
            'career_snapshot_primary_locale',
            'career_snapshot_secondary_locale',
            'personality_fit_block',
            'responsibilities_block',
            'work_context_block',
            'market_signal_card',
            'adjacent_career_comparison_table',
            'ai_impact_table',
            'career_risk_cards',
            'contract_project_risk_block',
            'related_next_pages',
            'source_card',
            'review_validity_card',
        ];
        $this->createModuleSubsetAsset($slug, $missingModules);

        [$exitCode, $report] = $this->runNormalize($slug, [
            '--force' => true,
            '--module-subset-report' => $this->moduleSubsetReport([$slug]),
        ]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('pass', $report['decision']);
        $this->assertSame(1, $report['validated_count']);
        $this->assertSame(1, $report['updated_count']);
        $this->assertSame(1, $report['module_subset_authorized_count']);
        $this->assertSame(count($missingModules), $report['module_placeholders_added_count']);

        $asset = $this->asset($slug);
        foreach ($this->componentOrder() as $moduleKey) {
            $this->assertIsArray(data_get($asset->page_payload_json, "page.zh.{$moduleKey}"));
        }
        foreach ($missingModules as $moduleKey) {
            $this->assertSame('pending_reviewed_zh_content', data_get($asset->page_payload_json, "page.zh.{$moduleKey}.module_state"));
            $this->assertFalse(data_get($asset->page_payload_json, "page.zh.{$moduleKey}.content_available"));
            $this->assertSame('no_translated_editorial_copy_generated', data_get($asset->page_payload_json, "page.zh.{$moduleKey}.placeholder_policy"));
        }

        [$exitCode, $report] = $this->runNormalize($slug, [
            '--dry-run' => true,
            '--module-subset-report' => $this->moduleSubsetReport([$slug]),
        ]);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $report['would_update_count']);
        $this->assertSame(0, $report['module_placeholders_added_count']);
    }

    #[Test]
    public function it_can_derive_module_subset_slugs_from_root_cause_manifest_without_explicit_slugs(): void
    {
        $first = 'module-subset-a';
        $second = 'module-subset-b';
        $missingModules = ['source_card', 'review_validity_card'];
        $this->createModuleSubsetAsset($first, $missingModules);
        $this->createModuleSubsetAsset($second, ['source_card']);

        $exitCode = Artisan::call('career:normalize-legacy-display-assets', [
            '--lineage-workbook' => $this->lineageWorkbook(),
            '--module-subset-report' => $this->moduleSubsetRootCauseReport([$second, $first]),
            '--dry-run' => true,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame('pass', $report['decision']);
        $this->assertSame('module_subset_report', $report['requested_slug_source']);
        $this->assertSame([$first, $second], $report['requested_slugs']);
        $this->assertSame(2, $report['module_subset_authorized_count']);
        $this->assertSame(2, $report['validated_count']);
        $this->assertSame(2, $report['would_update_count']);
        $this->assertSame(3, $report['module_placeholders_added_count']);
    }

    #[Test]
    public function it_rejects_manual_hold_and_outside_slugs(): void
    {
        [$exitCode, $report] = $this->runNormalize('software-developers', ['--dry-run' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $report['decision']);
        $this->assertStringContainsString('Unsupported slug(s)', implode(' ', $report['errors']));
        $this->assertFalse($report['did_write']);

        [$exitCode, $report] = $this->runNormalize('animal-caretakers', ['--dry-run' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $report['decision']);
        $this->assertStringContainsString('Unsupported slug(s)', implode(' ', $report['errors']));

        $this->createOccupation('outside-module-subset');
        [$exitCode, $report] = $this->runNormalize('outside-module-subset', [
            '--dry-run' => true,
            '--module-subset-report' => $this->moduleSubsetReport([]),
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $report['decision']);
        $this->assertStringContainsString('Unsupported slug(s)', implode(' ', $report['errors']));
    }

    #[Test]
    public function it_rejects_truthy_release_gates_without_writing(): void
    {
        $this->seedLegacyAssets(['actuaries' => ['sitemap' => true]]);
        $before = $this->snapshotAssets();

        [$exitCode, $report] = $this->runNormalize('actuaries', ['--force' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('fail', $report['decision']);
        $this->assertStringContainsString('contains non-false values', implode(' ', $report['errors']));
        $this->assertFalse($report['did_write']);
        $this->assertSame($before, $this->snapshotAssets());
    }

    /**
     * @param  array<string, array<string, bool>>  $releaseGateOverrides
     */
    private function seedLegacyAssets(array $releaseGateOverrides = []): void
    {
        foreach (self::AFFECTED_SLUGS as $slug) {
            $occupation = $this->createOccupation($slug);
            CareerJobDisplayAsset::query()->create([
                'occupation_id' => $occupation->id,
                'canonical_slug' => $slug,
                'surface_version' => 'display.surface.v1',
                'asset_version' => 'v4.2',
                'template_version' => 'v4.2',
                'asset_type' => 'career_job_public_display',
                'asset_role' => 'formal_pilot_master',
                'status' => 'ready_for_pilot',
                'component_order_json' => $this->componentOrder(),
                'page_payload_json' => $slug === 'actors'
                    ? $this->legacyActorsPage()
                    : $this->legacyReleaseGatePage($releaseGateOverrides[$slug] ?? []),
                'seo_payload_json' => ['en' => ['title' => $slug], 'zh' => ['title' => $slug]],
                'sources_json' => ['references' => [['label' => 'Official source']]],
                'structured_data_json' => $slug === 'actors'
                    ? ['@graph' => [
                        ['@type' => 'Occupation', 'name' => 'Actors'],
                        ['@type' => 'Product', 'name' => 'Legacy actors product schema'],
                    ]]
                    : ['faq_page' => ['en' => ['mainEntity' => []], 'zh' => ['mainEntity' => []]]],
                'implementation_contract_json' => ['claim_permissions' => ['visible' => true]],
                'metadata_json' => [
                    'command' => 'legacy-import',
                    'release_gates' => [
                        'sitemap' => false,
                        'llms' => false,
                        'paid' => false,
                        'backlink' => false,
                    ],
                ],
            ]);
        }
    }

    private function createOccupation(string $slug): Occupation
    {
        $family = OccupationFamily::query()->firstOrCreate(
            ['canonical_slug' => 'legacy-family'],
            ['title_en' => 'Legacy Family', 'title_zh' => '旧版职业族'],
        );

        return Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => $slug,
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'CN',
            'crosswalk_mode' => 'direct_match',
            'canonical_title_en' => str_replace('-', ' ', $slug),
            'canonical_title_zh' => str_replace('-', ' ', $slug),
            'search_h1_zh' => str_replace('-', ' ', $slug),
        ]);
    }

    /**
     * @return list<string>
     */
    private function componentOrder(): array
    {
        return [
            'breadcrumb',
            'hero',
            'fermat_decision_card',
            'primary_cta',
            'career_snapshot_primary_locale',
            'career_snapshot_secondary_locale',
            'fit_decision_checklist',
            'riasec_fit_block',
            'personality_fit_block',
            'definition_block',
            'responsibilities_block',
            'work_context_block',
            'market_signal_card',
            'adjacent_career_comparison_table',
            'ai_impact_table',
            'career_risk_cards',
            'contract_project_risk_block',
            'next_steps_block',
            'faq_block',
            'related_next_pages',
            'source_card',
            'review_validity_card',
            'boundary_notice',
            'final_cta',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyActorsPage(): array
    {
        return [
            'en' => ['hero' => ['title' => 'Actors'], 'boundary_notice' => ['copy' => 'Visible EN boundary copy.']],
            'zh' => ['hero' => ['title' => '演员'], 'boundary_notice' => ['copy' => '可见中文边界提示。']],
        ];
    }

    /**
     * @param  array<string, bool>  $overrides
     * @return array<string, mixed>
     */
    private function legacyReleaseGatePage(array $overrides = []): array
    {
        $releaseGates = array_merge([
            'sitemap' => false,
            'llms' => false,
            'paid' => false,
            'backlink' => false,
        ], $overrides);

        return [
            'page' => [
                'en' => [
                    'hero' => ['title' => 'Legacy career'],
                    'boundary_notice' => [
                        'copy' => 'Visible EN boundary copy.',
                        'release_gates' => $releaseGates,
                    ],
                ],
                'zh' => [
                    'hero' => ['title' => '旧版职业'],
                    'boundary_notice' => [
                        'copy' => '可见中文边界提示。',
                        'release_gates' => $releaseGates,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{int, array<string, mixed>}
     */
    private function runNormalize(string $slugs, array $options = []): array
    {
        $exitCode = Artisan::call('career:normalize-legacy-display-assets', array_merge([
            '--slugs' => $slugs,
            '--lineage-workbook' => $this->lineageWorkbook(),
            '--json' => true,
        ], $options));

        return [$exitCode, json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)];
    }

    private function lineageWorkbook(): string
    {
        $path = sys_get_temp_dir().'/career-normalize-lineage-workbook.xlsx';
        if (! is_file($path)) {
            file_put_contents($path, 'lineage workbook fixture');
        }

        return $path;
    }

    private function allSlugs(): string
    {
        return implode(',', self::AFFECTED_SLUGS);
    }

    /**
     * @param  list<string>  $slugs
     */
    private function moduleSubsetReport(array $slugs): string
    {
        $path = tempnam(sys_get_temp_dir(), 'career-module-subset-report-');
        $this->assertIsString($path);
        file_put_contents($path, json_encode([
            'validator_version' => 'career_zh_display_parity_audit_v0.3',
            'items' => array_map(static fn (string $slug): array => [
                'slug' => $slug,
                'root_cause' => 'zh_display_asset_present_but_module_subset',
            ], $slugs),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /**
     * @param  list<string>  $slugs
     */
    private function moduleSubsetRootCauseReport(array $slugs): string
    {
        $path = tempnam(sys_get_temp_dir(), 'career-module-subset-root-cause-report-');
        $this->assertIsString($path);
        file_put_contents($path, json_encode([
            'validator_version' => 'career_zh_display_parity_audit_v0.4',
            'root_cause_manifest' => [
                'buckets' => [
                    'zh_display_asset_present_but_module_subset' => array_map(static fn (string $slug): array => [
                        'slug' => $slug,
                        'root_cause' => 'zh_display_asset_present_but_module_subset',
                    ], $slugs),
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /**
     * @param  list<string>  $missingModules
     */
    private function createModuleSubsetAsset(string $slug, array $missingModules): void
    {
        $occupation = $this->createOccupation($slug);
        $zhContent = array_diff_key(
            $this->pageContentForComponentOrder('中文内容'),
            array_flip($missingModules),
        );

        CareerJobDisplayAsset::query()->create([
            'occupation_id' => $occupation->id,
            'canonical_slug' => $slug,
            'surface_version' => 'display.surface.v1',
            'asset_version' => 'v4.2',
            'template_version' => 'v4.2',
            'asset_type' => 'career_job_public_display',
            'asset_role' => 'formal_pilot_master',
            'status' => 'ready_for_pilot',
            'component_order_json' => $this->componentOrder(),
            'page_payload_json' => [
                'page' => [
                    'en' => $this->pageContentForComponentOrder('English content'),
                    'zh' => $zhContent,
                ],
            ],
            'seo_payload_json' => ['en' => ['title' => $slug], 'zh' => ['title' => $slug]],
            'sources_json' => ['references' => [['label' => 'Official source']]],
            'structured_data_json' => ['faq_page' => ['en' => ['mainEntity' => []], 'zh' => ['mainEntity' => []]]],
            'implementation_contract_json' => ['claim_permissions' => ['visible' => true]],
            'metadata_json' => ['command' => 'legacy-import'],
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function pageContentForComponentOrder(string $prefix): array
    {
        return collect($this->componentOrder())
            ->mapWithKeys(static fn (string $moduleKey): array => [
                $moduleKey => ['body' => "{$prefix}: {$moduleKey}"],
            ])
            ->all();
    }

    private function asset(string $slug): CareerJobDisplayAsset
    {
        return CareerJobDisplayAsset::query()
            ->where('canonical_slug', $slug)
            ->where('asset_version', 'v4.2')
            ->firstOrFail()
            ->refresh();
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'occupations' => Occupation::query()->count(),
            'career_job_display_assets' => CareerJobDisplayAsset::query()->count(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function snapshotAssets(): array
    {
        return CareerJobDisplayAsset::query()
            ->orderBy('canonical_slug')
            ->get()
            ->mapWithKeys(fn (CareerJobDisplayAsset $asset): array => [
                $asset->canonical_slug => hash('sha256', json_encode([
                    $asset->page_payload_json,
                    $asset->metadata_json,
                    $asset->structured_data_json,
                ], JSON_THROW_ON_ERROR)),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function guardSnapshot(string $slug): array
    {
        $asset = $this->asset($slug);

        return [
            'component_order_json' => $asset->component_order_json,
            'seo_payload_json' => $asset->seo_payload_json,
            'sources_json' => $asset->sources_json,
            'structured_data_json' => $asset->structured_data_json,
            'implementation_contract_json' => $asset->implementation_contract_json,
            'surface_version' => $asset->surface_version,
            'asset_version' => $asset->asset_version,
            'template_version' => $asset->template_version,
            'asset_type' => $asset->asset_type,
            'asset_role' => $asset->asset_role,
            'status' => $asset->status,
        ];
    }
}
