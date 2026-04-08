<?php

declare(strict_types=1);

namespace App\Services\Career;

use App\Domain\Career\Scoring\ScoringEngine5D;
use App\Models\Occupation;
use App\Models\ProfileProjection;
use App\Models\RecommendationSnapshot;

final class CareerRecommendationCompiler
{
    public const COMPILER_VERSION = 'career_scoring_engine.v1.2';

    public function __construct(
        private readonly CareerScoringInputResolver $inputResolver,
        private readonly ScoringEngine5D $scoringEngine,
    ) {}

    public function compile(ProfileProjection $profileProjection, Occupation $occupation): RecommendationSnapshot
    {
        $resolved = $this->inputResolver->resolve($profileProjection, $occupation);
        $compiled = $this->scoringEngine->compile($resolved);
        $compiledAt = now();

        return RecommendationSnapshot::query()->create([
            'profile_projection_id' => $profileProjection->id,
            'context_snapshot_id' => $profileProjection->context_snapshot_id,
            'occupation_id' => $occupation->id,
            'compiler_version' => self::COMPILER_VERSION,
            'trust_manifest_id' => $resolved['trust_manifest_id'],
            'index_state_id' => $resolved['index_state_id'],
            'truth_metric_id' => $resolved['truth_metric_id'],
            'compiled_at' => $compiledAt,
            'snapshot_payload' => [
                'score_bundle' => $compiled['score_bundle'],
                'warnings' => $compiled['warnings'],
                'claim_permissions' => $compiled['claim_permissions'],
                'integrity_summary' => $compiled['integrity_summary'],
                'compile_refs' => [
                    'compiler_version' => self::COMPILER_VERSION,
                    'profile_projection_id' => $profileProjection->id,
                    'context_snapshot_id' => $profileProjection->context_snapshot_id,
                    'occupation_id' => $occupation->id,
                    'trust_manifest_id' => $resolved['trust_manifest_id'],
                    'index_state_id' => $resolved['index_state_id'],
                    'truth_metric_id' => $resolved['truth_metric_id'],
                    'source_trace_id' => $resolved['source_trace_id'],
                    'compiled_at' => $compiledAt->toISOString(),
                ],
            ],
        ]);
    }
}
