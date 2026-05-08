<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Models\BigFiveNormObservation;
use App\Services\BigFive\Norms\BigFiveNormAnonymizer;
use App\Services\BigFive\Norms\BigFiveNormPrivacyPolicy;
use InvalidArgumentException;
use Tests\TestCase;

final class NormPrivacyTest extends TestCase
{
    private const POLICY_PATH = 'content_assets/big5/result_page_v2/governance/norm_privacy_policy_v0_1';

    public function test_anonymizer_creates_stable_irreversible_subject_key_inside_internal_boundary(): void
    {
        $anonymizer = new BigFiveNormAnonymizer;
        $subject = $this->subjectContext();
        $policy = $this->policyContext();

        $first = $anonymizer->subjectKey($subject, $policy);
        $second = $anonymizer->subjectKey($subject, $policy);

        $this->assertSame($first, $second);
        $this->assertStringStartsWith('b5norm_subj_v1_', $first);
        $this->assertDoesNotMatchRegularExpression('/subject-123|consent-456/', $first);
        $this->assertSame(79, strlen($first));
    }

    public function test_anonymizer_requires_consent_and_rejects_revoked_or_deleted_subjects(): void
    {
        $anonymizer = new BigFiveNormAnonymizer;

        $this->expectException(InvalidArgumentException::class);
        $anonymizer->subjectKey([
            ...$this->subjectContext(),
            'consent_status' => 'missing',
        ], $this->policyContext());
    }

    public function test_anonymizer_rejects_non_internal_capture_and_short_secret(): void
    {
        $anonymizer = new BigFiveNormAnonymizer;

        $this->assertFalse($anonymizer->canCapture([
            ...$this->subjectContext(),
            'capture_scope' => 'public_delivery_boundary',
        ], $this->policyContext()));

        $this->expectException(InvalidArgumentException::class);
        $anonymizer->subjectKey($this->subjectContext(), [
            ...$this->policyContext(),
            'privacy_secret' => 'short',
        ]);
    }

    public function test_privacy_policy_defines_retention_dsar_and_small_cell_suppression(): void
    {
        $defaults = (new BigFiveNormPrivacyPolicy)->defaults();

        $this->assertSame(BigFiveNormPrivacyPolicy::POLICY_VERSION, $defaults['policy_version'] ?? null);
        $this->assertSame('internal_only', $defaults['capture_default'] ?? null);
        $this->assertSame('disabled', $defaults['public_exposure'] ?? null);
        $this->assertSame('disabled', $defaults['runtime_attachment'] ?? null);
        $this->assertTrue((bool) ($defaults['requires_explicit_consent'] ?? false));
        $this->assertTrue((bool) ($defaults['requires_revoke_handling'] ?? false));
        $this->assertSame(50, $defaults['small_cell_minimum'] ?? null);
        $this->assertSame('exclude_from_future_snapshots', $defaults['dsar_policy']['aggregation_behavior_after_revoke'] ?? null);
        $this->assertSame('exclude_from_future_aggregation', $defaults['retention_policy']['expired_record_behavior'] ?? null);
    }

    public function test_small_cell_and_public_exposure_guards_fail_closed(): void
    {
        $policy = new BigFiveNormPrivacyPolicy;

        $this->assertFalse($policy->canPublishCell(49));
        $this->assertTrue($policy->canPublishCell(50));
        $this->assertFalse($policy->hasPublicExposureRisk(['score_trace_hash' => str_repeat('a', 64)]));
        $this->assertTrue($policy->hasPublicExposureRisk(['subject_key' => 'b5norm_subj_v1_x']));
    }

    public function test_observation_model_does_not_store_direct_identifiers(): void
    {
        $fillable = (new BigFiveNormObservation)->getFillable();
        $forbidden = [
            'user'.'_id',
            'anon'.'_id',
            'e'.'mail',
            'phone',
            'session'.'_id',
            'token',
            'subject_key',
            'stable_subject_reference',
            'consent_record_reference',
        ];

        foreach ($forbidden as $field) {
            $this->assertNotContains($field, $fillable, $field);
        }
    }

    public function test_governance_package_exists_and_preserves_public_norm_no_go(): void
    {
        $manifest = $this->jsonFile('manifest.json');
        $policy = $this->jsonFile('big5_v2_norm_privacy_policy_v0_1.json');

        $this->assertSame('big5_v2_norm_privacy_policy', $manifest['package'] ?? null);
        $this->assertSame('norm_privacy_policy', $policy['mode'] ?? null);
        $this->assertSame('one_way_hmac_subject_key', $policy['pseudonymous_linkage']['strategy'] ?? null);
        $this->assertSame('forbidden', $policy['pseudonymous_linkage']['raw_identifier_storage'] ?? null);
        $this->assertSame(50, $policy['small_cell_suppression']['minimum_cell_count'] ?? null);
        $this->assertContains('dynamic_norm_engine', $policy['not_enabled_by_package'] ?? []);
        $this->assertSafetyDefaults($manifest);
        $this->assertSafetyDefaults($policy);
    }

    public function test_sha256sums_are_reproducible(): void
    {
        $entries = file(base_path(self::POLICY_PATH.'/SHA256SUMS'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($entries);

        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  [A-Za-z0-9_.-]+$/', $entry);
            [$expectedHash, $fileName] = explode('  ', $entry, 2);
            $this->assertSame($expectedHash, hash_file('sha256', base_path(self::POLICY_PATH.'/'.$fileName)), $fileName);
        }
    }

    /**
     * @return array<string,string|bool>
     */
    private function subjectContext(): array
    {
        return [
            'stable_subject_reference' => 'subject-123',
            'consent_record_reference' => 'consent-456',
            'capture_scope' => 'norm_observation_internal',
            'consent_status' => 'granted',
            'consent_revoked' => false,
            'deletion_state' => 'active',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function policyContext(): array
    {
        return [
            'capture_default' => 'internal_only',
            'privacy_secret' => str_repeat('s', 32),
        ];
    }

    /**
     * @param  array<string,mixed>  $document
     */
    private function assertSafetyDefaults(array $document): void
    {
        $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
        $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
        $this->assertFalse((bool) ($document['production_rollout_enabled'] ?? true));
        $this->assertFalse((bool) ($document['dynamic_norm_engine_attached'] ?? true));
        $this->assertFalse((bool) ($document['public_percentile_display_enabled'] ?? true));
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonFile(string $fileName): array
    {
        $json = file_get_contents(base_path(self::POLICY_PATH.'/'.$fileName));
        $this->assertIsString($json, $fileName);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded, $fileName);

        return $decoded;
    }
}
