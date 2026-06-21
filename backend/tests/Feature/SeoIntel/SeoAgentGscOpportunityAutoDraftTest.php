<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\ContentPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoAgentGscOpportunityAutoDraftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-21 08:00:00'));
        config([
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'seo_intel.connection' => 'seo_intel',
        ]);

        DB::purge('seo_intel');
        $this->createSeoIntelTables();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function command_builds_gsc_opportunity_review_and_draft_package_without_writes(): void
    {
        $page = $this->createPublishedContentPage();
        $this->seedGscRows($page);
        $artifactDir = $this->artifactDir();
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-agent:gsc-opportunity-auto-draft', [
            '--limit' => 10,
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ]);

        $countsAfter = $this->rowCounts();
        $this->assertSame(0, $exitCode, Artisan::output());
        $evidence = $this->readJson($this->latestArtifact($artifactDir, 'seo-agent-gsc-opportunity-auto-draft-evidence-*.json'));
        $source = $this->readJson(data_get($evidence, 'artifacts.gsc_opportunity_source.path'));
        $aggregate = $this->readJson(data_get($evidence, 'artifacts.opportunity_aggregate.path'));
        $verdict = $this->readJson(data_get($evidence, 'artifacts.codex_review_verdict.path'));
        $draftPackage = $this->readJson(data_get($evidence, 'artifacts.cms_draft_package_dry_run.path'));

        $this->assertSame($countsBefore, $countsAfter);
        $this->assertSame('success', $evidence['status'] ?? null);
        $this->assertSame('pass', data_get($evidence, 'source_gate.status'));
        $this->assertTrue((bool) data_get($evidence, 'source_gate.opportunity_queue_eligible'));
        $this->assertSame(1, data_get($source, 'candidate_count'));
        $this->assertSame(1, data_get($draftPackage, 'draft_brief_count'));

        $this->assertSame('seo-agent-gsc-opportunity-auto-draft.v1', $source['schema_version'] ?? null);
        $this->assertSame('gsc_performance', data_get($source, 'candidates.0.source_family'));
        $this->assertSame('content_page', data_get($source, 'candidates.0.subject_type'));
        $this->assertSame('content_page:'.$page->id.':en', data_get($source, 'candidates.0.subject_ref'));
        $this->assertSame('/en/gsc-opportunity-page', data_get($source, 'candidates.0.safe_path'));
        $this->assertSame(['gsc_low_ctr_title_opportunity', 'gsc_low_ctr_description_opportunity'], data_get($source, 'candidates.0.gap_types'));

        $this->assertSame('seo-agent-opportunity-aggregate.v1', $aggregate['schema_version'] ?? null);
        $this->assertSame(1, $aggregate['candidate_count'] ?? null);
        $this->assertSame('seo-agent-codex-review-verdict.v1', $verdict['schema_version'] ?? null);
        $this->assertSame('cms_draft_package_dry_run', data_get($verdict, 'candidate_verdicts.0.recommended_action'));
        $this->assertSame('seo-agent-cms-draft-package-dry-run.v1', $draftPackage['schema_version'] ?? null);
        $this->assertSame(1, $draftPackage['draft_brief_count'] ?? null);
        $targetFields = data_get($draftPackage, 'draft_briefs.0.target_fields');
        sort($targetFields);
        $this->assertSame(['seo_description', 'seo_title'], $targetFields);
        $this->assertFalse((bool) data_get($draftPackage, 'negative_guarantees.cms_write', true));
        $this->assertFalse((bool) data_get($evidence, 'negative_guarantees.cms_publish', true));

        $encoded = json_encode([$source, $aggregate, $verdict, $draftPackage, $evidence], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        foreach ($this->forbiddenStrings() as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded, $forbidden);
        }
    }

    #[Test]
    public function command_outputs_zero_candidates_when_gate_is_blocked(): void
    {
        $page = $this->createPublishedContentPage();
        $this->seedGscRows($page, dataOrigin: 'fixture');
        $artifactDir = $this->artifactDir();

        $exitCode = Artisan::call('seo-agent:gsc-opportunity-auto-draft', [
            '--limit' => 10,
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $evidence = $this->readJson($this->latestArtifact($artifactDir, 'seo-agent-gsc-opportunity-auto-draft-evidence-*.json'));
        $source = $this->readJson(data_get($evidence, 'artifacts.gsc_opportunity_source.path'));

        $this->assertSame('success', $evidence['status'] ?? null);
        $this->assertSame('blocked', data_get($evidence, 'source_gate.status'));
        $this->assertFalse((bool) data_get($evidence, 'source_gate.opportunity_queue_eligible'));
        $this->assertSame(0, $source['candidate_count'] ?? null);
        $this->assertContains('fixture_or_mock_source', data_get($source, 'source_gate.reasons'));
        $this->assertFalse((bool) data_get($source, 'negative_guarantees.database_write', true));
    }

    #[Test]
    public function generated_contract_documents_gsc_auto_draft_boundaries(): void
    {
        $contract = $this->readJson(base_path('docs/seo/generated/seo-agent-gsc-opportunity-auto-draft.v1.json'));

        $this->assertSame('seo-agent-gsc-opportunity-auto-draft.v1', $contract['version'] ?? null);
        $this->assertSame('php artisan seo-agent:gsc-opportunity-auto-draft', $contract['command'] ?? null);
        $this->assertSame('gsc_performance', data_get($contract, 'candidate_contract.source_family'));
        $this->assertSame('live_gsc_api', data_get($contract, 'candidate_contract.data_origin'));
        $this->assertSame(['article', 'content_page'], data_get($contract, 'candidate_contract.allowed_subject_types'));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.cms_write', true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.cms_publish', true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.google_search_console_api_call', true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.google_indexing_api_call', true));
    }

    private function createPublishedContentPage(): ContentPage
    {
        return ContentPage::query()->create([
            'org_id' => 0,
            'slug' => 'gsc-opportunity-page',
            'path' => '/en/gsc-opportunity-page',
            'kind' => ContentPage::KIND_COMPANY,
            'page_type' => 'company',
            'title' => 'GSC Opportunity Page',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => 'en',
            'seo_title' => 'GSC Opportunity Page | FermatMind',
            'seo_description' => 'A published page used to verify GSC opportunity dry-run routing.',
            'meta_description' => 'A published page used to verify GSC opportunity dry-run routing.',
            'canonical_path' => '/en/gsc-opportunity-page',
            'is_public' => true,
            'is_indexable' => true,
            'schema_enabled' => false,
            'publish_allowed' => false,
            'operator_approval_required' => false,
            'legal_review_required' => false,
            'science_review_required' => false,
            'claim_gate_status' => 'not_reviewed',
            'forbidden_claims' => [],
            'status' => ContentPage::STATUS_PUBLISHED,
        ]);
    }

    private function seedGscRows(ContentPage $page, string $dataOrigin = 'live_gsc_api'): void
    {
        $hash = hash('sha256', 'https://fermatmind.com/en/gsc-opportunity-page');

        DB::connection('seo_intel')->table('seo_urls')->insert([
            'canonical_url_hash' => $hash,
            'canonical_url' => 'https://fermatmind.com/en/gsc-opportunity-page',
            'locale' => 'en',
            'page_entity_type' => 'content_page',
            'entity_id_or_slug' => (string) $page->id,
            'source_authority' => 'cms_content_page',
            'indexability_state' => 'indexable',
            'is_private_flow' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('seo_intel')->table('seo_gsc_daily')->insert([
            'report_date' => '2026-06-17',
            'canonical_url_hash' => $hash,
            'canonical_url' => null,
            'query_hash' => hash('sha256', 'best career test'),
            'query_display_masked' => 'b*************t',
            'locale' => 'en',
            'source_engine' => 'google',
            'clicks' => 0,
            'impressions' => 180,
            'ctr_ppm' => 0,
            'average_position_milli' => 11200,
            'is_brand_query' => false,
            'query_type' => 'non_brand',
            'data_state' => 'final',
            'metadata_json' => json_encode(['data_origin' => $dataOrigin], JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('seo_intel')->table('seo_gsc_daily')->insert([
            'report_date' => '2026-06-17',
            'canonical_url_hash' => $hash,
            'canonical_url' => null,
            'query_hash' => hash('sha256', 'fermatmind branded'),
            'query_display_masked' => 'f*********d',
            'locale' => 'en',
            'source_engine' => 'google',
            'clicks' => 10,
            'impressions' => 200,
            'ctr_ppm' => 50000,
            'average_position_milli' => 3000,
            'is_brand_query' => true,
            'query_type' => 'brand',
            'data_state' => 'final',
            'metadata_json' => json_encode(['data_origin' => $dataOrigin], JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSeoIntelTables(): void
    {
        $schema = Schema::connection('seo_intel');
        $schema->dropIfExists('seo_gsc_daily');
        $schema->dropIfExists('seo_urls');

        $schema->create('seo_urls', function ($table): void {
            $table->id();
            $table->char('canonical_url_hash', 64);
            $table->text('canonical_url');
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('entity_id_or_slug', 255)->nullable();
            $table->string('source_authority', 64);
            $table->string('indexability_state', 64);
            $table->boolean('is_private_flow')->default(false);
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });

        $schema->create('seo_gsc_daily', function ($table): void {
            $table->id();
            $table->date('report_date');
            $table->char('canonical_url_hash', 64)->nullable();
            $table->text('canonical_url')->nullable();
            $table->char('query_hash', 64)->nullable();
            $table->string('query_display_masked', 255)->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('source_engine', 64)->default('google');
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('ctr_ppm')->nullable();
            $table->unsignedInteger('average_position_milli')->nullable();
            $table->boolean('is_brand_query')->default(false);
            $table->string('query_type', 32)->default('unknown');
            $table->string('data_state', 32)->default('final');
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return [
            'content_pages' => ContentPage::query()->withoutGlobalScopes()->count(),
            'seo_urls' => DB::connection('seo_intel')->table('seo_urls')->count(),
            'seo_gsc_daily' => DB::connection('seo_intel')->table('seo_gsc_daily')->count(),
        ];
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-agent-gsc-opportunity-auto-draft-'.Str::uuid()->toString());
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    private function latestArtifact(string $dir, string $pattern): ?string
    {
        $paths = File::glob(rtrim($dir, '/').'/'.$pattern) ?: [];
        rsort($paths);

        return $paths[0] ?? null;
    }

    /**
     * @return list<string>
     */
    private function forbiddenStrings(): array
    {
        return [
            'raw_url',
            'raw_query',
            'full_url',
            'https://fermatmind.com',
            'credential_path',
            'client_email',
            'private_key',
            'Bearer ',
            'content_md',
            'content_html',
            'cms_draft_body',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(?string $path): array
    {
        $this->assertIsString($path);
        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($payload);

        return $payload;
    }
}
