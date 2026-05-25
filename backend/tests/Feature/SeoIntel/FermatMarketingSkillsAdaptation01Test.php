<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class FermatMarketingSkillsAdaptation01Test extends TestCase
{
    public function test_skill_drafts_and_generated_artifact_record_required_guardrails(): void
    {
        $skillPaths = [
            base_path('docs/seo/skills/fermat-product-marketing-context.md'),
            base_path('docs/seo/skills/fermat-seo-ops.md'),
            base_path('docs/seo/skills/fermat-claim-boundary.md'),
        ];

        foreach ($skillPaths as $path) {
            $this->assertFileExists($path);
        }

        $this->assertFileExists(base_path('docs/seo/fermat-marketing-skills-adaptation-01.md'));

        $artifactPath = base_path('docs/seo/generated/fermat-marketing-skills-adaptation-01.v1.json');
        $this->assertFileExists($artifactPath);

        $payload = json_decode((string) file_get_contents($artifactPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('fermat-marketing-skills-adaptation-01.v1', $payload['schema_version'] ?? null);
        $this->assertSame('FERMAT-MARKETING-SKILLS-ADAPTATION-01', $payload['task'] ?? null);

        $this->assertTrue((bool) ($payload['not_installed_upstream'] ?? false));
        $this->assertTrue((bool) ($payload['no_runtime_code_modified'] ?? false));
        $this->assertTrue((bool) ($payload['no_content_generated'] ?? false));
        $this->assertTrue((bool) ($payload['no_cms_mutation'] ?? false));
        $this->assertTrue((bool) ($payload['no_deploy'] ?? false));
        $this->assertTrue((bool) ($payload['no_search_channel_action'] ?? false));
        $this->assertTrue((bool) ($payload['no_external_search_api_call'] ?? false));
        $this->assertTrue((bool) ($payload['authority_rules_included'] ?? false));
        $this->assertTrue((bool) ($payload['search_channel_guardrails_included'] ?? false));
        $this->assertTrue((bool) ($payload['no_auto_publish_guardrails_included'] ?? false));
        $this->assertTrue((bool) ($payload['pseo_blocked_until_p0_cleanup'] ?? false));

        $this->assertIsArray($payload['claim_boundary_coverage'] ?? null);
        $this->assertNotEmpty($payload['claim_boundary_coverage']);
        $this->assertArrayHasKey('final_decision', $payload);
        $this->assertArrayHasKey('next_task', $payload);
    }
}
