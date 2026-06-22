<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\ContentPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TrustMethodContentPagesDryRunPackageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function package_preserves_draft_only_no_write_boundaries(): void
    {
        $package = $this->sourceRows();
        $artifact = $this->artifact();

        $this->assertSame('trust-method-content-pages-dry-run-package.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('FA30-API-08', $artifact['task'] ?? null);
        $this->assertSame('cms_dry_run_package_only', $artifact['mode'] ?? null);
        $this->assertSame('content-pages:import-local-baseline', $artifact['runtime_importer'] ?? null);
        $this->assertSame(4, data_get($artifact, 'candidate_summary.candidate_count'));
        $this->assertSame(4, count($package));
        $this->assertSame(4, data_get($artifact, 'acceptance.expected_dry_run_will_create'));
        $this->assertSame('FA30-WEB-07', $artifact['next_task'] ?? null);

        $expectedSlugs = [
            'assessment-method',
            'data-privacy-method',
            'review-and-evidence-process',
            'score-interpretation-boundaries',
        ];
        $actualSlugs = array_column($package, 'slug');
        sort($actualSlugs);
        $this->assertSame($expectedSlugs, $actualSlugs);

        foreach ($package as $row) {
            $this->assertSame('policy', $row['kind'] ?? null);
            $this->assertSame('policy', $row['template'] ?? null);
            $this->assertSame('en', $row['locale'] ?? null);
            $this->assertSame('draft', $row['translationStatus'] ?? null);
            $this->assertFalse((bool) ($row['isPublic'] ?? true));
            $this->assertFalse((bool) ($row['isIndexable'] ?? true));
            $this->assertFalse((bool) ($row['schema_enabled'] ?? true));
            $this->assertFalse((bool) ($row['publish_allowed'] ?? true));
            $this->assertTrue((bool) ($row['operator_approval_required'] ?? false));
            $this->assertSame('not_reviewed', $row['claim_gate_status'] ?? null);
            $this->assertSame('support@fermatmind.com', $row['support_contact'] ?? null);
            $this->assertSame('trust_method_draft.v1', $row['policy_version'] ?? null);
            $this->assertStringStartsWith('/', (string) ($row['path'] ?? ''));
            $this->assertSame($row['path'] ?? null, $row['canonicalPath'] ?? null);
            $this->assertNotEmpty($row['contentMd'] ?? '');
            $this->assertContains($row['pageType'] ?? null, ['methodology', 'boundary', 'privacy', 'trust']);
            $this->assertNotEmpty($row['forbidden_claims'] ?? []);
        }

        foreach ($artifact['negative_guarantees'] ?? [] as $flag => $value) {
            $this->assertFalse((bool) $value, $flag.' must remain false');
        }

        $this->assertFalse((bool) data_get($artifact, 'authority_boundary.frontend_fallback_authority_allowed', true));
        $this->assertFalse((bool) data_get($artifact, 'authority_boundary.cms_write_authorized_in_this_pr', true));
        $this->assertFalse((bool) data_get($artifact, 'authority_boundary.publication_authorized_in_this_pr', true));
        $this->assertFalse((bool) data_get($artifact, 'authority_boundary.indexability_authorized_in_this_pr', true));
    }

    #[Test]
    public function importer_dry_run_reports_create_plan_without_writing_rows(): void
    {
        $this->assertSame(0, ContentPage::query()->withoutGlobalScopes()->count());

        $this->artisan('content-pages:import-local-baseline', [
            '--dry-run' => true,
            '--status' => ContentPage::STATUS_DRAFT,
            '--source-dir' => 'docs/seo/import-packages/trust-method-content-pages-dry-run',
        ])
            ->expectsOutputToContain('dry_run=1')
            ->expectsOutputToContain('upsert=0')
            ->expectsOutputToContain('status_mode=draft')
            ->expectsOutputToContain('pages_found=4')
            ->expectsOutputToContain('will_create=4')
            ->expectsOutputToContain('will_update=0')
            ->expectsOutputToContain('will_skip=0')
            ->assertExitCode(0);

        $this->assertSame(0, ContentPage::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function package_does_not_contain_private_or_submission_patterns(): void
    {
        $artifact = $this->artifact();
        $forbiddenPatterns = $artifact['forbidden_fields_or_patterns'] ?? [];
        unset($artifact['forbidden_fields_or_patterns']);

        $serialized = json_encode([
            'source' => $this->sourceRows(),
            'artifact' => $artifact,
            'report' => file_get_contents(base_path('docs/seo/trust-method-content-pages-dry-run-package.md')),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        foreach ($forbiddenPatterns as $pattern) {
            $this->assertStringNotContainsString((string) $pattern, $serialized);
        }

        $this->assertDoesNotMatchRegularExpression('/token=/i', $serialized);
        $this->assertDoesNotMatchRegularExpression('/payment[_ -]?id/i', $serialized);
        $this->assertDoesNotMatchRegularExpression('/transaction[_ -]?id/i', $serialized);
        $this->assertDoesNotMatchRegularExpression('/redis:\\/\\//i', $serialized);
        $this->assertDoesNotMatchRegularExpression('/mysql:\\/\\//i', $serialized);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function sourceRows(): array
    {
        $path = base_path('docs/seo/import-packages/trust-method-content-pages-dry-run/content_pages.trust_method_drafts_01.json');

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string,mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/trust-method-content-pages-dry-run-package.v1.json');

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
