<?php

declare(strict_types=1);

namespace App\Services\Iq;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Analytics\EventRecorder;

final class IqProductionObservability
{
    public const SCALE_CODE = 'IQ_INTELLIGENCE_QUOTIENT';

    public const EVENT_COMPLETION = 'iq_completion';

    public const EVENT_NORM_MISS = 'iq_norm_miss';

    public const EVENT_ENTITLEMENT_MISS = 'iq_entitlement_miss';

    public const EVENT_SCORING_ANOMALY = 'iq_scoring_anomaly';

    public const EVENT_VERSION_DRIFT = 'iq_version_drift';

    /**
     * @var list<string>
     */
    private const ALLOWED_EXTRA_KEYS = [
        'request_id',
        'source',
        'surface',
        'variant',
        'locked',
        'report_access_level',
        'entitlement_status',
        'scoring_status',
        'scoring_mode',
        'reason_code',
        'norms_status',
        'quality_level',
        'raw_score',
        'final_score',
        'answer_count',
        'expected_item_count',
        'correct_count',
        'dimension_count',
        'bank_id',
        'norm_table_version',
        'scoring_engine_version',
        'scoring_spec_version',
        'content_package_version',
        'content_manifest_hash',
        'pack_version',
    ];

    public function __construct(
        private readonly EventRecorder $events,
    ) {}

