<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\ContentPage;
use App\Services\SeoIntel\CompetitorAlternativesSourceLedgerValidator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CompetitorAlternativesSourceLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function source_ledger_defines_internal_noindex_entries_with_required_review_gates(): void
    {
        $source = $this->sourcePackage();
        $artifact = $this->artifact();

        $this->assertSame('competitor-alternatives-source-ledger.v1', $source['schema_version'] ?? null);
        $this->assertSame('FA30-API-10', $source['task'] ?? null);
        $this->assertSame('backend_source_ledger_contract_only', $source['mode'] ?? null);
        $this->assertSame('FA30-API-10', $artifact['task'] ?? null);
        $this->assertSame(3, data_get($artifact, 'ledger_summary.entry_count'));
        $this->assertFalse((bool) data_get($artifact, 'ledger_summary.public_url_authority_created', true));
        $this->assertFalse((bool) data_get($artifact, 'ledger_summary.scraping_performed', true));

        $entries = $source['entries'] ?? [];
        $this->assertCount(3, $entries);

        foreach ($entries as $entry) {
            foreach ($artifact['ledger_entry_required_fields'] as $field) {
                $this->assertArrayHasKey($field, $entry);
                $this->assertNotEmpty($entry[$field], $field.' must not be empty');
            }

            $this->assertSame('noindex', $entry['indexability_status'] ?? null);
            $this->assertSame('not_reviewed', $entry['claim_review_status'] ?? null);
            $this->assertSame('required', $entry['legal_review_status'] ?? null);
            $this->assertSame('operator_review_required', $entry['source_review_status'] ?? null);
        }

        foreach ($artifact['negative_guarantees'] ?? [] as $flag => $value) {
            $this->assertFalse((bool) $value, $flag.' must remain false');
        }
    }

    #[Test]
    public function validator_rejects_copied_competitor_ranking_rating_pricing_and_endorsement_claims(): void
    {
        $validator = new CompetitorAlternativesSourceLedgerValidator;

        foreach ([
            'Copied from competitor copy.',
            'FermatMind is better than the other product.',
            'User reviews say it is rated highest.',
            'The pricing is cheaper than the competitor.',
            'This is an official partner endorsement.',
            '这是复制竞品并声称排名第一的页面。',
        ] as $claim) {
            $issues = $validator->validateComparisonText($claim, 'test_claim');

            $this->assertNotEmpty($issues, $claim);
            $this->assertStringStartsWith('test_claim.forbidden_claim.', $issues[0]);
        }
    }

    #[Test]
    public function validator_rejects_forbidden_source_fields_and_missing_review_state(): void
    {
        $payload = $this->sourcePackage();
        $payload['entries'][0]['rating'] = 4.9;
        unset($payload['entries'][0]['legal_review_status']);
        $payload['entries'][1]['indexability_status'] = 'indexable';

        $result = (new CompetitorAlternativesSourceLedgerValidator)->validate($payload);

        $this->assertFalse((bool) $result['ok']);
        $this->assertContains('entries.0.rating.forbidden_field', $result['issues']);
        $this->assertContains('entry.assessment-method-alternative-ledger-draft.legal_review_status.missing', $result['issues']);
        $this->assertContains('entry.free-assessment-alternative-ledger-draft.indexable_requires_claim_approval', $result['issues']);
        $this->assertContains('entry.free-assessment-alternative-ledger-draft.indexable_requires_legal_approval', $result['issues']);
    }

    #[Test]
    public function audit_command_emits_stable_readonly_json_without_writing_cms_rows(): void
    {
        $this->assertSame(0, ContentPage::query()->withoutGlobalScopes()->count());

        $exitCode = Artisan::call('competitor-alternatives:source-ledger-audit', [
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $decoded = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('FA30-API-10', $decoded['task'] ?? null);
        $this->assertSame('pass', $decoded['status'] ?? null);
        $this->assertTrue((bool) ($decoded['ok'] ?? false));
        $this->assertSame(3, $decoded['entry_count'] ?? null);
        $this->assertSame(0, $decoded['indexable_entry_count'] ?? null);
        $this->assertSame(0, $decoded['issue_count'] ?? null);
        $this->assertFalse((bool) data_get($decoded, 'boundary.scrape_performed', true));
        $this->assertFalse((bool) data_get($decoded, 'boundary.cms_write_performed', true));
        $this->assertFalse((bool) data_get($decoded, 'boundary.seo_runtime_changed', true));

        $this->assertSame(0, ContentPage::query()->withoutGlobalScopes()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function sourcePackage(): array
    {
        $path = base_path('docs/seo/import-packages/competitor-alternatives-source-ledger/competitor_alternatives_source_ledger.v1.json');

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/competitor-alternatives-source-ledger.v1.json');

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
