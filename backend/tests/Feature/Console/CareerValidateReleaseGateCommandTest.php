<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerValidateReleaseGateCommandTest extends TestCase
{
    #[Test]
    public function it_validates_live_release_gate_read_only_without_mutation(): void
    {
        Http::fake([
            'https://example.test/zh/career/jobs/actuaries' => Http::response($this->html('zh', 'actuaries'), 200),
        ]);

        $exitCode = Artisan::call('career:validate-release-gate', [
            '--slugs' => 'actuaries',
            '--locales' => 'zh',
            '--base-url' => 'https://example.test',
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('pass', $report['decision']);
        $this->assertTrue($report['read_only']);
        $this->assertFalse($report['writes_database']);
        $this->assertFalse($report['sitemap_changed']);
        $this->assertFalse($report['llms_changed']);
        $this->assertSame('pass', $report['items'][0]['Release_Gate_Result']);
        $this->assertTrue($report['items'][0]['Canonical_OK']);
        $this->assertTrue($report['items'][0]['CTA_OK']);
        $this->assertTrue($report['items'][0]['Product_Absent']);
        $this->assertTrue($report['items'][0]['Forbidden_Absent']);
    }

    #[Test]
    public function it_blocks_product_schema_and_forbidden_public_fields(): void
    {
        Http::fake([
            'https://example.test/en/career/jobs/actuaries' => Http::response(
                $this->html('en', 'actuaries', extra: '<script type="application/ld+json">{"@type":"Product"}</script> release_gates'),
                200,
            ),
        ]);

        $exitCode = Artisan::call('career:validate-release-gate', [
            '--slugs' => 'actuaries',
            '--locales' => 'en',
            '--base-url' => 'https://example.test',
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('no_go', $report['decision']);
        $this->assertSame('blocked', $report['items'][0]['Release_Gate_Result']);
        $this->assertFalse($report['items'][0]['Product_Absent']);
        $this->assertFalse($report['items'][0]['Forbidden_Absent']);
        $this->assertContains('release_gate', $report['items'][0]['Forbidden_Found']);
        $this->assertContains('release_gates', $report['items'][0]['Forbidden_Found']);
    }

    #[Test]
    public function it_validates_public_type_matrix_for_current_terminal_resolution_rows(): void
    {
        $ledgerPath = $this->writePublicResolutionLedger([
            $this->canonicalRow('accountants-and-auditors'),
            $this->nonPublicRow('duplicate-role', 'duplicate_identity_hold', 'blocked_until_governance_approval'),
            $this->nonPublicRow('cn-proxy-role', 'CN_proxy_hold', 'blocked_until_governance_approval'),
            $this->nonPublicRow('broad-role', 'broad_group_hold', 'blocked_until_governance_approval'),
            $this->nonPublicRow('software-developers', 'manual_hold', 'keep_non_public_with_policy'),
        ]);

        $exitCode = Artisan::call('career:validate-release-gate', [
            '--public-resolution-ledger' => $ledgerPath,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('career_release_gate_public_type_matrix_v0.1', $report['validator_version'] ?? null);
        $this->assertSame('pass', $report['decision']);
        $this->assertTrue($report['read_only']);
        $this->assertFalse($report['writes_database']);
        $this->assertFalse($report['sitemap_changed']);
        $this->assertFalse($report['llms_changed']);
        $this->assertSame(5, (int) ($report['validated_count'] ?? 0));
        $this->assertSame(5, (int) data_get($report, 'summary.pass'));
        $this->assertSame(0, (int) data_get($report, 'summary.blocked'));
    }

    #[Test]
    public function it_rejects_invalid_public_type_exposure(): void
    {
        $ledgerPath = $this->writePublicResolutionLedger([
            $this->canonicalRow('accountants-and-auditors'),
            [
                'source_slug' => 'duplicate-role',
                'current_status' => 'duplicate_identity_hold',
                'public_resolution_type' => 'public_canonical_job',
                'indexability' => 'indexable',
                'public_eligible' => true,
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'llms_full_eligible' => true,
            ],
            [
                'source_slug' => 'software-developers',
                'current_status' => 'manual_hold',
                'public_resolution_type' => 'public_nonindex_reference',
                'indexability' => 'noindex',
                'public_eligible' => true,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'llms_full_eligible' => false,
            ],
        ]);

        $exitCode = Artisan::call('career:validate-release-gate', [
            '--public-resolution-ledger' => $ledgerPath,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('no_go', $report['decision']);
        $this->assertSame(2, (int) data_get($report, 'summary.blocked'));

        $duplicate = collect($report['items'])->firstWhere('source_slug', 'duplicate-role');
        $software = collect($report['items'])->firstWhere('source_slug', 'software-developers');

        $this->assertContains('held_row_public_canonical_job_leakage', (array) ($duplicate['Failure_Reason'] ?? []));
        $this->assertContains('software_developers_public_leakage', (array) ($software['Failure_Reason'] ?? []));
    }

    #[Test]
    public function it_rejects_public_type_policy_gaps_for_alias_family_cn_and_nonindex(): void
    {
        $ledgerPath = $this->writePublicResolutionLedger([
            [
                'source_slug' => 'alias-role',
                'current_status' => 'duplicate_identity_hold',
                'public_resolution_type' => 'public_alias_redirect',
                'target_canonical_slug' => '',
                'indexability' => 'indexable',
                'public_eligible' => true,
                'sitemap_eligible' => true,
                'llms_eligible' => false,
                'llms_full_eligible' => false,
            ],
            [
                'source_slug' => 'family-role',
                'current_status' => 'broad_group_hold',
                'public_resolution_type' => 'public_family_hub',
                'family_hub_slug' => '',
                'child_canonical_slugs' => [],
                'schema_policy' => '',
                'trust_manifest_required' => false,
                'indexability' => 'indexable',
                'public_eligible' => true,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'llms_full_eligible' => false,
            ],
            [
                'source_slug' => 'cn-role',
                'current_status' => 'CN_proxy_hold',
                'public_resolution_type' => 'public_cn_proxy_page',
                'boundary_disclaimer_required' => false,
                'trust_manifest_required' => false,
                'indexability' => 'indexable',
                'public_eligible' => true,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'llms_full_eligible' => false,
            ],
            [
                'source_slug' => 'reference-role',
                'current_status' => 'reference_candidate',
                'public_resolution_type' => 'public_nonindex_reference',
                'indexability' => 'indexable',
                'public_eligible' => true,
                'sitemap_eligible' => false,
                'llms_eligible' => true,
                'llms_full_eligible' => false,
            ],
        ]);

        $exitCode = Artisan::call('career:validate-release-gate', [
            '--public-resolution-ledger' => $ledgerPath,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('no_go', $report['decision']);

        $alias = collect($report['items'])->firstWhere('source_slug', 'alias-role');
        $family = collect($report['items'])->firstWhere('source_slug', 'family-role');
        $cn = collect($report['items'])->firstWhere('source_slug', 'cn-role');
        $reference = collect($report['items'])->firstWhere('source_slug', 'reference-role');

        $this->assertContains('public_alias_redirect_missing_release_approved_target', (array) ($alias['Failure_Reason'] ?? []));
        $this->assertContains('public_alias_redirect_sitemap_eligible', (array) ($alias['Failure_Reason'] ?? []));
        $this->assertContains('public_family_hub_missing_child_canonical_links', (array) ($family['Failure_Reason'] ?? []));
        $this->assertContains('public_family_hub_missing_schema_policy', (array) ($family['Failure_Reason'] ?? []));
        $this->assertContains('public_cn_proxy_page_missing_disclaimer', (array) ($cn['Failure_Reason'] ?? []));
        $this->assertContains('public_cn_proxy_page_not_noindex_default', (array) ($cn['Failure_Reason'] ?? []));
        $this->assertContains('public_nonindex_reference_without_noindex', (array) ($reference['Failure_Reason'] ?? []));
        $this->assertContains('public_nonindex_reference_llms_eligible', (array) ($reference['Failure_Reason'] ?? []));
    }

    private function html(string $locale, string $slug, string $extra = ''): string
    {
        return '<!doctype html><html><head>'
            .'<link rel="canonical" href="https://example.test/'.$locale.'/career/jobs/'.$slug.'">'
            .'<meta name="robots" content="index,follow">'
            .'<script type="application/ld+json">{"@type":"FAQPage"}</script>'
            .'</head><body>'
            .'FAQ <a href="/'.$locale.'/tests/holland-career-interest-test-riasec?target_action=start_riasec_test&entry_surface=career_job_detail&subject_key='.$slug.'">test</a>'
            .$extra
            .'</body></html>';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writePublicResolutionLedger(array $rows): string
    {
        $path = storage_path('framework/testing/career-release-gate-public-type-ledger.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode([
            'public_resolution' => [
                'rows' => $rows,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalRow(string $slug): array
    {
        return [
            'source_slug' => $slug,
            'current_status' => 'already_imported_validated',
            'public_resolution_type' => 'public_canonical_job',
            'indexability' => 'indexable',
            'public_eligible' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'llms_full_eligible' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nonPublicRow(string $slug, string $status, string $type): array
    {
        return [
            'source_slug' => $slug,
            'current_status' => $status,
            'public_resolution_type' => $type,
            'indexability' => 'not_public',
            'public_eligible' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'llms_full_eligible' => false,
        ];
    }
}
