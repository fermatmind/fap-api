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
    }

    #[Test]
    public function it_has_a_deterministic_byte_exact_sha_manifest(): void
    {
        $shaManifest = $this->loadJson('sha256_manifest.json');
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
            'cda743f52cebaf753b8d0f2f234dd893e88f5d630ea6cc4e7a5f5b32faefda06',
            $shaManifest['package_sha256']
        );
    }

    #[Test]
    public function it_pins_unchanged_zh_registry_and_runtime_selector_baselines(): void
    {
        $manifest = $this->loadJson('package_manifest.json');
        $this->assertSame(
            $manifest['source_baselines']['zh_cn_registry_content_tree_sha256'],
            $this->contentTreeSha(base_path('content_packs/BIG5_OCEAN/v2/registry'))
        );
        $this->assertSame(
            $manifest['source_baselines']['result_page_v2_service_content_tree_sha256'],
            $this->contentTreeSha(base_path('app/Services/BigFive/ResultPageV2'))
        );
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

    private function contentTreeSha(string $root): string
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        $rows = [];
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $rows[] = $relative.':'.hash_file('sha256', $file->getPathname());
        }
        sort($rows, SORT_STRING);

        return hash('sha256', implode("\n", $rows));
    }
}
