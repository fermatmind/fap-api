<?php

declare(strict_types=1);

namespace Tests\Unit\Ci;

use App\Services\Ci\ScaleImpactResolver;
use PHPUnit\Framework\TestCase;

final class ScaleImpactResolverTest extends TestCase
{
    public function test_shared_layer_change_triggers_full_regression(): void
    {
        $resolver = new ScaleImpactResolver();
        $result = $resolver->resolve([
            'backend/app/Services/Template/TemplateEngine.php',
        ]);

        $this->assertTrue((bool) ($result['shared_changed'] ?? false));
        $this->assertTrue((bool) ($result['run_full_scale_regression'] ?? false));
        $this->assertTrue((bool) ($result['run_big5_ocean_gate'] ?? false));
        $this->assertSame('full_regression', (string) ($result['scale_scope'] ?? ''));
    }

    public function test_big5_only_change_runs_big5_gate_and_keeps_mbti_smoke(): void
    {
        $resolver = new ScaleImpactResolver();
        $result = $resolver->resolve([
            'backend/content_packs/BIG5_OCEAN/v1/raw/questions_big5_bilingual.csv',
        ]);

        $this->assertFalse((bool) ($result['shared_changed'] ?? true));
        $this->assertTrue((bool) ($result['big5_ocean_changed'] ?? false));
        $this->assertTrue((bool) ($result['run_big5_ocean_gate'] ?? false));
        $this->assertTrue((bool) ($result['run_mbti_smoke'] ?? false));
        $this->assertSame('big5_with_mbti_smoke', (string) ($result['scale_scope'] ?? ''));
    }

    public function test_mbti_only_change_skips_big5_gate(): void
    {
        $resolver = new ScaleImpactResolver();
        $result = $resolver->resolve([
            'content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3/questions.json',
        ]);

        $this->assertFalse((bool) ($result['shared_changed'] ?? true));
        $this->assertTrue((bool) ($result['mbti_changed'] ?? false));
        $this->assertFalse((bool) ($result['run_big5_ocean_gate'] ?? true));
        $this->assertSame('mbti_only', (string) ($result['scale_scope'] ?? ''));
    }

    public function test_clinical_only_change_runs_clinical_gate_and_keeps_mbti_smoke(): void
    {
        $resolver = new ScaleImpactResolver();
        $result = $resolver->resolve([
            'backend/content_packs/CLINICAL_COMBO_68/v1/raw/policy.json',
        ]);

        $this->assertFalse((bool) ($result['shared_changed'] ?? true));
        $this->assertTrue((bool) ($result['clinical_combo_68_changed'] ?? false));
        $this->assertTrue((bool) ($result['run_clinical_combo_68_gate'] ?? false));
        $this->assertTrue((bool) ($result['run_mbti_smoke'] ?? false));
        $this->assertSame('clinical_with_mbti_smoke', (string) ($result['scale_scope'] ?? ''));
    }

    public function test_sds_only_change_runs_sds_gate_and_keeps_mbti_smoke(): void
    {
        $resolver = new ScaleImpactResolver();
        $result = $resolver->resolve([
            'backend/content_packs/SDS_20/v1/raw/policy.json',
        ]);

        $this->assertFalse((bool) ($result['shared_changed'] ?? true));
        $this->assertTrue((bool) ($result['sds_20_changed'] ?? false));
        $this->assertTrue((bool) ($result['run_sds_20_gate'] ?? false));
        $this->assertTrue((bool) ($result['run_mbti_smoke'] ?? false));
        $this->assertSame('sds_with_mbti_smoke', (string) ($result['scale_scope'] ?? ''));
    }

    public function test_sds_norms_change_runs_sds_norms_gate(): void
    {
        $resolver = new ScaleImpactResolver();
        $result = $resolver->resolve([
            'backend/config/sds_norms.php',
        ]);

        $this->assertFalse((bool) ($result['shared_changed'] ?? true));
        $this->assertTrue((bool) ($result['sds_norms_changed'] ?? false));
        $this->assertTrue((bool) ($result['run_sds_norms_gate'] ?? false));
        $this->assertFalse((bool) ($result['run_sds_20_gate'] ?? true));
    }

    public function test_sds_content_change_does_not_force_sds_norms_gate(): void
    {
        $resolver = new ScaleImpactResolver();
        $result = $resolver->resolve([
            'backend/content_packs/SDS_20/v1/raw/policy.json',
        ]);

        $this->assertTrue((bool) ($result['sds_20_changed'] ?? false));
        $this->assertTrue((bool) ($result['run_sds_20_gate'] ?? false));
        $this->assertFalse((bool) ($result['run_sds_norms_gate'] ?? true));
    }

    public function test_shared_layer_change_enables_sds_gate_too(): void
    {
        $resolver = new ScaleImpactResolver();
        $result = $resolver->resolve([
            'backend/app/Services/Report/ReportGatekeeper.php',
        ]);

        $this->assertTrue((bool) ($result['shared_changed'] ?? false));
        $this->assertTrue((bool) ($result['run_sds_20_gate'] ?? false));
        $this->assertTrue((bool) ($result['run_sds_norms_gate'] ?? false));
        $this->assertSame('full_regression', (string) ($result['scale_scope'] ?? ''));
    }
}
