<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerValidatePublicNonindexReferenceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_validates_current_public_nonindex_reference_state_as_no_op(): void
    {
        $ledgerPath = $this->writeLedgerFixture();
        $outputPath = storage_path('framework/testing/career-public-nonindex-reference-dry-run.json');
        File::delete($outputPath);

        $exitCode = Artisan::call('career:validate-public-nonindex-reference', [
            '--ledger' => $ledgerPath,
            '--dry-run' => true,
            '--timestamp' => 'career-public-nonindex-reference-test',
            '--output' => $outputPath,
            '--json' => true,
        ]);

        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertIsArray($payload);
        $this->assertSame('validated', $payload['status'] ?? null);
        $this->assertTrue((bool) ($payload['dry_run'] ?? false));
        $this->assertFalse((bool) ($payload['did_write'] ?? true));
        $this->assertSame(2, (int) ($payload['ledger_rows'] ?? 0));
        $this->assertSame(0, (int) ($payload['public_nonindex_reference_rows'] ?? -1));
        $this->assertSame(0, (int) ($payload['active_public_nonindex_reference_urls'] ?? -1));
        $this->assertSame(0, (int) ($payload['public_urls_created'] ?? -1));
        $this->assertSame(0, (int) ($payload['sitemap_nonindex_reference_urls'] ?? -1));
        $this->assertSame(0, (int) ($payload['llms_nonindex_reference_urls'] ?? -1));
        $this->assertSame(0, (int) ($payload['llms_full_nonindex_reference_urls'] ?? -1));
        $this->assertTrue((bool) ($payload['ledger_decision_required'] ?? false));
        $this->assertTrue((bool) ($payload['noindex_required'] ?? false));
        $this->assertFalse((bool) ($payload['sitemap_eligible_default'] ?? true));
        $this->assertFalse((bool) ($payload['llms_eligible_default'] ?? true));
        $this->assertFalse((bool) ($payload['llms_full_eligible_default'] ?? true));
        $this->assertFalse((bool) ($payload['manifest_eligible'] ?? true));
        $this->assertFalse((bool) ($payload['held_rows_can_use_nonindex_as_bypass'] ?? true));
        $this->assertFalse((bool) ($payload['software_developers_can_use_nonindex_without_manual_decision'] ?? true));
        $this->assertSame([], $payload['blockers'] ?? null);
        $this->assertFileExists($outputPath);
    }

    public function test_it_accepts_future_ledger_approved_noindex_reference_when_noindex_and_excluded_from_sitemap_llms(): void
    {
        $ledgerPath = $this->writeLedgerFixture(extraRows: [[
            'source_slug' => 'reviewed-reference',
            'current_status' => 'reference_candidate',
            'governance_decision' => 'reviewed_public_nonindex_reference',
            'public_resolution_type' => 'public_nonindex_reference',
            'indexability' => 'noindex',
            'public_eligible' => true,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'llms_full_eligible' => false,
        ]]);

        $exitCode = Artisan::call('career:validate-public-nonindex-reference', [
            '--ledger' => $ledgerPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertIsArray($payload);
        $this->assertSame(1, (int) ($payload['public_nonindex_reference_rows'] ?? 0));
        $this->assertSame([], $payload['blockers'] ?? null);
    }

    public function test_it_rejects_public_nonindex_reference_without_noindex(): void
    {
        $ledgerPath = $this->writeLedgerFixture(extraRows: [[
            'source_slug' => 'bad-reference',
            'current_status' => 'reference_candidate',
            'public_resolution_type' => 'public_nonindex_reference',
            'indexability' => 'indexable',
            'public_eligible' => true,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'llms_full_eligible' => false,
        ]]);

        $exitCode = Artisan::call('career:validate-public-nonindex-reference', [
            '--ledger' => $ledgerPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($payload);
        $this->assertContains('public_nonindex_reference_without_noindex', (array) ($payload['blockers'] ?? []));
    }

    public function test_it_rejects_public_nonindex_reference_in_sitemap_or_llms(): void
    {
        $ledgerPath = $this->writeLedgerFixture(extraRows: [[
            'source_slug' => 'leaky-reference',
            'current_status' => 'reference_candidate',
            'public_resolution_type' => 'public_nonindex_reference',
            'indexability' => 'noindex',
            'public_eligible' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'llms_full_eligible' => true,
        ]]);

        $exitCode = Artisan::call('career:validate-public-nonindex-reference', [
            '--ledger' => $ledgerPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($payload);
        $this->assertContains('public_nonindex_reference_sitemap_eligible', (array) ($payload['blockers'] ?? []));
        $this->assertContains('public_nonindex_reference_llms_eligible', (array) ($payload['blockers'] ?? []));
        $this->assertContains('public_nonindex_reference_llms_full_eligible', (array) ($payload['blockers'] ?? []));
    }

    public function test_it_rejects_held_rows_using_nonindex_reference_as_public_bypass(): void
    {
        $ledgerPath = $this->writeLedgerFixture(extraRows: [[
            'source_slug' => 'duplicate-hold',
            'current_status' => 'duplicate_identity_hold',
            'public_resolution_type' => 'public_nonindex_reference',
            'indexability' => 'noindex',
            'public_eligible' => true,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'llms_full_eligible' => false,
        ]]);

        $exitCode = Artisan::call('career:validate-public-nonindex-reference', [
            '--ledger' => $ledgerPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($payload);
        $this->assertContains('held_row_public_nonindex_reference_bypass', (array) ($payload['blockers'] ?? []));
    }

    public function test_it_rejects_software_developers_as_nonindex_reference_without_manual_decision(): void
    {
        $ledgerPath = $this->writeLedgerFixture(extraRows: [[
            'source_slug' => 'software-developers',
            'current_status' => 'manual_hold',
            'public_resolution_type' => 'public_nonindex_reference',
            'indexability' => 'noindex',
            'public_eligible' => true,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'llms_full_eligible' => false,
        ]]);

        $exitCode = Artisan::call('career:validate-public-nonindex-reference', [
            '--ledger' => $ledgerPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($payload);
        $this->assertContains('software_developers_public_nonindex_reference_without_manual_decision', (array) ($payload['blockers'] ?? []));
    }

    /**
     * @param  list<array<string, mixed>>  $extraRows
     */
    private function writeLedgerFixture(array $extraRows = []): string
    {
        $path = storage_path('framework/testing/career-public-resolution-ledger.json');
        File::ensureDirectoryExists(dirname($path));

        $rows = [
            [
                'source_slug' => 'accountants-and-auditors',
                'current_status' => 'already_imported_validated',
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
                'public_resolution_type' => 'keep_non_public_with_policy',
                'indexability' => 'not_public',
                'public_eligible' => false,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'llms_full_eligible' => false,
            ],
            ...$extraRows,
        ];

        File::put($path, (string) json_encode([
            'public_resolution' => [
                'rows' => $rows,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}
