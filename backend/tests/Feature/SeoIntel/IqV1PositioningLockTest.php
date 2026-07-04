<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class IqV1PositioningLockTest extends TestCase
{
    public function test_iq_v1_positioning_lock_blocks_high_risk_claim_classes(): void
    {
        $policy = $this->positioningLockPolicy();

        $this->assertSame('iq.v1.positioning_lock.v1', data_get($policy, 'schema'));
        $this->assertSame('IQ-V1-POSITIONING-LOCK-01', data_get($policy, 'pr_id'));
        $this->assertSame('backend_policy_artifact', data_get($policy, 'authority.owner'));
        $this->assertSame('backend_cms_or_public_api', data_get($policy, 'authority.runtime_copy_authority'));
        $this->assertFalse((bool) data_get($policy, 'authority.frontend_fallback_copy_authority'));
        $this->assertFalse((bool) data_get($policy, 'authority.cms_write_allowed_in_this_pr'));
        $this->assertFalse((bool) data_get($policy, 'authority.production_deploy_allowed_in_this_pr'));

        $this->assertTrue((bool) data_get($policy, 'result_authority.backend_report_required'));
        $this->assertSame('backend_owned_random_baseline', data_get($policy, 'result_authority.beta_standard_score_source'));
        $this->assertFalse((bool) data_get($policy, 'result_authority.iq_estimate_public_copy_enabled'));
        $this->assertFalse((bool) data_get($policy, 'result_authority.percentile_public_copy_enabled'));
        $this->assertTrue((bool) data_get($policy, 'result_authority.production_norm_required_for_iq_estimate'));
        $this->assertSame('backend_cms_media_library', data_get($policy, 'media_authority.source'));
        $this->assertFalse((bool) data_get($policy, 'media_authority.frontend_fallback_media_authority'));

        $forbiddenClasses = collect(data_get($policy, 'forbidden_claim_classes', []))
            ->mapWithKeys(fn (array $row): array => [(string) ($row['id'] ?? '') => (bool) ($row['blocked'] ?? false)]);

        foreach ([
            'official_iq_certification',
            'clinical_or_diagnostic_iq',
            'mensa_or_official_affiliation',
            'admission_hiring_salary_or_career_guarantee',
            'fixed_intelligence_conclusion',
            'public_iq_estimate_without_norm_authority',
            'population_percentile_without_norm_authority',
            'pdf_or_certificate_public_claim',
            'paid_report_public_seo_claim',
        ] as $classId) {
            $this->assertTrue((bool) $forbiddenClasses->get($classId), $classId);
        }
    }

    public function test_iq_v1_allowed_public_examples_do_not_cross_positioning_boundaries(): void
    {
        $policy = $this->positioningLockPolicy();

        foreach (data_get($policy, 'public_copy_switches', []) as $switch => $enabled) {
            $this->assertFalse((bool) $enabled, (string) $switch);
        }

        $safePublicCopy = strtolower(implode(' ', [
            implode(' ', data_get($policy, 'allowed_positioning', [])),
            implode(' ', data_get($policy, 'allowed_public_copy_examples', [])),
        ]));

        foreach (data_get($policy, 'forbidden_public_copy_terms', []) as $forbiddenTerm) {
            $this->assertStringNotContainsString(strtolower((string) $forbiddenTerm), $safePublicCopy, (string) $forbiddenTerm);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function positioningLockPolicy(): array
    {
        $path = base_path('docs/seo/iq-v1/iq-v1-positioning-lock.v1.json');
        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
