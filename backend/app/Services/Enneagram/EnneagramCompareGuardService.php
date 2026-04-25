<?php

declare(strict_types=1);

namespace App\Services\Enneagram;

use App\Models\Attempt;
use App\Models\ReportSnapshot;
use App\Models\Result;

final class EnneagramCompareGuardService
{
    public const VERSION = 'enneagram.compare_guard.v1';

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

        if (($basisA['scale_code'] ?? '') !== 'ENNEAGRAM' || ($basisB['scale_code'] ?? '') !== 'ENNEAGRAM') {
            return $this->contract($basisA, $basisB, false, 'different_scale', 'compare.blocked_cross_form');
        }

        if (! ($basisA['has_projection_v2'] ?? false) || ! ($basisB['has_projection_v2'] ?? false)) {
            return $this->contract($basisA, $basisB, false, 'missing_projection_v2', 'compare.blocked_missing_basis');
        }

        if (($basisA['score_space_version'] ?? null) === null || ($basisB['score_space_version'] ?? null) === null) {
            return $this->contract($basisA, $basisB, false, 'missing_score_space_version', 'compare.blocked_missing_basis');
        }

        $groupA = (string) ($basisA['compare_compatibility_group'] ?? '');
        $groupB = (string) ($basisB['compare_compatibility_group'] ?? '');
        $formA = (string) ($basisA['form_code'] ?? '');
        $formB = (string) ($basisB['form_code'] ?? '');

        $sameGroup = $groupA !== '' && $groupA === $groupB;
        $sameForm = $formA !== '' && $formA === $formB;

        if ($sameGroup && $sameForm) {
            return $this->contract($basisA, $basisB, true, 'same_compare_compatibility_group', 'compare.allowed_same_form');
        }

        return $this->contract($basisA, $basisB, false, 'cross_form_score_space_mismatch', 'compare.blocked_cross_form');
    }

    /**
     * @return array<string,mixed>
     */
    public function summarizeAttempt(?Attempt $attempt, ?Result $result): array
    {
        $basis = $this->resolveBasis($attempt, $result);

        return [
            'version' => self::VERSION,
            'scale_code' => 'ENNEAGRAM',
            'form_code' => $basis['form_code'],
            'score_space_version' => $basis['score_space_version'],
            'compare_compatibility_group' => $basis['compare_compatibility_group'],
            'cross_form_comparable' => false,
            'has_projection_v2' => (bool) ($basis['has_projection_v2'] ?? false),
            'reason' => $this->basisReason($basis),
            'copy_key' => ($basis['has_projection_v2'] ?? false) && ($basis['score_space_version'] ?? null) !== null
                ? 'compare.allowed_same_form'
                : 'compare.blocked_missing_basis',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveBasis(?Attempt $attempt, ?Result $result): array
    {
        $attemptId = trim((string) ($attempt?->id ?? $result?->attempt_id ?? ''));
        $scaleCode = strtoupper(trim((string) ($attempt?->scale_code ?? $result?->scale_code ?? 'ENNEAGRAM')));
        $projection = $this->extractProjectionV2($result?->result_json);

        if ($projection === [] && $attempt instanceof Attempt) {
            $projection = $this->extractProjectionFromSnapshot($attempt);
        }

        return [
            'attempt_id' => $attemptId !== '' ? $attemptId : null,
            'scale_code' => $scaleCode,
            'form_code' => $this->stringOrNull(
                data_get($projection, 'form.form_code')
                ?? $attempt?->form_code
            ),
            'score_space_version' => $this->stringOrNull(data_get($projection, 'form.score_space_version')),
            'compare_compatibility_group' => $this->stringOrNull(data_get($projection, 'methodology.compare_compatibility_group')),
            'cross_form_comparable' => false,
            'has_projection_v2' => $projection !== [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function extractProjectionV2(mixed $payload): array
    {
        $decoded = $this->decodeArray($payload);

        $candidates = [
            data_get($decoded, 'enneagram_public_projection_v2'),
            data_get($decoded, 'report._meta.enneagram_public_projection_v2'),
            data_get($decoded, '_meta.enneagram_public_projection_v2'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && (string) ($candidate['schema_version'] ?? '') === 'enneagram.public_projection.v2') {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @return array<string,mixed>
     */
    private function extractProjectionFromSnapshot(Attempt $attempt): array
    {
        $snapshot = ReportSnapshot::query()
            ->where('org_id', (int) ($attempt->org_id ?? 0))
            ->where('attempt_id', (string) $attempt->id)
            ->where('status', 'ready')
            ->first();

        if (! $snapshot instanceof ReportSnapshot) {
            return [];
        }

        $report = is_array($snapshot->report_full_json) ? $snapshot->report_full_json : [];

        return $this->extractProjectionV2($report);
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
            'scale_code' => 'ENNEAGRAM',
            'can_compare' => $canCompare,
            'reason' => $reason,
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
     * @param  array<string,mixed>  $basis
     */
    private function basisReason(array $basis): string
    {
        if (! ($basis['has_projection_v2'] ?? false)) {
            return 'missing_projection_v2';
        }

        if (($basis['score_space_version'] ?? null) === null) {
            return 'missing_score_space_version';
        }

        return 'same_compare_compatibility_group';
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
