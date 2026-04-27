<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use App\Services\Enneagram\Assets\EnneagramAssetItemStreamLoader;
use App\Services\Enneagram\Assets\EnneagramAssetMergeResolver;
use App\Services\Enneagram\Assets\EnneagramAssetPreviewPayloadBuilder;
use App\Services\Enneagram\Assets\EnneagramAssetSelector;
use Tests\TestCase;

final class EnneagramAssetSelectorTest extends TestCase
{
    use EnneagramAssetTestPaths;

    public function test_it_selects_assets_by_type_category_and_preview_context(): void
    {
        $this->skipWhenAssetsMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $merged = app(EnneagramAssetMergeResolver::class)->resolve($loader->load($this->batchAPath()), $loader->load($this->batchBPath()));
        $context = app(EnneagramAssetPreviewPayloadBuilder::class)->contextFor('1', 'clear');
        $selected = app(EnneagramAssetSelector::class)->selectByCategory($merged, $context);

        $this->assertArrayHasKey('page1_summary', $selected);
        $this->assertArrayHasKey('core_motivation', $selected);
        $this->assertSame('1', $selected['page1_summary']['type_id']);
        $this->assertSame('1', $selected['core_motivation']['type_id']);
        $this->assertStringContainsString('1R_A', $selected['page1_summary']['asset_key']);
        $this->assertStringContainsString('1R_B', $selected['core_motivation']['asset_key']);
    }

    public function test_it_selects_1r_c_low_resonance_branch_for_matching_objection_axis(): void
    {
        $this->skipWhenAssetsMissing();
        $this->skipWhenBatchCMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $merged = app(EnneagramAssetMergeResolver::class)->resolveStreams(
            $loader->load($this->batchAPath()),
            $loader->load($this->batchBPath()),
            $loader->load($this->batchCPath()),
        );
        $batchCItem = collect((array) ($merged['items'] ?? []))
            ->first(static fn (array $item): bool => ($item['_preview_batch'] ?? null) === '1R-C'
                && ($item['type_id'] ?? null) === '1'
                && ($item['objection_axis'] ?? null) === 'top2_feels_closer');

        $this->assertIsArray($batchCItem);

        $context = app(EnneagramAssetPreviewPayloadBuilder::class)->contextForObjectionItem($batchCItem);
        $selected = app(EnneagramAssetSelector::class)->selectByCategory($merged, $context);

        $this->assertArrayHasKey('low_resonance_response', $selected);
        $this->assertSame('1R-C', $selected['low_resonance_response']['_preview_batch']);
        $this->assertSame('top2_feels_closer', $selected['low_resonance_response']['objection_axis']);
        $this->assertStringContainsString('1R_C', $selected['low_resonance_response']['asset_key']);
    }

    public function test_it_selects_1r_d_partial_resonance_branch_for_matching_partial_axis(): void
    {
        $this->skipWhenAssetsMissing();
        $this->skipWhenBatchCMissing();
        $this->skipWhenBatchDMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $merged = app(EnneagramAssetMergeResolver::class)->resolveStreams(
            $loader->load($this->batchAPath()),
            $loader->load($this->batchBPath()),
            $loader->load($this->batchCPath()),
            $loader->load($this->batchDPath()),
        );
        $batchDItem = collect((array) ($merged['items'] ?? []))
            ->first(static fn (array $item): bool => ($item['_preview_batch'] ?? null) === '1R-D'
                && ($item['type_id'] ?? null) === '1'
                && ($item['partial_axis'] ?? null) === 'top2_only');

        $this->assertIsArray($batchDItem);

        $context = app(EnneagramAssetPreviewPayloadBuilder::class)->contextForPartialItem($batchDItem);
        $selected = app(EnneagramAssetSelector::class)->selectByCategory($merged, $context);

        $this->assertArrayHasKey('partial_resonance_response', $selected);
        $this->assertSame('1R-D', $selected['partial_resonance_response']['_preview_batch']);
        $this->assertSame('top2_only', $selected['partial_resonance_response']['partial_axis']);
        $this->assertStringContainsString('1R_D', $selected['partial_resonance_response']['asset_key']);
    }

    public function test_it_selects_1r_e_diffuse_convergence_branch_for_matching_diffuse_axis(): void
    {
        $this->skipWhenAssetsMissing();
        $this->skipWhenBatchCMissing();
        $this->skipWhenBatchDMissing();
        $this->skipWhenBatchEMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $merged = app(EnneagramAssetMergeResolver::class)->resolveStreams(
            $loader->load($this->batchAPath()),
            $loader->load($this->batchBPath()),
            $loader->load($this->batchCPath()),
            $loader->load($this->batchDPath()),
            $loader->load($this->batchEPath()),
        );
        $batchEItem = collect((array) ($merged['items'] ?? []))
            ->first(static fn (array $item): bool => ($item['_preview_batch'] ?? null) === '1R-E'
                && ($item['type_id'] ?? null) === '1'
                && ($item['diffuse_axis'] ?? null) === 'top3_flat');

        $this->assertIsArray($batchEItem);

        $context = app(EnneagramAssetPreviewPayloadBuilder::class)->contextForDiffuseItem($batchEItem);
        $selected = app(EnneagramAssetSelector::class)->selectByCategory($merged, $context);

        $this->assertArrayHasKey('diffuse_convergence_response', $selected);
        $this->assertSame('1R-E', $selected['diffuse_convergence_response']['_preview_batch']);
        $this->assertSame('top3_flat', $selected['diffuse_convergence_response']['diffuse_axis']);
        $this->assertStringContainsString('1R_E', $selected['diffuse_convergence_response']['asset_key']);
    }

