<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Report;

use App\Services\Enneagram\Assets\EnneagramAssetItemStreamLoader;
use App\Services\Enneagram\Assets\EnneagramAssetMergeResolver;
use App\Services\Enneagram\Assets\EnneagramAssetPreviewPayloadBuilder;
use App\Services\Enneagram\Assets\EnneagramAssetPublicPayloadSanitizer;
use Tests\TestCase;
use Tests\Unit\Services\Enneagram\Assets\EnneagramAssetTestPaths;

final class EnneagramAssetPublicPayloadContractTest extends TestCase
{
    use EnneagramAssetTestPaths;

    public function test_public_preview_payload_exposes_only_allowlisted_asset_fields(): void
    {
        $this->skipWhenAssetsMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $merged = app(EnneagramAssetMergeResolver::class)->resolve($loader->load($this->batchAPath()), $loader->load($this->batchBPath()));
        $payload = app(EnneagramAssetPreviewPayloadBuilder::class)->build($merged, app(EnneagramAssetPreviewPayloadBuilder::class)->contextFor('1', 'clear'));
        $sanitizer = app(EnneagramAssetPublicPayloadSanitizer::class);

        $this->assertSame([], $sanitizer->internalMetadataLeaks($payload));
        foreach ((array) $payload['modules'] as $module) {
            $content = (array) ($module['content'] ?? []);
            $this->assertArrayHasKey('body_zh', $content);
            $this->assertArrayHasKey('asset_key', $content);
            $this->assertArrayNotHasKey('selection_guidance', $content);
            $this->assertArrayNotHasKey('editor_note', $content);
            $this->assertArrayNotHasKey('qa_note', $content);
            $this->assertArrayNotHasKey('safety_note', $content);
        }
    }

    public function test_public_preview_payload_exposes_pair_safe_fields_without_internal_metadata(): void
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
        $batchFItem = $loader->load($this->batchFPath())['items'][0];
        $payload = app(EnneagramAssetPreviewPayloadBuilder::class)->build(
            $merged,
            app(EnneagramAssetPreviewPayloadBuilder::class)->contextForPairItem($batchFItem)
        );
        $sanitizer = app(EnneagramAssetPublicPayloadSanitizer::class);

        $this->assertSame([], $sanitizer->internalMetadataLeaks($payload));
        $pairModule = collect((array) $payload['modules'])
            ->first(fn (array $module): bool => data_get($module, 'content.category') === 'close_call_pair');

        $this->assertIsArray($pairModule);
        $content = (array) ($pairModule['content'] ?? []);
        $this->assertSame('1_2', $content['pair_key']);
        $this->assertArrayHasKey('commercial_summary', $content);
        $this->assertArrayHasKey('micro_discrimination_prompt', $content);
        $this->assertArrayNotHasKey('selection_guidance', $content);
        $this->assertArrayNotHasKey('editor_note', $content);
        $this->assertArrayNotHasKey('qa_note', $content);
        $this->assertArrayNotHasKey('safety_note', $content);
    }

    public function test_public_preview_payload_exposes_scene_safe_fields_without_internal_metadata(): void
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
        $batchGItem = $loader->load($this->batchGPath())['items'][0];
        $payload = app(EnneagramAssetPreviewPayloadBuilder::class)->build(
            $merged,
            app(EnneagramAssetPreviewPayloadBuilder::class)->contextForSceneItem($batchGItem)
        );
        $sanitizer = app(EnneagramAssetPublicPayloadSanitizer::class);

        $this->assertSame([], $sanitizer->internalMetadataLeaks($payload));
        $sceneModule = collect((array) $payload['modules'])
            ->first(fn (array $module): bool => data_get($module, 'content.category') === 'scene_localization_response');

        $this->assertIsArray($sceneModule);
        $content = (array) ($sceneModule['content'] ?? []);
        $this->assertSame('student_group_project', $content['scene_axis']);
        $this->assertSame('student', $content['scene_domain']);
        $this->assertSame('小组作业', $content['scene_label_zh']);
        $this->assertArrayNotHasKey('selection_guidance', $content);
        $this->assertArrayNotHasKey('editor_note', $content);
        $this->assertArrayNotHasKey('qa_note', $content);
        $this->assertArrayNotHasKey('safety_note', $content);
    }

    public function test_public_preview_payload_exposes_fc144_safe_fields_without_internal_metadata(): void
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
        $batchHItem = $loader->load($this->batchHPath())['items'][0];
        $payload = app(EnneagramAssetPreviewPayloadBuilder::class)->build(
            $merged,
            app(EnneagramAssetPreviewPayloadBuilder::class)->contextForFc144RecommendationItem($batchHItem)
        );
        $sanitizer = app(EnneagramAssetPublicPayloadSanitizer::class);

        $this->assertSame([], $sanitizer->internalMetadataLeaks($payload));
        $fc144Module = collect((array) $payload['modules'])
            ->first(fn (array $module): bool => data_get($module, 'content.category') === 'fc144_recommendation_response');

        $this->assertIsArray($fc144Module);
        $content = (array) ($fc144Module['content'] ?? []);
        $this->assertSame('clear_high_resonance', $content['fc144_recommendation_context']);
        $this->assertSame('recommend_after_high_resonance', $content['recommendation_strategy']);
        $this->assertArrayNotHasKey('selection_guidance', $content);
        $this->assertArrayNotHasKey('editor_note', $content);
        $this->assertArrayNotHasKey('qa_note', $content);
        $this->assertArrayNotHasKey('safety_note', $content);
    }
}
