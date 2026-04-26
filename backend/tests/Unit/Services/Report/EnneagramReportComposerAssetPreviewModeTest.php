<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Report;

use App\Services\Enneagram\Assets\EnneagramAssetItemStreamLoader;
use App\Services\Enneagram\Assets\EnneagramAssetMergeResolver;
use App\Services\Enneagram\Assets\EnneagramAssetPreviewPayloadBuilder;
use App\Services\Enneagram\Assets\EnneagramAssetPublicPayloadSanitizer;
use App\Services\Report\EnneagramReportComposer;
use Tests\TestCase;
use Tests\Unit\Services\Enneagram\Assets\EnneagramAssetTestPaths;

final class EnneagramReportComposerAssetPreviewModeTest extends TestCase
{
    use EnneagramAssetTestPaths;

    public function test_it_requires_explicit_preview_mode_and_returns_sanitized_report_v2(): void
    {
        $this->skipWhenAssetsMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $merged = app(EnneagramAssetMergeResolver::class)->resolve($loader->load($this->batchAPath()), $loader->load($this->batchBPath()));
        $context = app(EnneagramAssetPreviewPayloadBuilder::class)->contextFor('1', 'clear');
        $composer = app(EnneagramReportComposer::class);

        $blocked = $composer->composeAssetPreview($merged, $context);
        $this->assertFalse($blocked['ok']);

        $context['preview_mode'] = true;
        $result = $composer->composeAssetPreview($merged, $context);
        $this->assertTrue($result['ok']);
        $reportV2 = data_get($result, 'report._meta.enneagram_report_v2');
        $this->assertSame('enneagram.report.v2', $reportV2['schema_version']);
        $this->assertTrue($reportV2['preview_mode']);
        $this->assertSame([], app(EnneagramAssetPublicPayloadSanitizer::class)->internalMetadataLeaks($reportV2));
    }
}