    public function test_it_selects_1r_f_close_call_pair_for_matching_pair_key_and_canonicalizes_reverse_probe(): void
    {
        $this->skipWhenAssetsMissing();
        $this->skipWhenBatchCMissing();
        $this->skipWhenBatchDMissing();
        $this->skipWhenBatchEMissing();
        $this->skipWhenBatchFMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $merged = app(EnneagramAssetMergeResolver::class)->resolveStreams(
            $loader->load($this->batchAPath()),
            $loader->load($this->batchBPath()),
            $loader->load($this->batchCPath()),
            $loader->load($this->batchDPath()),
            $loader->load($this->batchEPath()),
            $loader->load($this->batchFPath()),
        );
        $batchFItem = collect((array) ($merged['items'] ?? []))
            ->first(static fn (array $item): bool => ($item['_preview_batch'] ?? null) === '1R-F'
                && ($item['canonical_pair_key'] ?? null) === '1_6');

        $this->assertIsArray($batchFItem);

        $context = app(EnneagramAssetPreviewPayloadBuilder::class)->contextForPairItem($batchFItem);
        $selected = app(EnneagramAssetSelector::class)->selectByCategory($merged, $context);

        $this->assertArrayHasKey('close_call_pair', $selected);
        $this->assertSame('1R-F', $selected['close_call_pair']['_preview_batch']);
        $this->assertSame('1_6', $selected['close_call_pair']['canonical_pair_key']);
        $this->assertStringContainsString('1R_F', $selected['close_call_pair']['asset_key']);

        $reverseContext = array_merge($context, [
            'pair_key' => '6_1',
            'top1_type' => '6',
            'top2_type' => '1',
        ]);
        $reverseSelected = app(EnneagramAssetSelector::class)->selectByCategory($merged, $reverseContext);

        $this->assertSame(
            $selected['close_call_pair']['asset_key'],
            $reverseSelected['close_call_pair']['asset_key']
        );
    }

    public function test_it_selects_1r_g_scene_localization_branch_for_matching_scene_axis(): void
    {
        $this->skipWhenAssetsMissing();
        $this->skipWhenBatchCMissing();
        $this->skipWhenBatchDMissing();
        $this->skipWhenBatchEMissing();
        $this->skipWhenBatchFMissing();
        $this->skipWhenBatchGMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $merged = app(EnneagramAssetMergeResolver::class)->resolveStreams(
            $loader->load($this->batchAPath()),
            $loader->load($this->batchBPath()),
            $loader->load($this->batchCPath()),
            $loader->load($this->batchDPath()),
            $loader->load($this->batchEPath()),
            $loader->load($this->batchFPath()),
            $loader->load($this->batchGPath()),
        );
        $batchGItem = collect((array) ($merged['items'] ?? []))
            ->first(static fn (array $item): bool => ($item['_preview_batch'] ?? null) === '1R-G'
                && ($item['type_id'] ?? null) === '1'
                && ($item['scene_axis'] ?? null) === 'student_group_project');

        $this->assertIsArray($batchGItem);

        $context = app(EnneagramAssetPreviewPayloadBuilder::class)->contextForSceneItem($batchGItem);
        $selected = app(EnneagramAssetSelector::class)->selectByCategory($merged, $context);

        $this->assertArrayHasKey('scene_localization_response', $selected);
        $this->assertSame('1R-G', $selected['scene_localization_response']['_preview_batch']);
        $this->assertSame('student_group_project', $selected['scene_localization_response']['scene_axis']);
        $this->assertStringContainsString('1R_G', $selected['scene_localization_response']['asset_key']);
    }

    public function test_it_selects_1r_h_fc144_recommendation_branch_for_matching_context(): void
    {
        $this->skipWhenAssetsMissing();
        $this->skipWhenBatchCMissing();
        $this->skipWhenBatchDMissing();
        $this->skipWhenBatchEMissing();
        $this->skipWhenBatchFMissing();
        $this->skipWhenBatchGMissing();
        $this->skipWhenBatchHMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $merged = app(EnneagramAssetMergeResolver::class)->resolveStreams(
            $loader->load($this->batchAPath()),
            $loader->load($this->batchBPath()),
            $loader->load($this->batchCPath()),
            $loader->load($this->batchDPath()),
            $loader->load($this->batchEPath()),
            $loader->load($this->batchFPath()),
            $loader->load($this->batchGPath()),
            $loader->load($this->batchHPath()),
        );
        $batchHItem = collect((array) ($merged['items'] ?? []))
            ->first(static fn (array $item): bool => ($item['_preview_batch'] ?? null) === '1R-H'
                && ($item['type_id'] ?? null) === '1'
                && ($item['fc144_recommendation_context'] ?? null) === 'after_scene_localization');

        $this->assertIsArray($batchHItem);

        $context = app(EnneagramAssetPreviewPayloadBuilder::class)->contextForFc144RecommendationItem($batchHItem);
        $selected = app(EnneagramAssetSelector::class)->selectByCategory($merged, $context);

        $this->assertArrayHasKey('fc144_recommendation_response', $selected);
        $this->assertSame('1R-H', $selected['fc144_recommendation_response']['_preview_batch']);
        $this->assertSame('after_scene_localization', $selected['fc144_recommendation_response']['fc144_recommendation_context']);
        $this->assertStringContainsString('1R_H', $selected['fc144_recommendation_response']['asset_key']);
    }
}
