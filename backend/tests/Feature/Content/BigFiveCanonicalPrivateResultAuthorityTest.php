<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\BigFivePrivateResultCompileService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class BigFiveCanonicalPrivateResultAuthorityTest extends TestCase
{
    public function test_retired_private_result_authorities_and_runtime_competitors_are_absent(): void
    {
        foreach ([
            'content_assets/big5/result_page_v2',
            'content_assets/big5/v1',
            'content_packs/BIG5_OCEAN/v2/drafts',
            'content_packs/BIG5_OCEAN/v2/packages',
            'content_packs/BIG_FIVE_OCEAN_MODEL',
            'app/Services/BigFive/ResultPageV2',
        ] as $retiredPath) {
            $absolutePath = base_path($retiredPath);
            $this->assertFalse(
                is_dir($absolutePath) && File::allFiles($absolutePath) !== [],
                "Retired Big Five authority still contains tracked files: {$retiredPath}"
            );
        }

        $legacyResultAssets = [
            'raw/blocks',
            'raw/bucket_copy.csv',
            'raw/golden_cases.csv',
            'raw/landing_i18n.json',
            'raw/legal/disclaimer.json',
            'raw/report_layout.json',
            'compiled/blocks.compiled.json',
            'compiled/copy.compiled.json',
            'compiled/golden_cases.compiled.json',
            'compiled/landing.compiled.json',
            'compiled/layout.compiled.json',
            'compiled/legal.compiled.json',
        ];

        foreach (['v1', 'v1-form-90'] as $assessmentVersion) {
            foreach ($legacyResultAssets as $legacyResultAsset) {
                $absolutePath = base_path("content_packs/BIG5_OCEAN/{$assessmentVersion}/{$legacyResultAsset}");
                $this->assertFalse(
                    is_dir($absolutePath) ? File::allFiles($absolutePath) !== [] : is_file($absolutePath),
                    "Legacy private result asset still exists in {$assessmentVersion}: {$legacyResultAsset}"
                );
            }
        }

        foreach (['app/Services', 'app/Http', 'app/Console', 'bootstrap', 'config'] as $runtimeRoot) {
            foreach (File::allFiles(base_path($runtimeRoot)) as $file) {
                $contents = File::get($file->getPathname());
                $this->assertStringNotContainsString('content_assets/big5/result_page_v2', $contents, $file->getPathname());
                $this->assertStringNotContainsString('Services\\BigFive\\ResultPageV2', $contents, $file->getPathname());
                $this->assertStringNotContainsString('BIG5_RESULT_PAGE_V2', $contents, $file->getPathname());
            }
        }

        $this->assertFileExists(base_path('content_packs/BIG5_OCEAN/v2/registry/manifest.json'));
        $this->assertDirectoryExists(base_path('content_packs/BIG5_OCEAN/v2/registry/en'));
    }

    public function test_registry_is_complete_canonical_chinese_private_result_source(): void
    {
        $root = base_path('content_packs/BIG5_OCEAN/v2/registry');
        $this->assertDirectoryExists($root);
        $this->assertDirectoryDoesNotExist(base_path('content_packs/BIG5_OCEAN/v3'));
        $this->assertDirectoryDoesNotExist(base_path('content_packs/BIG5_OCEAN/v4'));

        $compiled = app(BigFivePrivateResultCompileService::class)->compile();
        $coverage = $compiled['manifest']['coverage'];

        $this->assertSame(['O', 'C', 'E', 'A', 'N'], $coverage['traits']);
        $this->assertSame(['low', 'mid', 'high'], $coverage['bands']);
        $this->assertSame(30, $coverage['facet_count']);
        $this->assertSame(10, $coverage['synergy_count']);
        $this->assertSame(
            ['workplace', 'relationships', 'stress_recovery', 'personal_growth'],
            $coverage['action_scenarios']
        );
        $this->assertContains('near_boundary', array_keys(array_filter($coverage)));
        $this->assertSame(['valid', 'low_quality', 'norm_unavailable'], $coverage['quality_states']);
        $this->assertSame(['free', 'full'], $coverage['access_levels']);
        $this->assertSame(['faq', 'lifecycle', 'share', 'pdf', 'print', 'history', 'compare'], $coverage['secondary_surfaces']);

        $assets = $compiled['payload']['assets'];
        $this->assertSame(
            'fap.big5.private_result.secondary_surfaces.v1',
            $assets['surfaces/secondary.json']['schema']
        );
    }
}
