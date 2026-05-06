<?php

declare(strict_types=1);

namespace App\Services\BigFive\Norms;

use InvalidArgumentException;

final class BigFiveNormAnonymizer
{
    private const SUBJECT_KEY_PREFIX = 'b5norm_subj_v1_';

    /**
     * @param  array<string,mixed>  $subjectContext
     * @param  array<string,mixed>  $policyContext
     */
    public function subjectKey(array $subjectContext, array $policyContext): string
    {
        $this->assertCaptureBoundary($subjectContext, $policyContext);

        $stableReference = $this->requiredString($subjectContext, 'stable_subject_reference');
        $consentReference = $this->requiredString($subjectContext, 'consent_record_reference');
        $secret = $this->requiredString($policyContext, 'privacy_secret');

        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('norm_privacy_secret_too_short');
        }

        $payload = json_encode([
            'policy' => BigFiveNormPrivacyPolicy::POLICY_VERSION,
            'stable_subject_reference' => hash('sha256', $stableReference),
            'consent_record_reference' => hash('sha256', $consentReference),
            'capture_scope' => $subjectContext['capture_scope'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return self::SUBJECT_KEY_PREFIX.hash_hmac('sha256', $payload, $secret);
    }

    /**
     * @param  array<string,mixed>  $subjectContext
     * @param  array<string,mixed>  $policyContext
     */
    public function canCapture(array $subjectContext, array $policyContext): bool
    {
        try {
            $this->assertCaptureBoundary($subjectContext, $policyContext);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $subjectContext
     * @param  array<string,mixed>  $policyContext
     */
    private function assertCaptureBoundary(array $subjectContext, array $policyContext): void
    {
        if (($policyContext['capture_default'] ?? 'disabled') !== 'internal_only') {
            throw new InvalidArgumentException('norm_privacy_internal_capture_required');
        }

        if (($subjectContext['capture_scope'] ?? null) !== 'norm_observation_internal') {
            throw new InvalidArgumentException('norm_privacy_scope_required');
        }

        if (($subjectContext['consent_status'] ?? null) !== 'granted') {
            throw new InvalidArgumentException('norm_privacy_consent_required');
        }

        if (($subjectContext['consent_revoked'] ?? true) !== false) {
            throw new InvalidArgumentException('norm_privacy_consent_revoked');
        }

        if (($subjectContext['deletion_state'] ?? 'active') !== 'active') {
            throw new InvalidArgumentException('norm_privacy_deleted_or_purged');
        }
    }

    /**
     * @param  array<string,mixed>  $values
     */
    private function requiredString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('missing_'.$key);
        }

        return trim($value);
    }
}
