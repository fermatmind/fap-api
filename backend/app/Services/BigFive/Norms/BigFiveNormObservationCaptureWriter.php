<?php

declare(strict_types=1);

namespace App\Services\BigFive\Norms;

use App\Models\BigFiveNormObservation;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class BigFiveNormObservationCaptureWriter
{
    public const SCHEMA_VERSION = 'big5_norm_observation.v0.1';

    /**
     * @param  array<string,mixed>  $scoreResult
     * @param  array<string,mixed>  $context
     */
    public function capture(array $scoreResult, array $context): BigFiveNormCaptureResult
    {
        $decision = $this->decide($scoreResult, $context);
        if (! $decision->allowed) {
            return $decision->status === 'skipped'
                ? BigFiveNormCaptureResult::skipped($decision->reason)
                : BigFiveNormCaptureResult::rejected($decision->reason);
        }

        $idempotencyKey = (string) $context['observation_idempotency_key'];
        $existing = BigFiveNormObservation::query()
            ->where('observation_idempotency_key', $idempotencyKey)
            ->first();

        if ($existing instanceof BigFiveNormObservation) {
            return BigFiveNormCaptureResult::duplicate((string) $existing->getKey());
        }

        $payload = $this->observationPayload($scoreResult, $context);
        $observation = BigFiveNormObservation::query()->create($payload);

        return BigFiveNormCaptureResult::captured((string) $observation->getKey());
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @param  array<string,mixed>  $context
     */
    public function decide(array $scoreResult, array $context): BigFiveNormCaptureDecision
    {
        if (($context['capture_enabled'] ?? false) !== true) {
            return BigFiveNormCaptureDecision::skip('capture_default_off');
        }

        if (($context['operation_scope'] ?? null) !== 'internal_only') {
            return BigFiveNormCaptureDecision::reject('internal_operation_scope_required');
        }

        if (($context['observation_schema_version'] ?? self::SCHEMA_VERSION) !== self::SCHEMA_VERSION) {
            return BigFiveNormCaptureDecision::reject('unsupported_schema');
        }

        foreach (['observation_idempotency_key', 'content_version', 'score_version'] as $required) {
            if (! is_string($context[$required] ?? null) || trim((string) $context[$required]) === '') {
                return BigFiveNormCaptureDecision::reject('missing_'.$required);
            }
        }

        if (($context['norm_eligibility_status'] ?? null) !== 'eligible' || ($context['norm_excluded'] ?? true) !== false) {
            return BigFiveNormCaptureDecision::reject('invalid_eligibility');
        }

        if (in_array((string) ($context['attempt_source'] ?? 'real'), ['fixture', 'staging', 'internal'], true)) {
            return BigFiveNormCaptureDecision::reject('source_excluded');
        }

        $qualityLevel = (string) ($context['quality_level'] ?? '');
        if (! in_array($qualityLevel, ['A', 'B'], true)) {
            return BigFiveNormCaptureDecision::reject('quality_level_excluded');
        }

        $qualityFlags = $this->stringList((array) ($context['quality_flags'] ?? []));
        if (array_intersect($qualityFlags, ['ATTENTION_CHECK_FAILED', 'SPEEDING', 'STRAIGHTLINING']) !== []) {
            return BigFiveNormCaptureDecision::reject('quality_flags_excluded');
        }

        if (! $this->hasScoreVector($scoreResult, 'raw_domain_scores')) {
            return BigFiveNormCaptureDecision::reject('missing_raw_domain_scores');
        }

        if (! $this->hasScoreVector($scoreResult, 'raw_facet_scores')) {
            return BigFiveNormCaptureDecision::reject('missing_raw_facet_scores');
        }

        return BigFiveNormCaptureDecision::allow();
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function observationPayload(array $scoreResult, array $context): array
    {
        $rawDomainScores = (array) $scoreResult['raw_domain_scores'];
        $rawFacetScores = (array) $scoreResult['raw_facet_scores'];
        $qualityFlags = $this->stringList((array) ($context['quality_flags'] ?? []));

        return [
            'id' => (string) Str::uuid(),
            'observation_schema_version' => self::SCHEMA_VERSION,
            'observation_idempotency_key' => (string) $context['observation_idempotency_key'],
            'observation_source' => (string) ($context['observation_source'] ?? 'norm_capture_writer'),
            'environment' => $this->optionalString($context, 'environment'),
            'scale_code' => (string) ($context['scale_code'] ?? 'BIG5_OCEAN'),
            'form_code' => $this->optionalString($context, 'form_code'),
            'content_version' => (string) $context['content_version'],
            'score_version' => (string) $context['score_version'],
            'norm_version_at_scoring' => $this->optionalString($context, 'norm_version_at_scoring'),
            'score_trace_hash' => $this->scoreTraceHash($rawDomainScores, $rawFacetScores, $context),
            'norm_eligibility_status' => 'eligible',
            'norm_excluded' => false,
            'exclusion_reasons_json' => [],
            'quality_level' => (string) $context['quality_level'],
            'quality_flags_json' => $qualityFlags,
            'locale' => $this->optionalString($context, 'locale'),
            'region' => $this->optionalString($context, 'region'),
            'age_band' => $this->optionalString($context, 'age_band'),
            'gender_bucket' => $this->optionalString($context, 'gender_bucket'),
            'occupation_bucket' => $this->optionalString($context, 'occupation_bucket'),
            'raw_domain_scores_json' => $this->sorted($rawDomainScores),
            'raw_facet_scores_json' => $this->sorted($rawFacetScores),
            'attempt_submitted_at' => $context['attempt_submitted_at'] ?? null,
            'observed_at' => $context['observed_at'] ?? now(),
        ];
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     */
    private function hasScoreVector(array $scoreResult, string $key): bool
    {
        $value = $scoreResult[$key] ?? null;

        return is_array($value) && $value !== [];
    }

    /**
     * @param  array<int|string,mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function optionalString(array $context, string $key): ?string
    {
        $value = Arr::get($context, $key);
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string,mixed>  $rawDomainScores
     * @param  array<string,mixed>  $rawFacetScores
     * @param  array<string,mixed>  $context
     */
    private function scoreTraceHash(array $rawDomainScores, array $rawFacetScores, array $context): string
    {
        $trace = [
            'schema' => self::SCHEMA_VERSION,
            'content_version' => (string) $context['content_version'],
            'score_version' => (string) $context['score_version'],
            'quality_level' => (string) $context['quality_level'],
            'raw_domain_scores' => $this->sorted($rawDomainScores),
            'raw_facet_scores' => $this->sorted($rawFacetScores),
        ];

        return hash('sha256', json_encode($trace, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private function sorted(array $values): array
    {
        ksort($values);

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->sorted($value);
            }
        }

        return $values;
    }
}
