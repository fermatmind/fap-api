<?php

declare(strict_types=1);

namespace App\Services\Eq;

use App\Models\Attempt;
use App\Models\Result;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class EqCrossAssessmentContextGuard
{
    public const VERSION = 'eq_cross_assessment_context.v1';

    /** @var array<string,string> */
    private const ASSET_IDS = [
        'MBTI' => 'eq.cross_context.mbti.available',
        'BIG5_OCEAN' => 'eq.cross_context.big_five.available',
        'ENNEAGRAM' => 'eq.cross_context.enneagram.available',
    ];

    /** @var list<string> */
    private const SOURCE_SCALES = ['MBTI', 'BIG5_OCEAN', 'ENNEAGRAM'];

    /**
     * @return array<string,mixed>
     */
    public function build(Attempt $attempt, Result $result): array
    {
        $actor = $this->resolveActor($attempt);
        $sources = $actor === null ? [] : $this->sourceAttempts($attempt, $actor);
        $sourcePayload = [];
        $assetIds = [];

        foreach (self::SOURCE_SCALES as $scaleCode) {
            $source = $sources[$scaleCode] ?? null;
            $available = is_array($source);
            $sourcePayload[$scaleCode] = [
                'available' => $available,
                'asset_id' => self::ASSET_IDS[$scaleCode],
                'source_attempt_id' => $available ? (string) ($source['attempt_id'] ?? '') : null,
                'source_result_id' => $available ? (string) ($source['result_id'] ?? '') : null,
                'submitted_at' => $available ? $this->dateString($source['submitted_at'] ?? null) : null,
                'evidence_kind' => $available ? 'completed_source_assessment' : 'not_available',
            ];

            if ($available) {
                $assetIds[] = self::ASSET_IDS[$scaleCode];
            }
        }

        return [
            'schema' => self::VERSION,
            'available' => true,
            'status' => $assetIds === [] ? 'no_source_assessments' : 'sources_available',
            'source_count' => count($assetIds),
            'source_scales' => $sourcePayload,
            'context_asset_ids' => $assetIds,
            'boundary_asset_id' => 'eq.cross_context.boundary.default',
            'guardrails' => [
                'eq_authority' => 'EQ_60 remains authoritative for emotional and relational self-report signals.',
                'source_authority' => 'MBTI, Big Five, and Enneagram remain authoritative only for their own assessment domains.',
                'affects_scores' => false,
                'changes_formulation' => false,
                'formal_report_mutation_allowed' => false,
                'claim_boundary' => 'reflection_context_only_not_type_determinism',
                'blocked_claims' => [
                    'career_or_hiring_prediction',
                    'clinical_or_diagnostic_claim',
                    'job_performance_prediction',
                    'fixed_personality_identity',
                    'emotional_ability_claim',
                ],
            ],
        ];
    }

    /**
     * @return array{field:string,value:string}|null
     */
    private function resolveActor(Attempt $attempt): ?array
    {
        $userId = trim((string) ($attempt->user_id ?? ''));
        if ($userId !== '') {
            return ['field' => 'user_id', 'value' => $userId];
        }

        $anonId = trim((string) ($attempt->anon_id ?? ''));
        if ($anonId !== '') {
            return ['field' => 'anon_id', 'value' => $anonId];
        }

        return null;
    }

    /**
     * @param  array{field:string,value:string}  $actor
     * @return array<string,array<string,mixed>>
     */
    private function sourceAttempts(Attempt $attempt, array $actor): array
    {
        if (! Schema::hasTable('attempts') || ! Schema::hasTable('results')) {
            return [];
        }

        $rows = DB::table('attempts')
            ->join('results', 'results.attempt_id', '=', 'attempts.id')
            ->where('attempts.org_id', (int) ($attempt->org_id ?? 0))
            ->where('attempts.'.$actor['field'], $actor['value'])
            ->where('attempts.id', '!=', (string) ($attempt->id ?? ''))
            ->whereNotNull('attempts.submitted_at')
            ->whereIn('attempts.scale_code', self::SOURCE_SCALES)
            ->orderByDesc('attempts.submitted_at')
            ->orderByDesc('attempts.created_at')
            ->get([
                'attempts.id as attempt_id',
                'attempts.scale_code as scale_code',
                'attempts.submitted_at as submitted_at',
                'results.id as result_id',
            ]);

        $sources = [];
        foreach ($rows as $row) {
            $scaleCode = strtoupper(trim((string) ($row->scale_code ?? '')));
            if (! in_array($scaleCode, self::SOURCE_SCALES, true) || isset($sources[$scaleCode])) {
                continue;
            }

            $sources[$scaleCode] = [
                'attempt_id' => (string) ($row->attempt_id ?? ''),
                'result_id' => (string) ($row->result_id ?? ''),
                'submitted_at' => $row->submitted_at ?? null,
            ];
        }

        return $sources;
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
