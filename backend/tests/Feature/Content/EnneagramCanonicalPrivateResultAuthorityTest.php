<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\EnneagramPrivateResultCompileService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class EnneagramCanonicalPrivateResultAuthorityTest extends TestCase
{
    public function test_registry_is_the_single_editable_private_result_authority(): void
    {
        $compiled = app(EnneagramPrivateResultCompileService::class)->compile();
        $manifest = $compiled['manifest'];

        $this->assertSame(EnneagramPrivateResultCompileService::AUTHORITY_ID, $manifest['authority_id']);
        $this->assertSame('backend/content_packs/ENNEAGRAM/v2/registry', $manifest['authority_root']);
        $this->assertSame(1, $manifest['editable_authority_count']);
        $this->assertFalse($manifest['generated']['manual_edit_allowed']);
        $this->assertDirectoryDoesNotExist(base_path('content_packs/ENNEAGRAM/v3'));
        $this->assertDirectoryDoesNotExist(base_path('content_packs/ENNEAGRAM/v4'));
    }

    public function test_manifest_whitelists_every_source_and_no_fixture_is_source(): void
    {
        $compiled = app(EnneagramPrivateResultCompileService::class)->compile();
        $paths = array_merge(
            array_column($compiled['manifest']['locale_source_files']['zh-CN'], 'path'),
            array_column($compiled['manifest']['locale_source_files']['en'], 'path'),
        );
        $this->assertCount(24, $paths);
        $this->assertFalse((bool) array_filter($paths, static fn (string $path): bool => str_contains($path, 'fixture') || str_ends_with($path, 'manifest.json')));
        foreach ($paths as $path) {
            $this->assertFileExists(base_path('content_packs/ENNEAGRAM/v2/registry/'.$path));
        }

        $actualSources = collect(File::allFiles(base_path('content_packs/ENNEAGRAM/v2/registry')))
            ->map(static fn (\SplFileInfo $file): string => str_replace('\\', '/', $file->getRelativePathname()))
            ->reject(static fn (string $path): bool => $path === 'manifest.json' || $path === 'en/manifest.json')
            ->sort()
            ->values()
            ->all();
        sort($paths);
        $this->assertSame($paths, $actualSources);
    }

    public function test_locales_are_one_hash_bound_package_with_distinct_provenance(): void
    {
        $zhManifest = json_decode(File::get(base_path('content_packs/ENNEAGRAM/v2/registry/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        $enManifest = json_decode(File::get(base_path('content_packs/ENNEAGRAM/v2/registry/en/manifest.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('zh-CN', $zhManifest['locale']);
        $this->assertSame('en', $enManifest['locale']);
        $this->assertSame($zhManifest['authority_id'], $enManifest['authority_id']);
        $this->assertSame($zhManifest['release_id'], $enManifest['release_id']);
        $this->assertSame($zhManifest['source_hash'], $enManifest['source_hash']);
        $this->assertSame($zhManifest['compiled_hash'], $enManifest['compiled_hash']);
    }

    public function test_canonical_coverage_includes_all_required_private_surfaces(): void
    {
        $manifest = app(EnneagramPrivateResultCompileService::class)->compile()['manifest'];
        $this->assertSame(['1', '2', '3', '4', '5', '6', '7', '8', '9'], $manifest['coverage']['types']);
        $this->assertSame(36, $manifest['coverage']['pair_count']);
        $this->assertSame(['clear', 'close_call', 'diffuse', 'low_quality'], $manifest['coverage']['interpretation_states']);
        $this->assertSame(['e105', 'fc144'], $manifest['coverage']['forms']);
        $this->assertSame(['faq', 'technical_note', 'share', 'pdf', 'print', 'history', 'compare', 'secondary'], $manifest['coverage']['secondary_surfaces']);
    }

    public function test_compiled_output_is_not_an_editable_source(): void
    {
        $this->assertFileExists(base_path('content_packs/ENNEAGRAM/v2/compiled/private_result.compiled.json'));
        $contents = File::get(base_path('content_packs/ENNEAGRAM/v2/compiled/manifest.json'));
        $this->assertStringContainsString('"manual_edit_allowed": false', $contents);
    }

    public function test_normal_runtime_has_no_page_method_or_technical_note_body_fallback(): void
    {
        $composer = File::get(base_path('app/Services/Report/EnneagramReportComposer.php'));
        $projection = File::get(base_path('app/Services/Enneagram/EnneagramPublicProjectionService.php'));
        $technical = File::get(base_path('app/Services/Enneagram/EnneagramTechnicalNoteService.php'));

        $this->assertStringNotContainsString('PAGE_SPECS', $composer);
        $this->assertStringNotContainsString('E105 采用五点量表作答，在自身计分空间内形成九型完整轮廓', $projection);
        $this->assertStringNotContainsString('FC144 采用二选一迫选作答，在自身计分空间内记录相对取舍线索', $projection);
        $this->assertStringNotContainsString('private function disclaimers', $technical);
        $this->assertStringNotContainsString('本测试用于人格模式理解与自我观察', $technical);
        $this->assertStringNotContainsString('composeAssetPreview', $composer);
        $this->assertStringNotContainsString('buildUnavailableReportV2', $composer);
        $this->assertStringNotContainsString('Services\\Enneagram\\Assets', $composer);
    }

    public function test_retired_private_result_authorities_and_control_planes_are_absent(): void
    {
        $forbiddenPaths = [
            'content_assets/enneagram/result_page',
            'content_assets/en-content-parity/W5-enneagram/private-result-content-v2',
            'content_assets/en-content-parity/W9/enneagram-private-results',
            'generated/result-page-agents/enneagram',
            'app/Services/Enneagram/Assets',
            'app/Services/ContentPromotion/EnneagramPrivateResultPromotionAuthority.php',
            'app/Services/ContentPromotion/Adapters/EnneagramPrivateResultPromotionAdapter.php',
            'app/Services/Ops/EnneagramRegistryActivationGateService.php',
            'scripts/content/build_w5_enneagram_private_result_package.php',
            'scripts/content/verify_w5_enneagram_private_result_w9.php',
            'docs/enneagram/result-page-agent-runbook.md',
            'docs/enneagram/result-page-agent-schema.md',
            'docs/enneagram/result-page-agent-gates.md',
        ];

        foreach ($forbiddenPaths as $path) {
            $this->assertFileDoesNotExist(base_path($path), $path);
        }

        $kernel = File::get(base_path('app/Console/Kernel.php'));
        $promotionRegistry = File::get(base_path('app/Services/ContentPromotion/PromotionAdapterRegistry.php'));
        $promotionConfig = File::get(base_path('config/content_promotion.php'));
        foreach (['EnneagramResultPage', 'InactiveCandidate', 'ProductionEquivalentCandidate', 'EnneagramActivateRegistryRelease', 'EnneagramRollbackRegistryRelease'] as $symbol) {
            $this->assertStringNotContainsString($symbol, $kernel);
        }
        $this->assertStringNotContainsString('EnneagramPrivateResultPromotion', $promotionRegistry);
        $this->assertStringNotContainsString('enneagram-results', $promotionConfig);

        $this->assertFileExists(base_path('app/Console/Commands/Packs2Publish.php'));
        $this->assertFileExists(base_path('app/Console/Commands/Packs2Rollback.php'));
    }
}
