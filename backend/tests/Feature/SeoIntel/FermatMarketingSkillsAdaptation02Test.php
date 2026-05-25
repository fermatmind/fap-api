<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class FermatMarketingSkillsAdaptation02Test extends TestCase
{
    public function test_skill_drafts_and_generated_artifact_record_required_guardrails(): void
    {
        $skillPaths = [
            base_path('docs/seo/skills/fermat-ai-seo-geo.md'),
            base_path('docs/seo/skills/fermat-cro-result-report.md'),
            base_path('docs/seo/skills/fermat-digital-pr.md'),
        ];

        foreach ($skillPaths as $path) {
            $this->assertFileExists($path);
        }

        $this->assertFileExists(base_path('docs/seo/fermat-marketing-skills-adaptation-02.md'));

        $artifactPath = base_path('docs/seo/generated/fermat-marketing-skills-adaptation-02.v1.json');
        $this->assertFileExists($artifactPath);

        $payload = json_decode((string) file_get_contents($artifactPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('fermat-marketing-skills-adaptation-02.v1', $payload['schema_version'] ?? null);
        $this->assertSame('FERMAT-MARKETING-SKILLS-ADAPTATION-02', $payload['task'] ?? null);

        $this->assertTrue((bool) ($payload['not_installed_upstream'] ?? false));
        $this->assertTrue((bool) ($payload['no_runtime_code_modified'] ?? false));
        $this->assertTrue((bool) ($payload['no_content_generated'] ?? false));
        $this->assertTrue((bool) ($payload['no_cms_mutation'] ?? false));
        $this->assertTrue((bool) ($payload['no_deploy'] ?? false));
        $this->assertTrue((bool) ($payload['no_search_channel_action'] ?? false));
        $this->assertTrue((bool) ($payload['no_external_search_api_call'] ?? false));
        $this->assertTrue((bool) ($payload['no_digital_pr_send'] ?? false));
        $this->assertTrue((bool) ($payload['ai_seo_geo_guardrails_included'] ?? false));
        $this->assertTrue((bool) ($payload['schema_grounding_guardrails_included'] ?? false));
        $this->assertTrue((bool) ($payload['cro_result_report_guardrails_included'] ?? false));
        $this->assertTrue((bool) ($payload['telemetry_truth_split_included'] ?? false));
        $this->assertTrue((bool) ($payload['digital_pr_human_approval_guardrails_included'] ?? false));
        $this->assertTrue((bool) ($payload['no_bulk_outreach_guardrails_included'] ?? false));
        $this->assertTrue((bool) ($payload['no_paid_backlink_guardrails_included'] ?? false));
        $this->assertTrue((bool) ($payload['authority_rules_included'] ?? false));
        $this->assertTrue((bool) ($payload['search_channel_guardrails_included'] ?? false));

        $this->assertArrayHasKey('final_decision', $payload);
        $this->assertArrayHasKey('next_task', $payload);
    }
}