    /**
     * Emit the full IQ production guard snapshot for a completed attempt.
     *
     * The emitted events intentionally contain aggregate status and version data only. They must
     * never include answer keys, answer text, item payloads, or paid-report private payloads.
     *
     * @param  array<string,mixed>  $extra
     */
    public function recordCompletionSnapshot(Attempt $attempt, Result $result, array $extra = []): void
    {
        $score = $this->extractScoreResult($result);
        $base = $this->buildBaseMeta($attempt, $result, $score, $extra);

        $this->record($attempt, $result, self::EVENT_COMPLETION, $base);

        $normsStatus = strtolower(trim((string) ($base['norms_status'] ?? '')));
        $normTableVersion = strtolower(trim((string) ($base['norm_table_version'] ?? '')));
        if ($normsStatus === '' || str_contains($normsStatus, 'unavailable') || in_array($normTableVersion, ['', 'unavailable'], true)) {
            $this->record($attempt, $result, self::EVENT_NORM_MISS, $base);
        }

        if ((bool) ($base['locked'] ?? false) || strtolower(trim((string) ($base['entitlement_status'] ?? ''))) === 'missing') {
            $this->record($attempt, $result, self::EVENT_ENTITLEMENT_MISS, $base);
        }

        $anomalyReasons = $this->detectScoringAnomalies($base);
        if ($anomalyReasons !== []) {
            $this->record($attempt, $result, self::EVENT_SCORING_ANOMALY, [
                ...$base,
                'reason_code' => implode(',', $anomalyReasons),
            ]);
        }

        $driftReasons = $this->detectVersionDrift($attempt, $result, $score);
        if ($driftReasons !== []) {
            $this->record($attempt, $result, self::EVENT_VERSION_DRIFT, [
                ...$base,
                'reason_code' => implode(',', $driftReasons),
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    public function recordNormMiss(Attempt $attempt, ?Result $result = null, array $meta = []): void
    {
        $this->record($attempt, $result, self::EVENT_NORM_MISS, $this->safeMeta($meta));
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    public function recordEntitlementMiss(Attempt $attempt, ?Result $result = null, array $meta = []): void
    {
        $this->record($attempt, $result, self::EVENT_ENTITLEMENT_MISS, $this->safeMeta($meta));
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    public function recordScoringAnomaly(Attempt $attempt, ?Result $result = null, array $meta = []): void
    {
        $this->record($attempt, $result, self::EVENT_SCORING_ANOMALY, $this->safeMeta($meta));
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    public function recordVersionDrift(Attempt $attempt, ?Result $result = null, array $meta = []): void
    {
        $this->record($attempt, $result, self::EVENT_VERSION_DRIFT, $this->safeMeta($meta));
    }

    /**
     * @param  array<string,mixed>  $score
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function buildBaseMeta(Attempt $attempt, Result $result, array $score, array $extra): array
    {
        $quality = is_array($score['quality'] ?? null) ? $score['quality'] : [];
        $norms = is_array($score['norms'] ?? null) ? $score['norms'] : [];
        $dimensions = is_array($score['dimension_scores'] ?? null) ? $score['dimension_scores'] : [];
        $snapshot = is_array($score['version_snapshot'] ?? null) ? $score['version_snapshot'] : [];

        return $this->safeMeta([
            'source' => 'iq_production_observability',
            'scale_code' => self::SCALE_CODE,
            'scoring_status' => $score['status'] ?? null,
            'scoring_mode' => $score['scoring_mode'] ?? null,
            'reason_code' => $score['reason_code'] ?? null,
            'norms_status' => $norms['status'] ?? null,
            'quality_level' => $quality['level'] ?? null,
            'raw_score' => $score['raw_score'] ?? null,
            'final_score' => $score['final_score'] ?? null,
            'answer_count' => $score['answer_count'] ?? null,
            'expected_item_count' => $score['expected_item_count'] ?? null,
            'correct_count' => $score['correct_count'] ?? null,
            'dimension_count' => count($dimensions),
            'bank_id' => $score['bank_id'] ?? null,
            'norm_table_version' => $score['norm_table_version'] ?? ($norms['norm_table_version'] ?? null),
            'scoring_engine_version' => $score['scoring_engine_version'] ?? null,
            'scoring_spec_version' => $result->scoring_spec_version ?? $attempt->scoring_spec_version ?? ($snapshot['scoring_spec_version'] ?? null),
            'content_package_version' => $result->content_package_version ?? $attempt->content_package_version ?? ($snapshot['pack_version'] ?? null),
            'content_manifest_hash' => $snapshot['content_manifest_hash'] ?? null,
            'pack_version' => $snapshot['pack_version'] ?? $result->dir_version ?? $attempt->dir_version ?? null,
            ...$extra,
        ]);
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function safeMeta(array $meta): array
    {
        $safe = [
            'scale_code' => self::SCALE_CODE,
            'observability_schema' => 'iq.production_observability.v1',
        ];

        foreach (self::ALLOWED_EXTRA_KEYS as $key) {
            if (! array_key_exists($key, $meta)) {
                continue;
            }

            $value = $meta[$key];
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $safe[$key] = $this->normalizeScalar($value);
        }

        return array_filter(
            $safe,
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function record(Attempt $attempt, ?Result $result, string $eventCode, array $meta): void
    {
        $this->events->record(
            $eventCode,
            $this->nullableInt($attempt->user_id ?? null),
            $meta,
            [
                'org_id' => $this->nullableInt($attempt->org_id ?? null) ?? 0,
                'user_id' => $this->nullableInt($attempt->user_id ?? null),
                'anon_id' => $this->nullableString($attempt->anon_id ?? null),
                'attempt_id' => $this->nullableString($attempt->id ?? null),
                'scale_code' => self::SCALE_CODE,
                'scale_code_v2' => self::SCALE_CODE,
                'pack_id' => $this->nullableString($result->pack_id ?? $attempt->pack_id ?? null),
                'dir_version' => $this->nullableString($result->dir_version ?? $attempt->dir_version ?? null),
                'region' => $this->nullableString($attempt->region ?? null),
                'locale' => $this->nullableString($attempt->locale ?? null),
            ]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function extractScoreResult(Result $result): array
    {
        $candidates = [
            $result->normed_json ?? null,
            data_get($result->result_json, 'normed_json'),
            data_get($result->result_json, 'breakdown_json.score_result'),
            data_get($result->result_json, 'axis_scores_json.score_result'),
            $result->result_json,
        ];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            if (
                array_key_exists('status', $candidate)
                || array_key_exists('quality', $candidate)
                || array_key_exists('norms', $candidate)
                || array_key_exists('dimension_scores', $candidate)
            ) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $base
     * @return list<string>
     */
    private function detectScoringAnomalies(array $base): array
    {
        $reasons = [];
        $status = strtolower(trim((string) ($base['scoring_status'] ?? '')));
        if ($status !== '' && $status !== 'scored') {
            $reasons[] = 'scoring_status_not_scored';
        }

        $rawScore = $this->numericOrNull($base['raw_score'] ?? null);
        $expected = $this->numericOrNull($base['expected_item_count'] ?? null);
        $answerCount = $this->numericOrNull($base['answer_count'] ?? null);
        $correctCount = $this->numericOrNull($base['correct_count'] ?? null);

        if ($rawScore !== null && $expected !== null && ($rawScore < 0.0 || $rawScore > $expected)) {
            $reasons[] = 'raw_score_out_of_expected_range';
        }
        if ($answerCount !== null && $expected !== null && $answerCount !== $expected) {
            $reasons[] = 'answer_count_mismatch';
        }
        if ($correctCount !== null && $answerCount !== null && $correctCount > $answerCount) {
            $reasons[] = 'correct_count_exceeds_answer_count';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  array<string,mixed>  $score
     * @return list<string>
     */
    private function detectVersionDrift(Attempt $attempt, Result $result, array $score): array
    {
        $reasons = [];
        $snapshot = is_array($score['version_snapshot'] ?? null) ? $score['version_snapshot'] : [];

        foreach ([
            'content_package_version' => [$attempt->content_package_version ?? null, $result->content_package_version ?? null],
            'scoring_spec_version' => [$attempt->scoring_spec_version ?? null, $result->scoring_spec_version ?? null],
            'pack_id' => [$attempt->pack_id ?? null, $result->pack_id ?? ($snapshot['pack_id'] ?? null)],
            'dir_version' => [$attempt->dir_version ?? null, $result->dir_version ?? ($snapshot['pack_version'] ?? null)],
        ] as $field => [$attemptValue, $resultValue]) {
            $left = trim((string) $attemptValue);
            $right = trim((string) $resultValue);
            if ($left !== '' && $right !== '' && $left !== $right) {
                $reasons[] = $field.'_mismatch';
            }
        }

        return array_values(array_unique($reasons));
    }

    private function normalizeScalar(mixed $value): mixed
    {
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return $value + 0;
        }

        return trim((string) $value);
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) || is_numeric($value)) {
            $normalized = trim((string) $value);
            if ($normalized !== '' && preg_match('/^\d+$/', $normalized) === 1) {
                return (int) $normalized;
            }
        }

        return null;
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
