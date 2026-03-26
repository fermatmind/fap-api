<?php

namespace App\Services\Attempts;

use App\Services\Assessment\ScoreResult;
use App\Exceptions\Api\ApiProblemException;
use App\Services\Psychometrics\MbtiQualityEvaluator;

class AttemptSubmitScoreService
{
    public function __construct(private AttemptSubmitService $core)
    {
    }

    public function handle(array $canonicalized): array
    {
        $scaleCode = (string) ($canonicalized['scale_code'] ?? '');
        $orgId = (int) ($canonicalized['org_id'] ?? 0);
        $packId = (string) ($canonicalized['pack_id'] ?? '');
        $dirVersion = (string) ($canonicalized['dir_version'] ?? '');
        $mergedAnswers = (array) ($canonicalized['merged_answers'] ?? []);
        $scoreContext = (array) ($canonicalized['score_context'] ?? []);
        $registryRow = (array) ($canonicalized['registry_row'] ?? []);

        $scored = $this->core->assessmentRunner()->run(
            $scaleCode,
            $orgId,
            $packId,
            $dirVersion,
            $mergedAnswers,
            $scoreContext
        );
        if (! ($scored['ok'] ?? false)) {
            $errorCode = strtoupper(trim((string) ($scored['error'] ?? 'SCORING_FAILED')));
            if ($errorCode === '') {
                $errorCode = 'SCORING_FAILED';
            }
            $status = match ($errorCode) {
                'SCORING_INPUT_INVALID' => 422,
                'SCALE_NOT_FOUND' => 404,
                default => 500,
            };

            throw new ApiProblemException($status, $errorCode, (string) ($scored['message'] ?? 'scoring failed.'));
        }

        $scoreResult = $scored['result'];
        $contentPackageVersion = (string) ($scored['pack']['content_package_version'] ?? '');
        $scoringSpecVersion = (string) ($scored['scoring_spec_version'] ?? '');
        $modelSelection = is_array($scored['model_selection'] ?? null)
            ? $scored['model_selection']
            : [];

        if (
            $scaleCode === 'MBTI'
            && $scoreResult instanceof ScoreResult
            && $contentPackageVersion !== ''
        ) {
            // Current MBTI quality truth starts here and is persisted through results.result_json.
            $quality = app(MbtiQualityEvaluator::class)->evaluate(
                (string) ($canonicalized['region'] ?? ''),
                (string) ($canonicalized['locale'] ?? ''),
                $contentPackageVersion,
                $dirVersion,
                $mergedAnswers
            );

            if ($quality !== []) {
                $normed = is_array($scoreResult->normedJson ?? null) ? $scoreResult->normedJson : [];
                $normed['quality'] = $quality;
                $scoreResult->normedJson = $normed;
            }
        }

        $commercial = $registryRow['commercial_json'] ?? null;
        if (is_string($commercial)) {
            $decoded = json_decode($commercial, true);
            $commercial = is_array($decoded) ? $decoded : null;
        }

        $creditBenefitCode = '';
        if (is_array($commercial)) {
            $creditBenefitCode = strtoupper(trim((string) ($commercial['credit_benefit_code'] ?? '')));
        }

        $entitlementBenefitCode = strtoupper(trim((string) ($commercial['report_benefit_code'] ?? '')));
        if ($entitlementBenefitCode === '') {
            $entitlementBenefitCode = $creditBenefitCode;
        }

        return [
            'score_result' => $scoreResult,
            'content_package_version' => $contentPackageVersion,
            'scoring_spec_version' => $scoringSpecVersion,
            'model_selection' => $modelSelection,
            'credit_benefit_code' => $creditBenefitCode,
            'entitlement_benefit_code' => $entitlementBenefitCode,
        ];
    }
}
