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

final class SeoAgentCodexReviewRunnerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function command_writes_deterministic_verdict_without_db_writes_or_external_calls(): void
    {
        $this->createRows();
        $artifactDir = $this->artifactDir();
        $handoffPath = $this->writeHandoff([
            $this->candidate('p1', 'title gap', [
                'source_family' => 'gsc_cohort_artifact',
                'proposal_payload' => $this->proposalPayload(),
            ]),
            $this->candidate('p2', 'description gap'),
            $this->candidate('p1', 'canonical runtime', [
                'source_family' => 'runtime_seo_qa',
                'gap_types' => ['canonical_mismatch'],
                'evidence_refs' => [
                    [
                        'code' => 'canonical_mismatch',
                        'field_status' => 'mismatch',
                    ],
                ],
            ]),
            $this->candidate('p2', 'faq gap', [
                'source_family' => 'cms_faq_gap',
                'gap_types' => ['missing_faq_items'],
                'evidence_refs' => [
                    [
                        'code' => 'missing_faq_items',
                        'field_status' => 'missing',
                    ],
                ],
            ]),
            $this->candidate('p1', 'gsc no cms target', [
                'source_family' => 'gsc_performance',
                'subject_type' => 'query_page',
                'gap_types' => ['low_ctr'],
            ]),
            $this->candidate('p3', 'low priority'),
            [
                'source_family' => 'cms_tdk_gap',
                'source_id' => 'incomplete',
                'severity' => 'p1',
                'evidence_refs' => [],
            ],
        ]);
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-agent:codex-review-runner', [
            '--handoff' => $handoffPath,
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $countsAfter = $this->rowCounts();
        $verdict = $this->readJson(data_get($summary, 'artifact.path'));

        $this->assertSame(0, $exitCode);
        $this->assertSame($countsBefore, $countsAfter);
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertSame('seo-agent-codex-review-verdict.v1', $verdict['schema_version'] ?? null);
        $this->assertSame('deterministic_rules', $verdict['review_mode'] ?? null);
        $this->assertFalse((bool) ($verdict['execution_permission'] ?? true));
        $this->assertFalse((bool) data_get($verdict, 'negative_guarantees.external_model_api_call', true));
        $this->assertSame(7, $verdict['candidate_count'] ?? null);
        $this->assertTrue((bool) ($verdict['worth_optimizing'] ?? false));
        $this->assertSame('cms_draft_package_dry_run', $verdict['recommended_action'] ?? null);

        $candidateActions = array_column($verdict['candidate_verdicts'] ?? [], 'recommended_action');
        $this->assertSame([
            'cms_draft_package_dry_run',
            'cms_draft_package_dry_run',
            'technical_review_required',
            'cms_draft_package_dry_run',
            'defer',
            'defer',
            'defer',
        ], $candidateActions);
        $this->assertSame(['missing_title'], data_get($verdict, 'candidate_verdicts.0.gap_types'));
        $this->assertSame('missing_title', data_get($verdict, 'candidate_verdicts.0.evidence_refs.0.code'));
        $this->assertSame('runtime_seo_qa_requires_technical_review', data_get($verdict, 'candidate_verdicts.2.review_reason'));
        $this->assertSame('cms_faq_gap_ready_for_draft_dry_run', data_get($verdict, 'candidate_verdicts.3.review_reason'));
        $this->assertSame('gsc_candidate_without_cms_target', data_get($verdict, 'candidate_verdicts.4.review_reason'));
        $this->assertSame('gsc_cohort_artifact', data_get($verdict, 'candidate_verdicts.0.proposal_payload.source'));
        $this->assertSame('zh-CN', data_get($verdict, 'candidate_verdicts.0.proposal_payload.locale'));
        $this->assertSame('中文 SEO 标题 | FermatMind', data_get($verdict, 'candidate_verdicts.0.proposal_payload.runtime.title'));
        $this->assertSame('Review title/meta from sanitized GSC context.', data_get($verdict, 'candidate_verdicts.0.proposal_payload.proposed_actions.0'));
        $this->assertContains('technical_surface_requires_human_review', $verdict['risk_flags'] ?? []);
        $this->assertContains('cms_target_missing', $verdict['risk_flags'] ?? []);
        $this->assertContains('candidate_incomplete', $verdict['risk_flags'] ?? []);
        $this->assertContains('evidence_missing', $verdict['risk_flags'] ?? []);

        $encoded = json_encode([$summary, $verdict], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        foreach ($this->forbiddenStrings() as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded, $forbidden);
        }
    }

    #[Test]
    public function command_fails_closed_for_invalid_schema_and_forbidden_fields(): void
    {
        $invalidSchema = $this->writeJson(['schema_version' => 'wrong.v1']);

        $exitCode = Artisan::call('seo-agent:codex-review-runner', [
            '--handoff' => $invalidSchema,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertContains('handoff_schema_invalid', $summary['issues'] ?? []);
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.cms_write', true));

        $forbidden = $this->writeHandoff([
            $this->candidate('p1', 'forbidden', ['raw_url' => '/private']),
        ]);

        $exitCode = Artisan::call('seo-agent:codex-review-runner', [
            '--handoff' => $forbidden,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertContains('forbidden_input_field_present', $summary['issues'] ?? []);
    }

    #[Test]
    public function generated_contract_documents_review_runner_boundaries(): void
    {
        $artifact = $this->readJson(base_path('docs/seo/generated/seo-agent-codex-review-runner.v1.json'));

        $this->assertSame('seo-agent-codex-review-runner.v1', $artifact['version'] ?? null);
        $this->assertSame('seo-agent-codex-review-handoff.v1', $artifact['input_schema'] ?? null);
        $this->assertSame('seo-agent-codex-review-verdict.v1', $artifact['output_schema'] ?? null);
        $this->assertSame('codex', $artifact['reviewer'] ?? null);
        $this->assertFalse((bool) ($artifact['external_model_api_call'] ?? true));
        $this->assertSame('technical_review_required', data_get($artifact, 'rules.runtime_seo_qa_canonical_noindex_robots'));
        $this->assertSame('defer', data_get($artifact, 'rules.gsc_without_cms_target'));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.cms_write', true));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function candidate(string $severity, string $label, array $extra = []): array
    {
        return [
            'source_family' => 'cms_tdk_gap',
            'source_id' => hash('sha256', $label),
            'subject_type' => 'article',
            'subject_ref' => 'article:1:zh-CN',
            'safe_path' => '/zh/articles/'.$this->safeSlug($label),
            'severity' => $severity,
            'gap_types' => ['missing_title'],
            'evidence_refs' => [
                [
                    'code' => 'missing_title',
                    'field_status' => 'missing',
                ],
            ],
            ...$extra,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    private function writeHandoff(array $candidates): string
    {
        return $this->writeJson([
            'schema_version' => 'seo-agent-codex-review-handoff.v1',
            'reviewer' => 'codex',
            'role' => 'review_only',
            'execution_permission' => false,
            'candidate_count' => count($candidates),
            'candidate_preview' => $candidates,
            'negative_guarantees' => [
                'database_write' => false,
                'cms_write' => false,
                'external_model_api_call' => false,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(array $payload): string
    {
        $path = storage_path('framework/testing/seo-agent-codex-review-handoff-'.Str::uuid()->toString().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return $path;
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-agent-codex-review-runner-'.Str::uuid()->toString());
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    private function safeSlug(string $label): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($label)) ?: 'candidate', '-');
    }

    private function createRows(): void
    {
        Article::query()->create([
            'org_id' => 0,
            'slug' => 'review-runner-db-sentinel',
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
            'slug' => 'review-runner-page-sentinel',
            'path' => '/zh/review-runner-page-sentinel',
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
     * @return array<string, mixed>
     */
    private function proposalPayload(): array
    {
        return [
            'source' => 'gsc_cohort_artifact',
            'locale' => 'zh-CN',
            'safe_path' => '/zh/articles/title-gap',
            'draft_angle' => 'title-gap',
            'proposed_actions' => [
                'Review title/meta from sanitized GSC context.',
                'Add internal link from /zh/careers.',
            ],
            'runtime' => [
                'title' => '中文 SEO 标题 | FermatMind',
                'meta_description' => '中文描述需要原样保留用于 dry-run proposal。',
                'title_length' => 23,
                'meta_description_length' => 29,
                'jsonld_total' => 0,
                'internal_link_count' => 12,
                'sample_internal_paths' => ['/zh/careers'],
            ],
            'metrics' => [
                'clicks' => 0,
                'impressions' => 257,
                'ctr_ppm' => 0,
                'average_position_milli' => 8900,
            ],
        ];
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
            'raw_url',
            'raw_query',
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
