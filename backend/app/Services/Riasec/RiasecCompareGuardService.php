<?php

declare(strict_types=1);

namespace App\Services\Riasec;

use App\Models\Attempt;
use App\Models\Result;

final class RiasecCompareGuardService
{
    public const VERSION = 'riasec.compare_guard.v1';

    public function __construct(
        private readonly RiasecMeasurementContract $measurementContract,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function evaluate(
        ?Attempt $attemptA,
        ?Result $resultA,
        ?Attempt $attemptB,
        ?Result $resultB
    ): array {
        $basisA = $this->resolveBasis($attemptA, $resultA);
        $basisB = $this->resolveBasis($attemptB, $resultB);

        if (($basisA['scale_code'] ?? '') !== 'RIASEC' || ($basisB['scale_code'] ?? '') !== 'RIASEC') {
            return $this->contract($basisA, $basisB, false, 'different_scale', 'riasec.compare.blocked_cross_scale');
        }

        if (($basisA['score_space_version'] ?? null) === null || ($basisB['score_space_version'] ?? null) === null) {
            return $this->contract($basisA, $basisB, false, 'missing_score_space_version', 'riasec.compare.blocked_missing_basis');
        }

        $groupA = (string) ($basisA['compare_compatibility_group'] ?? '');
        $groupB = (string) ($basisB['compare_compatibility_group'] ?? '');
        $formA = (string) ($basisA['form_code'] ?? '');
        $formB = (string) ($basisB['form_code'] ?? '');

        if ($groupA !== '' && $groupA === $groupB && $formA !== '' && $formA === $formB) {
            return $this->contract($basisA, $basisB, true, 'same_compare_compatibility_group', 'riasec.compare.allowed_same_form');
        }

        return $this->contract($basisA, $basisB, false, 'cross_form_score_space_mismatch', 'riasec.compare.blocked_cross_form');
    }

    /**
     * @return array<string,mixed>
     */
    public function summarizeAttempt(?Attempt $attempt, ?Result $result): array
    {
        $basis = $this->resolveBasis($attempt, $result);

        return [
            'version' => self::VERSION,
            'scale_code' => 'RIASEC',
            'form_code' => $basis['form_code'],
            'score_space_version' => $basis['score_space_version'],
            'compare_compatibility_group' => $basis['compare_compatibility_group'],
            'cross_form_comparable' => false,
            'raw_score_delta_allowed' => false,
            'reason' => ($basis['score_space_version'] ?? null) !== null
                ? 'same_compare_compatibility_group'
                : 'missing_score_space_version',
            'copy_key' => ($basis['score_space_version'] ?? null) !== null
                ? 'riasec.compare.allowed_same_form'
                : 'riasec.compare.blocked_missing_basis',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveBasis(?Attempt $attempt, ?Result $result): array
    {
        $payload = $this->decodeArray($result?->result_json);
        $questionCount = (int) ($attempt?->question_count ?? data_get($payload, 'answer_count', 0));
        $formCode = $this->stringOrNull(
            data_get($payload, 'measurement_contract_v1.form.form_code')
            ?? data_get($payload, 'form_code')
            ?? data_get($attempt?->answers_summary_json, 'meta.form_code')
        );
        $formCode = $this->measurementContract->canonicalFormCode($formCode, $questionCount);
        $scoreSpaceVersion = $this->stringOrNull(
            data_get($payload, 'measurement_contract_v1.form.score_space_version')
            ?? data_get($payload, 'score_space_version')
            ?? data_get($attempt?->answers_summary_json, 'meta.score_space_version')
        ) ?? $this->measurementContract->scoreSpaceVersion($formCode, $questionCount);
        $compareGroup = $this->stringOrNull(
            data_get($payload, 'measurement_contract_v1.compare_policy.compare_compatibility_group')
            ?? data_get($payload, 'compare_policy_v1.compare_compatibility_group')
            ?? data_get($payload, 'compare_compatibility_group')
            ?? data_get($attempt?->answers_summary_json, 'meta.compare_compatibility_group')
        ) ?? $this->measurementContract->compareCompatibilityGroup($formCode, $scoreSpaceVersion);

        return [
            'attempt_id' => $this->stringOrNull($attempt?->id ?? $result?->attempt_id),
            'scale_code' => strtoupper(trim((string) ($attempt?->scale_code ?? $result?->scale_code ?? 'RIASEC'))),
            'form_code' => $formCode,
            'score_space_version' => $scoreSpaceVersion,
            'compare_compatibility_group' => $compareGroup,
            'cross_form_comparable' => false,
            'raw_score_delta_allowed' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $basisA
     * @param  array<string,mixed>  $basisB
     * @return array<string,mixed>
     */
    private function contract(array $basisA, array $basisB, bool $canCompare, string $reason, string $copyKey): array
    {
        return [
            'version' => self::VERSION,
            'scale_code' => 'RIASEC',
            'can_compare' => $canCompare,
            'reason' => $reason,
            'raw_score_delta_allowed' => false,
            'attempt_a' => [
                'attempt_id' => $basisA['attempt_id'],
                'form_code' => $basisA['form_code'],
                'score_space_version' => $basisA['score_space_version'],
                'compare_compatibility_group' => $basisA['compare_compatibility_group'],
            ],
            'attempt_b' => [
                'attempt_id' => $basisB['attempt_id'],
                'form_code' => $basisB['form_code'],
                'score_space_version' => $basisB['score_space_version'],
                'compare_compatibility_group' => $basisB['compare_compatibility_group'],
            ],
            'copy_key' => $copyKey,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeArray(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (! is_string($payload) || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
