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
        'actuaries',
        'architectural-and-engineering-managers',
        'biomedical-engineers',
        'civil-engineers',
        'dentists',
        'financial-analysts',
        'high-school-teachers',
        'market-research-analysts',
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
        $this->assertSame(8, $report['lineage_backfilled_count']);
        $this->assertSame(4, $report['lineage_hold_count']);
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
        $this->assertSame(8, $report['lineage_backfilled_count']);
        $this->assertSame(4, $report['lineage_hold_count']);
        $this->assertFileExists($backupPath);
        $this->assertCount(12, json_decode((string) file_get_contents($backupPath), true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame($beforeCounts, $this->counts());

        $actors = $this->asset('actors');
        $this->assertIsArray(data_get($actors->page_payload_json, 'page.en'));
        $this->assertIsArray(data_get($actors->page_payload_json, 'page.zh'));
        $this->assertArrayNotHasKey('en', $actors->page_payload_json);
        $this->assertArrayNotHasKey('zh', $actors->page_payload_json);
        $this->assertNull(data_get($actors->metadata_json, 'row_fingerprint'));

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

        foreach (['actors', 'accountants-and-auditors', 'data-scientists', 'registered-nurses'] as $slug) {
            $this->assertNull(data_get($this->asset($slug)->metadata_json, 'row_fingerprint'));
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
                'structured_data_json' => ['faq_page' => ['en' => ['mainEntity' => []], 'zh' => ['mainEntity' => []]]],
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
            '--json' => true,
        ], $options));

        return [$exitCode, json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)];
    }

    private function allSlugs(): string
    {
        return implode(',', self::AFFECTED_SLUGS);
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
