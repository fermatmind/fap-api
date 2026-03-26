<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Analytics;

use App\Services\Analytics\QualitySignalExtractor;
use Tests\TestCase;

final class QualitySignalExtractorTest extends TestCase
{
    public function test_mbti_quality_extractor_does_not_treat_snapshot_quality_as_current_truth(): void
    {
        $extractor = app(QualitySignalExtractor::class);

        $signal = $extractor->extract(
            [
                'scale_code' => 'MBTI',
                'type_code' => 'INTJ-A',
            ],
            [
                'quality' => [
                    'level' => 'B',
                    'flags' => ['SNAPSHOT_ONLY'],
                ],
            ],
            null
        );

        $this->assertSame('', $signal['level']);
        $this->assertSame([], $signal['flags']);
    }

    public function test_non_mbti_quality_extractor_keeps_snapshot_quality_fallback(): void
    {
        $extractor = app(QualitySignalExtractor::class);

        $signal = $extractor->extract(
            [
                'scale_code' => 'EQ_60',
            ],
            [
                'quality' => [
                    'level' => 'A',
                    'flags' => ['SNAPSHOT_ONLY'],
                ],
            ],
            null
        );

        $this->assertSame('A', $signal['level']);
        $this->assertSame(['SNAPSHOT_ONLY'], $signal['flags']);
    }
}
