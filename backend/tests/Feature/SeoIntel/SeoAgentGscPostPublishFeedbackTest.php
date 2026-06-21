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
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class SeoAgentGscPostPublishFeedbackTest extends TestCase
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
    public function command_classifies_post_publish_feedback_from_gated_gsc_rows_without_writes(): void
    {
        $page = $this->createPublishedContentPage();
        $this->seedUrlTruth($page);
        $this->seedGscRows($page);
        $artifactDir = $this->artifactDir();
        $publishEvidence = $this->writePublishEvidence($artifactDir, $page);
        $countsBefore = $this->rowCounts();

        $output = new BufferedOutput();
        $exitCode = Artisan::call('seo-agent:gsc-post-publish-feedback', [
            '--publish-evidence' => $publishEvidence,
            '--window' => 7,
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ], $output);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame($countsBefore, $this->rowCounts());

        $artifact = $this->readJson($this->latestArtifact($artifactDir, 'seo-agent-gsc-post-publish-feedback-*.json'));

        $this->assertSame('seo-agent-gsc-post-publish-feedback.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('success', $artifact['status'] ?? null);
        $this->assertSame(7, $artifact['window_days'] ?? null);
        $this->assertSame(1, $artifact['target_count'] ?? null);
        $this->assertSame('pass', data_get($artifact, 'targets.0.source_gate.status'));
        $this->assertSame('improved', data_get($artifact, 'targets.0.classification'));
        $this->assertSame(80, data_get($artifact, 'targets.0.before.impressions'));
        $this->assertSame(120, data_get($artifact, 'targets.0.after.impressions'));
        $this->assertSame(1, data_get($artifact, 'classification_counts.improved'));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.database_write', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.google_search_console_live_api_call', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.search_channel_submit', true));

        $encoded = json_encode($artifact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        foreach ($this->forbiddenStrings() as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded, $forbidden);
        }
    }

    #[Test]
    public function command_reports_insufficient_data_when_live_rows_are_missing(): void
    {
        $page = $this->createPublishedContentPage();
        $this->seedUrlTruth($page);
        $artifactDir = $this->artifactDir();
        $publishEvidence = $this->writePublishEvidence($artifactDir, $page);

        $output = new BufferedOutput();
        $exitCode = Artisan::call('seo-agent:gsc-post-publish-feedback', [
            '--publish-evidence' => $publishEvidence,
            '--window' => 14,
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ], $output);

        $this->assertSame(0, $exitCode, Artisan::output());
        $artifact = $this->readJson($this->latestArtifact($artifactDir, 'seo-agent-gsc-post-publish-feedback-*.json'));

        $this->assertSame('blocked', data_get($artifact, 'targets.0.source_gate.status'));
        $this->assertContains('no_gsc_rows_for_window', data_get($artifact, 'targets.0.source_gate.reasons'));
        $this->assertSame('insufficient_data', data_get($artifact, 'targets.0.classification'));
        $this->assertSame(1, data_get($artifact, 'classification_counts.insufficient_data'));
    }

    #[Test]
    public function generated_contract_documents_readonly_feedback_boundary(): void
    {
        $contract = $this->readJson(base_path('docs/seo/generated/seo-agent-gsc-post-publish-feedback.v1.json'));

        $this->assertSame('seo-agent-gsc-post-publish-feedback.v1', $contract['version'] ?? null);
        $this->assertSame('php artisan seo-agent:gsc-post-publish-feedback', $contract['command'] ?? null);
        $this->assertSame([7, 14, 28], $contract['allowed_windows_days'] ?? null);
        $this->assertContains('seo_intel.seo_gsc_daily', $contract['read_models'] ?? []);
        $this->assertSame('live_gsc_api', data_get($contract, 'row_gate.data_origin'));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.database_write', true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.google_search_console_live_api_call', true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.cms_publish', true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.search_channel_submit', true));
    }

    private function createPublishedContentPage(): ContentPage
    {
        return ContentPage::query()->create([
            'org_id' => 0,
            'slug' => 'feedback-page',
            'path' => '/en/feedback-page',
            'kind' => ContentPage::KIND_COMPANY,
            'page_type' => 'company',
            'title' => 'Feedback Page',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => 'en',
            'seo_title' => 'Feedback Page | FermatMind',
            'seo_description' => 'A published page used to verify GSC post-publish feedback.',
            'meta_description' => 'A published page used to verify GSC post-publish feedback.',
            'canonical_path' => '/en/feedback-page',
            'is_public' => true,
            'is_indexable' => true,
            'schema_enabled' => false,
            'publish_allowed' => true,
            'operator_approval_required' => false,
            'claim_gate_status' => 'passed',
            'forbidden_claims' => [],
            'status' => ContentPage::STATUS_PUBLISHED,
            'published_at' => Carbon::parse('2026-06-10 12:00:00'),
        ]);
    }

    private function seedUrlTruth(ContentPage $page): void
    {
        DB::connection('seo_intel')->table('seo_urls')->insert([
            'canonical_url_hash' => $this->canonicalHash(),
            'canonical_url' => 'https://fermatmind.com/en/feedback-page',
            'locale' => 'en',
            'page_entity_type' => 'content_page',
            'entity_id_or_slug' => (string) $page->id,
            'source_authority' => 'cms_content_page',
            'indexability_state' => 'indexable',
            'is_private_flow' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedGscRows(ContentPage $page): void
    {
        $hash = $this->canonicalHash();
        $this->insertGscRow($hash, '2026-06-08', clicks: 0, impressions: 80, ctrPpm: 0, positionMilli: 13000);
        $this->insertGscRow($hash, '2026-06-11', clicks: 4, impressions: 120, ctrPpm: 33333, positionMilli: 9500);
    }

    private function insertGscRow(string $hash, string $date, int $clicks, int $impressions, int $ctrPpm, int $positionMilli): void
    {
        DB::connection('seo_intel')->table('seo_gsc_daily')->insert([
            'report_date' => $date,
            'canonical_url_hash' => $hash,
            'canonical_url' => null,
            'query_hash' => hash('sha256', 'career path feedback'),
            'query_display_masked' => 'c****************k',
            'locale' => 'en',
            'source_engine' => 'google',
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr_ppm' => $ctrPpm,
            'average_position_milli' => $positionMilli,
            'is_brand_query' => false,
            'query_type' => 'non_brand',
            'data_state' => 'final',
            'metadata_json' => json_encode(['data_origin' => 'live_gsc_api'], JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function writePublishEvidence(string $dir, ContentPage $page): string
    {
        $path = rtrim($dir, '/').'/publish-evidence.json';
        $payload = [
            'schema_version' => 'seo-agent-cms-publish-canary.v1',
            'ok' => true,
            'status' => 'success',
            'execute' => true,
            'writes_attempted' => true,
            'writes_committed' => true,
            'published_count' => 1,
            'rows_skipped_existing' => 0,
            'affected_refs' => [[
                'status' => 'published',
                'target_model' => 'content_page',
                'subject_ref' => 'content_page:'.$page->id.':en',
                'revision_id' => 1001,
                'safe_path' => '/en/feedback-page',
            ]],
            'rollback_evidence' => [
                'available' => true,
            ],
            'negative_guarantees' => [
                'search_channel_enqueue' => false,
                'search_channel_submit' => false,
                'indexing_request' => false,
                'scheduler_activation' => false,
            ],
        ];

        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return $path;
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

    private function canonicalHash(): string
    {
        return hash('sha256', 'https://fermatmind.com/en/feedback-page');
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-agent-gsc-post-publish-feedback-'.Str::uuid()->toString());
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
