<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BigFiveEnglishResultAssetPackageTest extends TestCase
{
    private const PACKAGE = 'content_packs/BIG5_OCEAN/v2/packages/en_parity/w2_result_content_v1';

    private const UNITS = [
        'free_preview',
        'locked_result',
        'paid_full_report',
        'entitlement_levels',
        'five_dimension_explanations',
        'facet_subscale_explanations',
        'score_range_boundary_copy',
        'action_growth_advice',
        'workplace_relationship_copy',
        'share_public_summary',
        'pdf_reader_content',
        'history_account_reentry',
        'result_report_cta',
        'empty_error_expired_access_denied',
        'mobile_desktop_consumption',
        'analytics_reader_labels',
    ];

    #[Test]
    public function it_covers_the_frozen_inventory_exactly_once(): void
    {
        $manifest = $this->loadJson('package_manifest.json');
        $assets = $this->assets();
        $ledger = $this->loadJson('source_ledger.json');
        $translationMap = $this->loadJson('translation_map.json');
        $coverage = $this->loadJson('surface_coverage.json');

        $this->assertSame(self::UNITS, $manifest['inventory_units']);
        $this->assertSame(16, $manifest['inventory_unit_count']);
        $this->assertCount(16, $assets);
        $this->assertSame(self::UNITS, array_column($assets, 'unit'));
        $this->assertCount(16, array_unique(array_column($assets, 'unit')));
        $this->assertCount(16, array_unique(array_column($assets, 'asset_id')));
        $this->assertSame(self::UNITS, array_column($ledger['entries'], 'unit'));
        $this->assertSame(self::UNITS, array_column($translationMap['mappings'], 'unit'));
        $this->assertSame(16, $coverage['covered_unit_count']);
        $this->assertSame([], $coverage['missing_units']);
        $this->assertSame([], $coverage['duplicate_units']);
    }

    #[Test]
    public function it_reconciles_all_118_frozen_source_rows_and_required_result_keys(): void
    {
        $manifest = $this->loadJson('package_manifest.json');
        $ledger = $this->loadJson('source_ledger.json');
        $reconciliation = $this->loadJson('inventory_row_reconciliation.json');
        $assetsByUnit = array_column($this->assets(), null, 'unit');
        $expectedCounts = [
            'public_profile_control' => 52,
            'english_historical_revision_verification' => 50,
            'result_content' => 16,
        ];

        $this->assertSame(118, $manifest['source_inventory']['row_count']);
        $this->assertSame($expectedCounts, $manifest['source_inventory']['cohort_counts']);
        $this->assertSame('inventory_row_reconciliation.json', $manifest['source_inventory']['row_reconciliation_file']);
        $this->assertSame(118, $ledger['inventory_row_reconciliation']['row_count']);
        $this->assertSame($expectedCounts, $ledger['inventory_row_reconciliation']['cohort_counts']);
        $this->assertSame(52, count($reconciliation['public_profile_control_rows']));
        $this->assertSame(50, count($reconciliation['english_historical_revision_rows']));
        $this->assertSame(16, count($reconciliation['result_content_rows']));

        $sourceRowIds = [
            ...$reconciliation['public_profile_control_rows'],
            ...$reconciliation['english_historical_revision_rows'],
            ...array_column($reconciliation['result_content_rows'], 'row_id'),
        ];
        $this->assertCount(118, $sourceRowIds);
        $this->assertCount(118, array_unique($sourceRowIds));
        $this->assertSame(
            '347bd92a3db93d873b44e27c88327e860ee5915e9e7eafb9adb2227e75fef8b7',
            hash('sha256', implode("\n", $sourceRowIds)."\n")
        );

        foreach ($reconciliation['result_content_rows'] as $index => $row) {
            $unit = self::UNITS[$index];
            $asset = $assetsByUnit[$unit];

            $this->assertSame('W2-RESULT-'.$unit, $row['row_id']);
            $this->assertSame($unit, $row['unit']);
            $this->assertSame('big5.result_content.'.$unit, $row['stable_asset_identity']);
            $this->assertSame('big5-result:'.$unit.':zh-CN:en', $row['translation_identity']);
            $this->assertSame($asset['asset_id'], $row['asset_id']);

            foreach ($row['required_content_keys'] as $key) {
                $this->assertArrayHasKey($key, $asset['content'], "{$unit}.{$key}");
            }

            foreach ($row['required_item_identities'] ?? [] as $path => $expectedIdentities) {
                if (str_contains($path, '.')) {
                    [$collection, $identityKey] = explode('.', $path, 2);
                    $actualIdentities = array_column($asset['content'][$collection], $identityKey);
                } else {
                    $actualIdentities = $asset['content'][$path];
                }
                $this->assertSame($expectedIdentities, $actualIdentities, "{$unit}.{$path}");
            }
        }
    }

    #[Test]
    public function it_keeps_every_asset_draft_only_and_every_permission_false(): void
    {
        $manifest = $this->loadJson('package_manifest.json');

        foreach ($this->assets() as $asset) {
            $this->assertSame('en', $asset['locale']);
            $this->assertSame('pending_manual_review', $asset['status']);
            $this->assertSame('draft_review_only', $asset['runtime_use']);
            $this->assertFalse($asset['ready_for_runtime']);
            $this->assertFalse($asset['ready_for_production']);
            $this->assertFalse($asset['production_use_allowed']);
        }

        $this->assertSame('pending_manual_review', $manifest['status']);
        $this->assertSame('draft_review_only', $manifest['runtime_use']);
        $this->assertFalse($manifest['ready_for_runtime']);
        $this->assertFalse($manifest['ready_for_production']);
        $this->assertFalse($manifest['production_use_allowed']);
        $this->assertFalse($manifest['human_reviewed']);
        $this->assertFalse($manifest['editorial_approved']);
        $this->assertFalse($manifest['qa_pass']);
        $this->assertFalse($manifest['package_frozen']);
        foreach ($manifest['permissions'] as $allowed) {
            $this->assertFalse($allowed);
        }
    }

    #[Test]
    public function it_blocks_cjk_private_fields_public_urls_and_forbidden_claims(): void
    {
        $encoded = json_encode($this->assets(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertDoesNotMatchRegularExpression('/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $encoded);
        $this->assertDoesNotMatchRegularExpression('/https?:\/\/\S+/i', $encoded);

        foreach ([
            'guaranteed career',
            'perfect career',
            'hiring fit',
            'admission decision',
            'clinical diagnosis',
            'treatment plan',
            'intelligence score',
            'moral ranking',
            'salary guarantee',
            'success guarantee',
            'relationship guarantee',
        ] as $claim) {
            $this->assertStringNotContainsString($claim, strtolower($encoded));
        }

        foreach (['raw_score', 'score_vector', 'percentile', 'attempt_id', 'report_token', 'private_url'] as $privateField) {
            $this->assertStringNotContainsString('"'.$privateField.'":', $encoded);
        }
    }

    #[Test]
    public function it_enforces_share_private_reader_entitlement_and_failure_boundaries(): void
    {
        $byUnit = [];
        foreach ($this->assets() as $asset) {
            $byUnit[$asset['unit']] = $asset['content'];
        }

        $this->assertSame(
            ['title', 'summary', 'privacy_note'],
            $byUnit['share_public_summary']['allowed_fields']
        );
        $this->assertFalse($byUnit['pdf_reader_content']['public_url_allowed']);
        $this->assertFalse($byUnit['pdf_reader_content']['indexable']);
        $this->assertCount(3, $byUnit['entitlement_levels']['levels']);
        $this->assertSame(
            ['empty', 'error', 'expired', 'access_denied'],
            array_column($byUnit['empty_error_expired_access_denied']['states'], 'key')
        );
        $this->assertTrue($byUnit['empty_error_expired_access_denied']['fail_closed']);
        $this->assertSame([], $byUnit['analytics_reader_labels']['internal_metric_ids']);
        $this->assertSame([], $byUnit['analytics_reader_labels']['personal_result_fields']);
        $this->assertCount(5, $byUnit['five_dimension_explanations']['dimensions']);
        $this->assertCount(30, $byUnit['facet_subscale_explanations']['facets']);
        $o3 = array_values(array_filter(
            $byUnit['facet_subscale_explanations']['facets'],
            static fn (array $facet): bool => $facet['code'] === 'O3'
        ))[0];
        $this->assertSame('Emotional Receptivity', $o3['label']);
        $this->assertStringContainsString('identify, approach, and express inner feelings', $o3['description']);
        $this->assertStringContainsString('use them as information', $o3['description']);
        $c5 = array_values(array_filter(
            $byUnit['facet_subscale_explanations']['facets'],
            static fn (array $facet): bool => $facet['code'] === 'C5'
        ))[0];
        $this->assertSame('Delayed-Feedback Persistence', $c5['label']);
        $this->assertStringContainsString('keep making progress', $c5['description']);
        $this->assertStringContainsString('feedback or reward is not immediate', $c5['description']);
        $a4 = array_values(array_filter(
            $byUnit['facet_subscale_explanations']['facets'],
            static fn (array $facet): bool => $facet['code'] === 'A4'
        ))[0];
        $this->assertSame('Conflict Mediation', $a4['label']);
        $this->assertStringContainsString('maintain the relationship', $a4['description']);
        $this->assertStringContainsString('yield when useful', $a4['description']);
        $this->assertStringContainsString('prevent escalation', $a4['description']);
        $n2 = array_values(array_filter(
            $byUnit['facet_subscale_explanations']['facets'],
            static fn (array $facet): bool => $facet['code'] === 'N2'
        ))[0];
        $this->assertSame('Anger Activation', $n2['label']);
        $this->assertStringContainsString('internal anger or resistance', $n2['description']);
        $this->assertStringContainsString('obstructed, misunderstood', $n2['description']);
        $this->assertStringContainsString('boundary is violated', $n2['description']);
        $n3 = array_values(array_filter(
            $byUnit['facet_subscale_explanations']['facets'],
            static fn (array $facet): bool => $facet['code'] === 'N3'
        ))[0];
        $this->assertSame('Sustained-Stress Depletion', $n3['label']);
        $this->assertStringContainsString('under sustained pressure', $n3['description']);
        $this->assertStringContainsString('drained, fatigued', $n3['description']);
        $this->assertStringContainsString('energy needed to keep responding', $n3['description']);
        $n5 = array_values(array_filter(
            $byUnit['facet_subscale_explanations']['facets'],
            static fn (array $facet): bool => $facet['code'] === 'N5'
        ))[0];
        $this->assertSame('Overload Exit Urge', $n5['label']);
        $this->assertStringContainsString('when overloaded', $n5['description']);
        $this->assertStringContainsString('disconnect, withdraw, or change the situation immediately', $n5['description']);
        $n6 = array_values(array_filter(
            $byUnit['facet_subscale_explanations']['facets'],
            static fn (array $facet): bool => $facet['code'] === 'N6'
        ))[0];
        $this->assertSame('Coping-Limit Vulnerability', $n6['label']);
        $this->assertStringContainsString('visible coping limits', $n6['description']);
        $this->assertStringContainsString('high pressure, loss of control, or complex demands', $n6['description']);
    }

    #[Test]
    public function it_has_a_deterministic_byte_exact_sha_manifest(): void
    {
        $shaManifest = $this->loadJson('sha256_manifest.json');
        $expectedPackageFiles = [
            ...array_column($shaManifest['files'], 'path'),
            'sha256_manifest.json',
        ];
        $actualPackageFiles = array_map(
            'basename',
            glob($this->path('*')) ?: []
        );
        sort($expectedPackageFiles);
        sort($actualPackageFiles);
        $this->assertSame($expectedPackageFiles, $actualPackageFiles);

        $canonical = [];

        foreach ($shaManifest['files'] as $file) {
            $actual = hash_file('sha256', $this->path($file['path']));
            $this->assertSame($file['sha256'], $actual, $file['path']);
            $canonical[] = $file['path'].':'.$actual;
        }

        $this->assertSame(
            $shaManifest['package_sha256'],
            hash('sha256', implode("\n", $canonical))
        );
        $this->assertSame(
            '47d43575aea0c638a21b90c33e1f0c147e4f679986d76db182c0f28606cf4dd4',
            $shaManifest['package_sha256']
        );
    }

    #[Test]
    public function it_pins_unchanged_zh_registry_and_runtime_selector_baselines(): void
    {
        $manifest = $this->loadJson('package_manifest.json');
        $commit = $manifest['source_baselines']['fap_api_commit'];

        $this->assertSame(
            '0c0c42c20ee1a78164a91093d69c7d616edfa8a7',
            $manifest['source_baselines']['zh_cn_registry_git_tree_oid']
        );
        $this->assertSame(
            'bcf1cdb47da2596e252e4940ccc6cac368218e5f',
            $manifest['source_baselines']['result_page_v2_service_git_tree_oid']
        );
        if ($this->gitCommitIsAvailable($commit)) {
            $this->assertSame(
                $manifest['source_baselines']['zh_cn_registry_git_tree_oid'],
                $this->gitTreeOid($commit, 'backend/content_packs/BIG5_OCEAN/v2/registry')
            );
            $this->assertSame(
                $manifest['source_baselines']['result_page_v2_service_git_tree_oid'],
                $this->gitTreeOid($commit, 'backend/app/Services/BigFive/ResultPageV2')
            );
        }
        $this->assertFalse($manifest['authority']['existing_zh_cn_registry_changed']);
        $this->assertFalse($manifest['authority']['runtime_selector_changed']);
        $this->assertFalse($manifest['authority']['importer_changed']);
        $this->assertFalse($manifest['authority']['schema_changed']);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJson(string $file): array
    {
        return json_decode(
            (string) file_get_contents($this->path($file)),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assets(): array
    {
        $assets = [];
        foreach (file($this->path('content_assets.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $asset = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($asset);
            $assets[] = $asset;
        }

        return $assets;
    }

    private function path(string $file): string
    {
        return base_path(self::PACKAGE.'/'.$file);
    }

    private function gitCommitIsAvailable(string $commit): bool
    {
        exec(
            'git -C '.escapeshellarg(dirname(base_path())).
            ' cat-file -e '.escapeshellarg($commit.'^{commit}').' 2>/dev/null',
            $output,
            $status
        );

        return $status === 0;
    }

    private function gitTreeOid(string $commit, string $path): string
    {
        $command = 'git -C '.escapeshellarg(dirname(base_path())).
            ' rev-parse '.escapeshellarg($commit.':'.$path);
        $value = shell_exec($command);

        $this->assertNotNull($value);

        return trim((string) $value);
    }
}
