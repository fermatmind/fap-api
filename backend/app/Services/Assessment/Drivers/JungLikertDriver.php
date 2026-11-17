<?php

declare(strict_types=1);

namespace App\Services\Assessment\Drivers;

use App\Services\Assessment\Jung\ArchetypeScorer;
use App\Services\Assessment\Jung\CognitiveFunctionScorer;
use App\Services\Assessment\Jung\JungBank;
use App\Services\Assessment\ScoreResult;
use InvalidArgumentException;

final class JungLikertDriver implements DriverInterface
{
    public function score(array $answers, array $spec, array $ctx): ScoreResult
    {
        $bank = new JungBank($ctx['scale_code'] ?? '');
        $version = $bank->data['scoringVersion'];
        if (($ctx['pack_id'] ?? null) !== $bank->scale.'.v1'
            || ($ctx['dir_version'] ?? null) !== 'v1'
            || ($ctx['form_code'] ?? null) !== $bank->data['formCode']
            || ($ctx['scoring_spec_version'] ?? null) !== $version
            || ($ctx['content_manifest_hash'] ?? null) !== $bank->hash) {
            throw new InvalidArgumentException('Jung attempt version does not match its question bank.');
        }

        $projection = match ($bank->scale) {
            JungBank::ARCHETYPES => (new ArchetypeScorer)->score($answers),
            JungBank::FUNCTIONS => (new CognitiveFunctionScorer)->score($answers),
        };
        $versionSnapshot = [
            'pack_id' => $ctx['pack_id'],
            'form_code' => $ctx['form_code'],
            'pack_version' => $bank->data['itemBankVersion'],
            'scoring_spec_version' => $version,
            'content_manifest_hash' => $bank->hash,
        ];

        return new ScoreResult(
            rawScore: 0,
            finalScore: 0,
            breakdownJson: [
                'score_method' => $version,
                'answer_count' => count($answers),
                'version_snapshot' => $versionSnapshot,
            ],
            axisScoresJson: ['jung' => $projection, 'scores_pct' => $projection['scores']],
            normedJson: ['version_snapshot' => $versionSnapshot],
        );
    }
}
