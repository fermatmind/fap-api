<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class MarketingSkillsFermatmindFitScan00Test extends TestCase
{
    public function test_generated_fit_scan_artifact_records_required_safety_contract(): void
    {
        $path = base_path('docs/seo/generated/marketingskills-fermatmind-fit-scan-00.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('marketingskills-fermatmind-fit-scan-00.v1', $payload['schema_version'] ?? null);
        $this->assertSame('MARKETINGSKILLS-FERMATMIND-FIT-SCAN-00', $payload['task'] ?? null);

        $this->assertTrue((bool) ($payload['no_install_performed'] ?? false));
        $this->assertTrue((bool) ($payload['no_runtime_code_modified'] ?? false));
        $this->assertTrue((bool) ($payload['no_content_generated'] ?? false));
        $this->assertTrue((bool) ($payload['no_search_channel_action_performed'] ?? false));
        $this->assertTrue((bool) ($payload['no_deploy_performed'] ?? false));

        $this->assertIsArray($payload['directly_useful_skills'] ?? null);
        $this->assertIsArray($payload['useful_after_adaptation_skills'] ?? null);
        $this->assertIsArray($payload['risk_findings'] ?? null);
        $this->assertIsArray($payload['adoption_phases'] ?? null);

        $this->assertArrayHasKey('final_decision', $payload);
        $this->assertArrayHasKey('recommended_next_task', $payload);
        $this->assertSame(
            'marketingskills_fit_scan_completed_ready_for_internal_skill_adaptation',
            $payload['final_decision']
        );
        $this->assertSame('FERMAT-MARKETING-SKILLS-ADAPTATION-01', $payload['recommended_next_task']);

        $directSkills = array_column($payload['directly_useful_skills'], 'skill');
        $this->assertContains('seo-audit', $directSkills);
        $this->assertContains('ai-seo', $directSkills);
        $this->assertContains('schema', $directSkills);
        $this->assertContains('cro', $directSkills);

        $riskIds = array_column($payload['risk_findings'], 'id');
        $this->assertContains('risk_mass_pseo_generation', $riskIds);
        $this->assertContains('risk_search_channel_bypass', $riskIds);
    }
}
