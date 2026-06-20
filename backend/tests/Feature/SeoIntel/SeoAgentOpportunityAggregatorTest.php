<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Article;
use App\Models\ContentPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoAgentOpportunityAggregatorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function command_aggregates_dedupes_and_ranks_multisource_candidates_without_db_writes(): void
    {
        $this->createRows();
        $artifactDir = $this->artifactDir();
        $tdkPath = $this->writeArtifact('tdk', [
            $this->candidate('cms_tdk_gap', 'article:1:zh-CN', '/zh/articles/shared', 'p1', ['missing_title'], evidenceCount: 1),
            $this->candidate('cms_tdk_gap', 'article:1:zh-CN', '/zh/articles/shared', 'p1', ['missing_title'], evidenceCount: 2),
        ]);
        $runtimePath = $this->writeArtifact('runtime', [
            $this->candidate('runtime_seo_qa', 'article:1:zh-CN', '/zh/articles/shared', 'p1', ['canonical_mismatch'], evidenceCount: 2),
        ]);
        $faqPath = $this->writeArtifact('faq', [
            $this->candidate('cms_faq_gap', 'content_page:2:zh-CN', '/zh/help/faq-gap', 'p2', ['missing_faq_items'], evidenceCount: 1),
        ]);
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-agent:opportunity-aggregate', [
            '--inputs' => implode(',', [$tdkPath, $runtimePath, $faqPath]),
            '--limit' => 10,
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $aggregate = $this->readJson(data_get($summary, 'artifact.path'));

        $this->assertSame(0, $exitCode);
        $this->assertSame($countsBefore, $this->rowCounts());
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertSame('seo-agent-opportunity-aggregate.v1', $aggregate['schema_version'] ?? null);
        $this->assertSame(3, $aggregate['candidate_count'] ?? null);
        $this->assertSame(3, $aggregate['input_artifact_count'] ?? null);

        $this->assertSame('runtime_seo_qa', data_get($aggregate, 'candidates.0.source_family'));
        $this->assertSame(1, data_get($aggregate, 'candidates.0.priority_rank'));
        $this->assertSame('cms_tdk_gap', data_get($aggregate, 'candidates.1.source_family'));
        $this->assertSame('cms_faq_gap', data_get($aggregate, 'candidates.2.source_family'));

        $tdkCandidate = collect($aggregate['candidates'])->firstWhere('source_family', 'cms_tdk_gap');
        $this->assertIsArray($tdkCandidate);
        $this->assertSame(['missing_title'], $tdkCandidate['gap_types'] ?? null);
        $this->assertCount(3, $tdkCandidate['evidence_refs'] ?? []);

        $encoded = json_encode([$summary, $aggregate], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        foreach ($this->forbiddenStrings() as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded, $forbidden);
        }

        foreach ([
            'database_write',
            'cms_write',
            'cms_publish',
            'search_channel_enqueue',
            'search_channel_submit',
            'indexing_request',
            'scheduler_activation',
            'queue_worker_started',
            'google_search_console_api_call',
            'external_model_api_call',
        ] as $field) {
            $this->assertFalse((bool) data_get($summary, 'negative_guarantees.'.$field, true), $field);
            $this->assertFalse((bool) data_get($aggregate, 'negative_guarantees.'.$field, true), $field);
        }
    }

    #[Test]
    public function command_fails_closed_for_forbidden_input_fields(): void
    {
        $forbiddenPath = $this->writeJson([
            'schema_version' => 'seo-agent-cms-tdk-gap-readonly-scanner.v1',
            'candidate_count' => 1,
            'candidates' => [
                [
                    'source_family' => 'cms_tdk_gap',
                    'subject_type' => 'article',
                    'subject_ref' => 'article:1:zh-CN',
                    'safe_path' => '/zh/articles/unsafe',
                    'raw_url' => 'https://fermatmind.com/zh/articles/unsafe',
                ],
            ],
        ]);

        $exitCode = Artisan::call('seo-agent:opportunity-aggregate', [
            '--inputs' => $forbiddenPath,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertContains('forbidden_input_field_present', $summary['issues'] ?? []);
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.cms_write', true));
    }

    #[Test]
    public function generated_contract_documents_aggregator_boundaries(): void
    {
        $artifact = $this->readJson(base_path('docs/seo/generated/seo-agent-opportunity-aggregate.v1.json'));

        $this->assertSame('seo-agent-opportunity-aggregate.v1', $artifact['version'] ?? null);
        $this->assertSame('php artisan seo-agent:opportunity-aggregate', $artifact['command'] ?? null);
        $this->assertSame('seo-agent-opportunity-aggregate.v1', $artifact['output_schema'] ?? null);
        $this->assertFalse((bool) ($artifact['cross_source_same_subject_merge_allowed'] ?? true));
        $this->assertSame(40, data_get($artifact, 'source_weights.runtime_seo_qa'));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.cms_write', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.external_model_api_call', true));
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    private function writeArtifact(string $family, array $candidates): string
    {
        return $this->writeJson([
            'schema_version' => 'seo-agent-'.$family.'.v1',
            'status' => 'success',
            'source_family' => $family,
            'candidate_count' => count($candidates),
            'candidates' => $candidates,
            'negative_guarantees' => [
                'database_write' => false,
                'cms_write' => false,
            ],
        ]);
    }

    /**
     * @param  list<string>  $gapTypes
     * @return array<string, mixed>
     */
    private function candidate(string $sourceFamily, string $subjectRef, string $safePath, string $severity, array $gapTypes, int $evidenceCount): array
    {
        return [
            'source_family' => $sourceFamily,
            'source_id' => hash('sha256', $sourceFamily.$subjectRef.$severity.implode(',', $gapTypes).$evidenceCount),
            'subject_type' => str_starts_with($subjectRef, 'content_page:') ? 'content_page' : 'article',
            'subject_ref' => $subjectRef,
            'safe_path' => $safePath,
            'locale' => 'zh-CN',
            'severity' => $severity,
            'gap_types' => $gapTypes,
            'evidence_refs' => array_map(
                static fn (int $index): array => [
                    'code' => $gapTypes[0] ?? 'gap',
                    'field_status' => 'missing',
                    'status_code' => 200 + $index,
                ],
                range(1, $evidenceCount)
            ),
            'recommended_next_step' => 'codex_review_required',
            'allowed_action' => 'readonly_review',
            'blocked_actions' => [
                'cms_write',
                'cms_publish',
                'search_channel_submit',
                'indexing_request',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(array $payload): string
    {
        $path = storage_path('framework/testing/seo-agent-opportunity-aggregate-input-'.Str::uuid()->toString().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return $path;
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-agent-opportunity-aggregate-'.Str::uuid()->toString());
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function createRows(): void
    {
        Article::query()->create([
            'org_id' => 0,
            'slug' => 'aggregator-db-sentinel',
            'locale' => 'zh-CN',
            'title' => 'Sentinel',
            'excerpt' => 'Sentinel.',
            'content_md' => 'Sentinel markdown body is not read or emitted by this command.',
            'content_html' => '<p>Sentinel HTML body is not read or emitted by this command.</p>',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subDay(),
        ]);

        ContentPage::query()->create([
            'org_id' => 0,
            'slug' => 'aggregator-page-sentinel',
            'path' => '/zh/aggregator-page-sentinel',
            'kind' => ContentPage::KIND_COMPANY,
            'page_type' => 'company',
            'title' => 'Sentinel',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => 'zh-CN',
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

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return [
            'articles' => Article::query()->withoutGlobalScopes()->count(),
            'content_pages' => ContentPage::query()->withoutGlobalScopes()->count(),
        ];
    }

    /**
     * @return list<string>
     */
    private function forbiddenStrings(): array
    {
        return [
            'https://fermatmind.com',
            'raw_url',
            'raw_query',
            'full_url',
            'credential_path',
            'client_email',
            'private_key',
            'Bearer ',
            'token',
            'cookie',
            'content_md',
            'content_html',
            'raw_html',
            'cms_draft_body',
        ];
    }
}
