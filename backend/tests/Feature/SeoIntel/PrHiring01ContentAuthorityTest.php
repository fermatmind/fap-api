<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PrHiring01ContentAuthorityTest extends TestCase
{
    #[Test]
    public function generated_artifact_records_non_runtime_boundaries(): void
    {
        $payload = $this->artifact();

        $this->assertSame('pr_hiring_01_content_authority.v1', $payload['schema_version'] ?? null);
        $this->assertSame('PR-HIRING-01', $payload['task'] ?? null);
        $this->assertSame('backend_content_pages_and_operator_reviewed_import_package', $payload['source_of_truth'] ?? null);
        $this->assertSame(3, $payload['roles_count'] ?? null);
        $this->assertTrue((bool) data_get($payload, 'careers_content_page_topology.en_baseline_contains_careers'));
        $this->assertTrue((bool) data_get($payload, 'careers_content_page_topology.zh_baseline_contains_careers'));
        $this->assertFalse((bool) data_get($payload, 'careers_content_page_topology.runtime_publish_changed_by_this_pr'));

        foreach ([
            'no_cms_mutation',
            'no_publish',
            'no_deploy',
            'no_search_channel_action',
            'no_url_submission',
            'no_frontend_fallback_authority',
            'draft_import_not_exposed',
        ] as $flag) {
            $this->assertTrue((bool) ($payload[$flag] ?? false), $flag);
        }
    }

    #[Test]
    public function import_package_contains_three_draft_roles_and_no_exposure(): void
    {
        $payload = $this->importPackage();

        $this->assertSame('pr_hiring_01_import.v1', $payload['schema_version'] ?? null);
        $this->assertTrue((bool) ($payload['non_runtime'] ?? false));
        $this->assertSame('draft_review_only', $payload['publish_state'] ?? null);
        $this->assertFalse((bool) ($payload['cms_mutation_performed'] ?? true));
        $this->assertFalse((bool) ($payload['runtime_exposure_allowed'] ?? true));
        $this->assertFalse((bool) ($payload['sitemap_llms_exposure_allowed'] ?? true));
        $this->assertFalse((bool) ($payload['footer_exposure_allowed'] ?? true));
        $this->assertFalse((bool) ($payload['search_channel_action_allowed'] ?? true));
        $this->assertSame('careers', $payload['page_key'] ?? null);
        $this->assertSame('en', $payload['locale'] ?? null);

        $roles = collect($payload['roles'] ?? [])->keyBy('role_key');

        $this->assertEqualsCanonicalizing([
            'technical-partner-engineering-lead',
            'product-design-brand-systems-lead',
            'growth-seo-content-operations-lead',
        ], $roles->keys()->all());

        foreach ($roles as $role) {
            $this->assertSame('draft_review_only', $role['status'] ?? null);
            $this->assertNotEmpty($role['intro'] ?? null);
            $this->assertNotEmpty($role['responsibilities'] ?? null);
            $this->assertNotEmpty($role['review_notes'] ?? null);
        }
    }

    #[Test]
    public function hiring_package_remains_claim_safe(): void
    {
        $artifact = $this->artifact();
        $importPackage = $this->importPackage();

        $this->assertSame([], data_get($artifact, 'claim_boundary_status.forbidden_claim_hits'));
        $this->assertSame([], data_get($importPackage, 'claim_boundary.forbidden_claim_hits'));

        $text = strtolower(json_encode([$artifact, $importPackage], JSON_THROW_ON_ERROR));

        foreach ([
            'salary guarantee',
            'career success guarantee',
            'hiring suitability guarantee',
            'best career for you',
            'diagnosis product',
            'treatment product',
            'cure outcome',
            'equity transfer',
            'guaranteed compensation',
            'psychometric job success prediction',
        ] as $forbiddenPhrase) {
            $this->assertStringNotContainsString($forbiddenPhrase, $text, $forbiddenPhrase);
        }
    }

    #[Test]
    public function report_has_required_sections_and_next_task(): void
    {
        $reportPath = base_path('docs/seo/pr-hiring-01-content-authority.md');

        $this->assertFileExists($reportPath);

        $report = (string) file_get_contents($reportPath);

        foreach ([
            '## 1. Executive Summary',
            '## 2. Authority Boundary',
            '## 3. Draft Roles',
            '## 4. Discoverability Boundary',
            '## 5. Claim Boundary',
            '## 6. Validation',
            '## 7. What Was Not Done',
            '## 8. Final Decision',
            '## 9. Next Task',
        ] as $heading) {
            $this->assertStringContainsString($heading, $report);
        }

        $this->assertSame(
            'pr_hiring_content_authority_package_ready_for_human_review',
            $this->artifact()['final_decision'] ?? null,
        );
        $this->assertSame(
            'CAREER-1046-INTERNAL-LINKING-AUTHORITY-01',
            $this->artifact()['next_task'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/pr-hiring-01-content-authority.v1.json');

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function importPackage(): array
    {
        $path = base_path('docs/seo/import-packages/pr-hiring-01.import.v1.json');

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
