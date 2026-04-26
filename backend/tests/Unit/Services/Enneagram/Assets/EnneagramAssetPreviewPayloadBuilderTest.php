<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use App\Services\Enneagram\Assets\EnneagramAssetItemStreamLoader;
use App\Services\Enneagram\Assets\EnneagramAssetMergeResolver;
use App\Services\Enneagram\Assets\EnneagramAssetPreviewPayloadBuilder;
use App\Services\Enneagram\Assets\EnneagramAssetPublicPayloadSanitizer;
use Tests\TestCase;

final class EnneagramAssetPreviewPayloadBuilderTest extends TestCase
{
    use EnneagramAssetTestPaths;

    public function test_it_builds_36_preview_payloads_without_internal_metadata(): void
    {
        $this->skipWhenAssetsMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $merged = app(EnneagramAssetMergeResolver::class)->resolve($loader->load($this->batchAPath()), $loader->load($this->batchBPath()));
        $payloads = app(EnneagramAssetPreviewPayloadBuilder::class)->buildAll($merged);
        $sanitizer = app(EnneagramAssetPublicPayloadSanitizer::class);

        $this->assertCount(36, $payloads);

        foreach ($payloads as $payload) {
            $this->assertTrue($payload['preview_mode']);
            $this->assertFalse($payload['production_import_allowed']);
            $this->assertFalse($payload['full_replacement_allowed']);
            $this->assertSame([], $payload['blocked_reasons']);
            $this->assertSame([], $sanitizer->internalMetadataLeaks($payload));
            $this->assertNotEmpty($payload['modules']);
        }
    }

    public function test_it_builds_1r_c_low_resonance_objection_matrix_without_internal_metadata(): void
    {
        $this->skipWhenAssetsMissing();
        $this->skipWhenBatchCMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $merged = app(EnneagramAssetMergeResolver::class)->resolveStreams(
            $loader->load($this->batchAPath()),
            $loader->load($this->batchBPath()),
            $loader->load($this->batchCPath()),
        );
        $payloads = app(EnneagramAssetPreviewPayloadBuilder::class)->buildLowResonanceObjectionMatrix($merged);
        $sanitizer = app(EnneagramAssetPublicPayloadSanitizer::class);

        $this->assertCount(108, $payloads);

        foreach ($payloads as $payload) {
            $this->assertTrue($payload['preview_mode']);
            $this->assertFalse($payload['production_import_allowed']);
            $this->assertFalse($payload['full_replacement_allowed']);
            $this->assertSame([], $payload['blocked_reasons']);
            $this->assertSame([], $sanitizer->internalMetadataLeaks($payload));
            $lowResonanceModules = array_values(array_filter(
                (array) ($payload['modules'] ?? []),
                static fn (array $module): bool => data_get($module, 'content.category') === 'low_resonance_response'
            ));

            $this->assertCount(1, $lowResonanceModules);
            $this->assertStringContainsString('1R_C', (string) data_get($lowResonanceModules[0], 'content.asset_key'));
        }
    }

    public function test_it_builds_1r_d_partial_resonance_matrix_without_internal_metadata(): void
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
        $payloads = app(EnneagramAssetPreviewPayloadBuilder::class)->buildPartialResonanceMatrix($merged);
        $sanitizer = app(EnneagramAssetPublicPayloadSanitizer::class);

        $this->assertCount(90, $payloads);

        foreach ($payloads as $payload) {
            $this->assertTrue($payload['preview_mode']);
            $this->assertFalse($payload['production_import_allowed']);
            $this->assertFalse($payload['full_replacement_allowed']);
            $this->assertSame([], $payload['blocked_reasons']);
            $this->assertSame([], $sanitizer->internalMetadataLeaks($payload));
            $partialResonanceModules = array_values(array_filter(
                (array) ($payload['modules'] ?? []),
                static fn (array $module): bool => data_get($module, 'content.category') === 'partial_resonance_response'
            ));

            $this->assertCount(1, $partialResonanceModules);
            $this->assertStringContainsString('1R_D', (string) data_get($partialResonanceModules[0], 'content.asset_key'));
        }
    }

    public function test_it_builds_1r_e_diffuse_convergence_matrix_without_internal_metadata(): void
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
        $payloads = app(EnneagramAssetPreviewPayloadBuilder::class)->buildDiffuseConvergenceMatrix($merged);
        $sanitizer = app(EnneagramAssetPublicPayloadSanitizer::class);

        $this->assertCount(108, $payloads);

        foreach ($payloads as $payload) {
            $this->assertTrue($payload['preview_mode']);
            $this->assertFalse($payload['production_import_allowed']);
            $this->assertFalse($payload['full_replacement_allowed']);
            $this->assertSame([], $payload['blocked_reasons']);
            $this->assertSame([], $sanitizer->internalMetadataLeaks($payload));
            $diffuseModules = array_values(array_filter(
                (array) ($payload['modules'] ?? []),
                static fn (array $module): bool => data_get($module, 'content.category') === 'diffuse_convergence_response'
            ));

            $this->assertCount(1, $diffuseModules);
            $this->assertStringContainsString('1R_E', (string) data_get($diffuseModules[0], 'content.asset_key'));
        }
    }
}
