<?php

declare(strict_types=1);

namespace App\Services\Legacy\Mbti\Report;

use App\Services\Legacy\Mbti\Report\V2\LegacyMbtiReportPayloadBuilderV2;
use App\Services\Legacy\Mbti\Report\V2\LegacyMbtiReportPayloadBuilderV2Facade;
use App\Services\Legacy\Mbti\Report\V2\LegacyMbtiReportPayloadComposer;

final class LegacyMbtiReportPayloadBuilder
{
    private LegacyMbtiReportPayloadBuilderV2 $legacy;

    private LegacyMbtiReportPayloadBuilderV2Facade $v2;

    public function __construct(private readonly LegacyMbtiReportAssetRepository $assetRepo)
    {
        $this->legacy = new LegacyMbtiReportPayloadBuilderV2($this->assetRepo);
        $this->v2 = new LegacyMbtiReportPayloadBuilderV2Facade(
            new LegacyMbtiReportPayloadComposer($this->legacy)
        );
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function buildLegacyMbtiReportParts(array $input): array
    {
        if (config('features.legacy_mbti_report_payload_v2', false) === true) {
            return $this->v2->build($input);
        }

        return $this->legacy->buildLegacyMbtiReportParts($input);
    }
}
