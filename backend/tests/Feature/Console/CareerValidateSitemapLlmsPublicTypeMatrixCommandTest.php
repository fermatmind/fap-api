<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerValidateSitemapLlmsPublicTypeMatrixCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_validates_current_canonical_and_non_public_sitemap_llms_policy(): void
    {
        $ledgerPath = $this->writeLedgerFixture([
            $this->canonicalRow('accountants-and-auditors'),
            $this->canonicalRow('registered-nurses'),
            $this->nonPublicRow('duplicate-role', 'duplicate_identity_hold', 'blocked_until_governance_approval'),
            $this->nonPublicRow('cn-role', 'CN_proxy_hold', 'blocked_until_governance_approval'),
            $this->nonPublicRow('broad-role', 'broad_group_hold', 'blocked_until_governance_approval'),
            $this->nonPublicRow('software-developers', 'manual_hold', 'keep_non_public_with_policy'),
        ]);
        $outputPath = storage_path('framework/testing/career-sitemap-llms-public-type-matrix.json');
        File::delete($outputPath);

        $exitCode = Artisan::call('career:validate-sitemap-llms-public-type-matrix', [
            '--ledger' => $ledgerPath,
            '--locales' => 'zh,en',
            '--output' => $outputPath,
            '--json' => true,
        ]);
        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertIsArray($payload);
        $this->assertSame('validated', $payload['status'] ?? null);
        $this->assertTrue((bool) ($payload['read_only'] ?? false));
        $this->assertFalse((bool) ($payload['did_write'] ?? true));
        $this->assertSame(2, (int) ($payload['canonical_job_rows'] ?? 0));
        $this->assertSame(4, (int) ($payload['canonical_career_job_urls'] ?? 0));
        $this->assertSame(0, (int) ($payload['alias_urls_in_sitemap'] ?? -1));
        $this->assertSame(0, (int) ($payload['CN_urls_in_sitemap'] ?? -1));
        $this->assertSame(0, (int) ($payload['nonindex_reference_urls_in_sitemap'] ?? -1));
        $this->assertTrue((bool) ($payload['software_developers_absent'] ?? false));
        $this->assertSame(0, (int) ($payload['sitemap_bad_count'] ?? -1));
        $this->assertSame(0, (int) ($payload['llms_bad_count'] ?? -1));
        $this->assertSame(0, (int) ($payload['llms_full_bad_count'] ?? -1));
        $this->assertSame([], $payload['blockers'] ?? null);
        $this->assertFileExists($outputPath);
    }

    public function test_it_rejects_non_canonical_public_types_in_sitemap_or_llms(): void
    {
        $ledgerPath = $this->writeLedgerFixture([
            $this->canonicalRow('accountants-and-auditors'),
            $this->leakyRow('alias-role', 'duplicate_identity_hold', 'public_alias_redirect'),
            $this->leakyRow('cn-role', 'CN_proxy_hold', 'public_cn_proxy_page'),
            $this->leakyRow('reference-role', 'reference_candidate', 'public_nonindex_reference'),
            $this->leakyRow('duplicate-role', 'duplicate_identity_hold', 'blocked_until_governance_approval'),
        ]);

        $exitCode = Artisan::call('career:validate-sitemap-llms-public-type-matrix', [
            '--ledger' => $ledgerPath,
            '--json' => true,
        ]);
        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($payload);
        $this->assertContains('public_alias_redirect_sitemap_llms_leakage', (array) ($payload['blockers'] ?? []));
        $this->assertContains('public_cn_proxy_page_sitemap_llms_leakage', (array) ($payload['blockers'] ?? []));
        $this->assertContains('public_nonindex_reference_sitemap_llms_leakage', (array) ($payload['blockers'] ?? []));
        $this->assertContains('non_public_type_sitemap_llms_leakage', (array) ($payload['blockers'] ?? []));
    }

    public function test_it_rejects_held_rows_even_when_marked_as_public_canonical_jobs(): void
    {
        $ledgerPath = $this->writeLedgerFixture([
            $this->canonicalRow('accountants-and-auditors'),
            [
                'source_slug' => 'held-canonical-row',
                'current_status' => 'manual_hold',
                'public_resolution_type' => 'public_canonical_job',
                'indexability' => 'indexable',
                'public_eligible' => true,
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'llms_full_eligible' => true,
            ],
        ]);

        $exitCode = Artisan::call('career:validate-sitemap-llms-public-type-matrix', [
            '--ledger' => $ledgerPath,
            '--json' => true,
        ]);
        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($payload);
        $this->assertSame(1, (int) ($payload['held_canonical_job_rows'] ?? 0));
        $this->assertContains('held_public_canonical_job_rows', (array) ($payload['blockers'] ?? []));
    }

    public function test_it_allows_family_hub_sitemap_llms_only_with_explicit_required_fields(): void
    {
        $ledgerPath = $this->writeLedgerFixture([
            $this->canonicalRow('accountants-and-auditors'),
            [
                'source_slug' => 'engineering-family',
                'current_status' => 'broad_group_hold',
                'public_resolution_type' => 'public_family_hub',
                'public_eligible' => true,
                'indexability' => 'indexable',
                'family_hub_slug' => 'engineering-careers',
                'child_canonical_slugs' => ['software-engineers', 'systems-engineers'],
                'schema_policy' => 'family_hub_schema_v1',
                'trust_manifest_required' => true,
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'llms_full_eligible' => true,
            ],
        ]);

        $exitCode = Artisan::call('career:validate-sitemap-llms-public-type-matrix', [
            '--ledger' => $ledgerPath,
            '--json' => true,
        ]);
        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertIsArray($payload);
        $this->assertSame(1, (int) ($payload['family_urls_in_sitemap'] ?? 0));
        $this->assertSame(1, (int) ($payload['family_urls_in_llms'] ?? 0));
        $this->assertSame(1, (int) ($payload['family_urls_in_llms_full'] ?? 0));
        $this->assertSame([], $payload['blockers'] ?? null);
    }

    public function test_it_rejects_family_hub_sitemap_llms_without_schema_children_and_trust(): void
    {
        $ledgerPath = $this->writeLedgerFixture([
            $this->canonicalRow('accountants-and-auditors'),
            [
                'source_slug' => 'bad-family',
                'current_status' => 'broad_group_hold',
                'public_resolution_type' => 'public_family_hub',
                'public_eligible' => true,
                'indexability' => 'indexable',
                'family_hub_slug' => '',
                'child_canonical_slugs' => [],
                'schema_policy' => '',
                'trust_manifest_required' => false,
                'sitemap_eligible' => true,
                'llms_eligible' => false,
                'llms_full_eligible' => false,
            ],
        ]);

        $exitCode = Artisan::call('career:validate-sitemap-llms-public-type-matrix', [
            '--ledger' => $ledgerPath,
            '--json' => true,
        ]);
        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($payload);
        $this->assertContains('public_family_hub_sitemap_llms_without_schema_children_trust', (array) ($payload['blockers'] ?? []));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeLedgerFixture(array $rows): string
    {
        $path = storage_path('framework/testing/career-sitemap-llms-public-type-ledger.json');
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

    /**
     * @return array<string, mixed>
     */
    private function leakyRow(string $slug, string $status, string $type): array
    {
        return [
            'source_slug' => $slug,
            'current_status' => $status,
            'public_resolution_type' => $type,
            'indexability' => $type === 'blocked_until_governance_approval' ? 'not_public' : 'noindex',
            'public_eligible' => $type !== 'blocked_until_governance_approval',
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'llms_full_eligible' => true,
        ];
    }
}
