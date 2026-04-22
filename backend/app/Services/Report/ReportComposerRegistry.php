<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Assessment\GenericReportBuilder;

final class ReportComposerRegistry
{
    public function __construct(
        private readonly ReportComposer $reportComposer,
        private readonly BigFiveReportComposer $bigFiveReportComposer,
        private readonly ClinicalCombo68ReportComposer $clinicalCombo68ReportComposer,
        private readonly Sds20ReportComposer $sds20ReportComposer,
        private readonly Eq60ReportComposer $eq60ReportComposer,
        private readonly EnneagramReportComposer $enneagramReportComposer,
        private readonly RiasecReportComposer $riasecReportComposer,
        private readonly GenericReportBuilder $genericReportBuilder,
    ) {}

    /**
     * @param  array<string,mixed>  $ctx
     * @return array{ok:bool,report?:array<string,mixed>,error?:string,message?:string,status?:int}
     */
    public function composeVariant(
        string $scaleCode,
        Attempt $attempt,
        Result $result,
        string $variant,
        array $ctx = []
    ): array {
        $scaleCode = strtoupper(trim($scaleCode));

        return match ($scaleCode) {
            'MBTI' => $this->reportComposer->composeVariant($attempt, $variant, $ctx, $result),
            'BIG5_OCEAN' => $this->bigFiveReportComposer->composeVariant($attempt, $result, $variant, $ctx),
            'CLINICAL_COMBO_68' => $this->clinicalCombo68ReportComposer->composeVariant($attempt, $result, $variant, $ctx),
            'SDS_20' => $this->sds20ReportComposer->composeVariant($attempt, $result, $variant, $ctx),
            'EQ_60' => $this->eq60ReportComposer->composeVariant($attempt, $result, $variant, $ctx),
            'ENNEAGRAM' => $this->enneagramReportComposer->composeVariant($attempt, $result, $variant, $ctx),
            'RIASEC' => $this->riasecReportComposer->composeVariant($attempt, $result, $variant, $ctx),
            default => [
                'ok' => true,
                'report' => $this->genericReportBuilder->build($attempt, $result),
            ],
        };
    }
}
