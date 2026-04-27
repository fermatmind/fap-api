<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use App\Services\Enneagram\Assets\EnneagramAssetItemStreamLoader;
use App\Services\Enneagram\Assets\EnneagramAssetMergeResolver;
use Tests\TestCase;

final class EnneagramAssetMergeResolverTest extends TestCase
{
    use EnneagramAssetTestPaths;

    public function test_it_merges_1r_a_and_1r_b_without_full_replacement(): void
    {
        $this->skipWhenAssetsMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $resolver = app(EnneagramAssetMergeResolver::class);

        $merged = $resolver->resolve($loader->load($this->batchAPath()), $loader->load($this->batchBPath()));

        $this->assertFalse($merged['production_import_allowed']);
        $this->assertFalse($merged['full_replacement_allowed']);
        $this->assertCount(738, $merged['items']);
        $this->assertContains('page1_summary', data_get($merged, 'replacement_coverage.batch_1r_a_replaces'));
        $this->assertContains('core_motivation', data_get($merged, 'replacement_coverage.batch_1r_b_replaces'));
    }

    public function test_it_merges_1r_a_1r_b_and_1r_c_as_staging_preview_only(): void
    {
        $this->skipWhenAssetsMissing();
        $this->skipWhenBatchCMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $resolver = app(EnneagramAssetMergeResolver::class);

        $merged = $resolver->resolveStreams(
            $loader->load($this->batchAPath()),
            $loader->load($this->batchBPath()),
            $loader->load($this->batchCPath()),
        );

        $this->assertFalse($merged['production_import_allowed']);
        $this->assertFalse($merged['full_replacement_allowed']);
        $this->assertCount(846, $merged['items']);
        $this->assertContains('low_resonance_response', data_get($merged, 'replacement_coverage.batch_1r_c_adds'));
        $this->assertSame(
            'enneagram_content_expansion_batch_1R_C_low_resonance_objection_handling.v1',
            data_get($merged, 'source_versions.batch_1r_c')
        );
    }

    public function test_it_merges_1r_a_1r_b_1r_c_and_1r_d_as_staging_preview_only(): void
    {
        $this->skipWhenAssetsMissing();
        $this->skipWhenBatchCMissing();
        $this->skipWhenBatchDMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $resolver = app(EnneagramAssetMergeResolver::class);

        $merged = $resolver->resolveStreams(
            $loader->load($this->batchAPath()),
            $loader->load($this->batchBPath()),
            $loader->load($this->batchCPath()),
            $loader->load($this->batchDPath()),
        );

        $this->assertFalse($merged['production_import_allowed']);
        $this->assertFalse($merged['full_replacement_allowed']);
        $this->assertCount(936, $merged['items']);
        $this->assertContains('partial_resonance_response', data_get($merged, 'replacement_coverage.batch_1r_d_adds'));
        $this->assertSame(
            'enneagram_content_expansion_batch_1R_D_partial_resonance_deep_branch.v1',
            data_get($merged, 'source_versions.batch_1r_d')
        );
    }

    public function test_it_merges_1r_a_1r_b_1r_c_1r_d_and_1r_e_as_staging_preview_only(): void
    {
        $this->skipWhenAssetsMissing();
        $this->skipWhenBatchCMissing();
        $this->skipWhenBatchDMissing();
        $this->skipWhenBatchEMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $resolver = app(EnneagramAssetMergeResolver::class);

        $merged = $resolver->resolveStreams(
            $loader->load($this->batchAPath()),
            $loader->load($this->batchBPath()),
            $loader->load($this->batchCPath()),
            $loader->load($this->batchDPath()),
            $loader->load($this->batchEPath()),
        );

        $this->assertFalse($merged['production_import_allowed']);
        $this->assertFalse($merged['full_replacement_allowed']);
        $this->assertCount(1044, $merged['items']);
        $this->assertContains('diffuse_convergence_response', data_get($merged, 'replacement_coverage.batch_1r_e_adds'));
        $this->assertSame(
            'enneagram_content_expansion_batch_1R_E_diffuse_top3_convergence.v1',
            data_get($merged, 'source_versions.batch_1r_e')
        );
    }

    public function test_it_merges_1r_a_1r_b_1r_c_1r_d_1r_e_and_1r_f_as_staging_preview_only(): void
    {
        $this->skipWhenAssetsMissing();
        $this->skipWhenBatchCMissing();
        $this->skipWhenBatchDMissing();
        $this->skipWhenBatchEMissing();
        $this->skipWhenBatchFMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $resolver = app(EnneagramAssetMergeResolver::class);

        $merged = $resolver->resolveStreams(
            $loader->load($this->batchAPath()),
            $loader->load($this->batchBPath()),
            $loader->load($this->batchCPath()),
            $loader->load($this->batchDPath()),
            $loader->load($this->batchEPath()),
            $loader->load($this->batchFPath()),
        );

        $this->assertFalse($merged['production_import_allowed']);
        $this->assertFalse($merged['full_replacement_allowed']);
        $this->assertCount(1080, $merged['items']);
        $this->assertContains('close_call_pair', data_get($merged, 'replacement_coverage.batch_1r_f_adds'));
        $this->assertSame(
            'enneagram_content_expansion_batch_1R_F_close_call_36_pair_completion.v1',
            data_get($merged, 'source_versions.batch_1r_f')
        );
    }

    public function test_it_merges_1r_a_1r_b_1r_c_1r_d_1r_e_1r_f_1r_g_and_1r_h_as_staging_preview_only(): void
    {
        $this->skipWhenAssetsMissing();
        $this->skipWhenBatchCMissing();
        $this->skipWhenBatchDMissing();
        $this->skipWhenBatchEMissing();
        $this->skipWhenBatchFMissing();
        $this->skipWhenBatchGMissing();
        $this->skipWhenBatchHMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $resolver = app(EnneagramAssetMergeResolver::class);

        $merged = $resolver->resolveStreams(
            $loader->load($this->batchAPath()),
            $loader->load($this->batchBPath()),
            $loader->load($this->batchCPath()),
            $loader->load($this->batchDPath()),
            $loader->load($this->batchEPath()),
            $loader->load($this->batchFPath()),
            $loader->load($this->batchGPath()),
            $loader->load($this->batchHPath()),
        );

        $this->assertFalse($merged['production_import_allowed']);
        $this->assertFalse($merged['full_replacement_allowed']);
        $this->assertCount(1332, $merged['items']);
        $this->assertContains('close_call_pair', data_get($merged, 'replacement_coverage.batch_1r_f_adds'));
        $this->assertContains('scene_localization_response', data_get($merged, 'replacement_coverage.batch_1r_g_adds'));
        $this->assertContains('fc144_recommendation_response', data_get($merged, 'replacement_coverage.batch_1r_h_adds'));
        $this->assertSame(
            'enneagram_content_expansion_batch_1R_F_close_call_36_pair_completion.v1',
            data_get($merged, 'source_versions.batch_1r_f')
        );
        $this->assertSame(
            'enneagram_content_expansion_batch_1R_G_scene_localization.v1',
            data_get($merged, 'source_versions.batch_1r_g')
        );
        $this->assertSame(
            'enneagram_content_expansion_batch_1R_H_fc144_recommendation.v1',
            data_get($merged, 'source_versions.batch_1r_h')
        );
    }
}
