<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoAgentGscCohortHandoffTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function command_converts_gsc_cohort_article_proposals_into_standard_draft_dry_run_artifacts(): void
    {
        $articleOne = $this->createArticle('what-is-riasec-holland-code-career-interest-test', 'en');
        $articleTwo = $this->createArticle('mbti-vs-holland-career-choice', 'zh-CN');
        $artifactDir = $this->artifactDir();
        $classifiedPath = $this->writeClassifiedArtifact();
        $proposalsPath = $this->writeProposalsArtifact([
            $this->articleProposal('/en/articles/what-is-riasec-holland-code-career-interest-test', 977, 4, '0.4%', 16.9, 67, 125, 2),
            $this->articleProposal('/zh/articles/mbti-vs-holland-career-choice', 257, 0, '0%', 8.9, 37, 47, 0),
            $this->personalityProposal('/zh/personality/istj-a'),
            $this->careerProposal('/en/career/jobs/shipping-receiving-and-inventory-clerks'),
        ]);
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-agent:gsc-cohort-handoff', [
            '--classified' => $classifiedPath,
            '--proposals' => $proposalsPath,
            '--artifact-dir' => $artifactDir,
            '--limit' => 10,
            '--json' => true,
        ]);

        $countsAfter = $this->rowCounts();
        $evidence = $this->readJson($this->latestArtifact($artifactDir, 'seo-agent-gsc-cohort-handoff-evidence-*.json'));
        $source = $this->readJson(data_get($evidence, 'artifacts.gsc_cohort_source.path'));
        $aggregate = $this->readJson(data_get($evidence, 'artifacts.opportunity_aggregate.path'));
        $handoff = $this->readJson(data_get($evidence, 'artifacts.codex_review_handoff.path'));
        $verdict = $this->readJson(data_get($evidence, 'artifacts.codex_review_verdict.path'));
        $draftPackage = $this->readJson(data_get($evidence, 'artifacts.cms_draft_package_dry_run.path'));

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame($countsBefore, $countsAfter);
        $this->assertSame('success', $evidence['status'] ?? null);
        $this->assertSame(2, $evidence['candidate_count'] ?? null);
        $this->assertCount(2, $evidence['deferred_non_draft_groups'] ?? []);
        $this->assertSame([], $evidence['unresolved_article_targets'] ?? null);

        $this->assertSame('seo-agent-gsc-cohort-handoff.v1', $source['schema_version'] ?? null);
        $this->assertSame('gsc_cohort_artifact', data_get($source, 'candidates.0.source_family'));
        $this->assertSame('article:'.$articleOne->id.':en', data_get($source, 'candidates.0.subject_ref'));
        $this->assertSame('article:'.$articleTwo->id.':zh-CN', data_get($source, 'candidates.1.subject_ref'));
        $this->assertSame('/en/articles/what-is-riasec-holland-code-career-interest-test', data_get($source, 'candidates.0.safe_path'));
        $this->assertSame('/zh/articles/mbti-vs-holland-career-choice', data_get($source, 'candidates.1.safe_path'));
        $this->assertSame('gsc_cohort_artifact', data_get($source, 'candidates.0.proposal_payload.source'));
        $this->assertSame('What Is the RIASEC Holland Code Career Interest Test? | FermatMind', data_get($source, 'candidates.0.proposal_payload.runtime.title'));
        $this->assertSame('/en/articles/career-interest-test', data_get($source, 'candidates.0.proposal_payload.runtime.sample_internal_paths.0'));
        $this->assertSame(2, $source['candidate_count'] ?? null);
        $this->assertCount(2, $source['deferred_non_draft_groups'] ?? []);
        $this->assertSame('mbti_personality_variant', data_get($source, 'deferred_non_draft_groups.0.group'));
        $this->assertSame('career_job_longtail', data_get($source, 'deferred_non_draft_groups.1.group'));

        $this->assertSame('seo-agent-opportunity-aggregate.v1', $aggregate['schema_version'] ?? null);
        $this->assertSame(2, $aggregate['candidate_count'] ?? null);
        $this->assertSame('zh-CN', data_get($aggregate, 'candidates.1.proposal_payload.locale'));
        $this->assertSame('seo-agent-codex-review-handoff.v1', $handoff['schema_version'] ?? null);
        $this->assertFalse((bool) ($handoff['execution_permission'] ?? true));
        $this->assertSame('seo-agent-codex-review-verdict.v1', $verdict['schema_version'] ?? null);
        $this->assertSame('cms_draft_package_dry_run', data_get($verdict, 'candidate_verdicts.0.recommended_action'));
        $this->assertSame('Add internal link review targets from GSC cohort.', data_get($verdict, 'candidate_verdicts.0.proposal_payload.proposed_actions.0'));
        $this->assertSame('seo-agent-cms-draft-package-dry-run.v1', $draftPackage['schema_version'] ?? null);
        $this->assertSame(2, $draftPackage['draft_brief_count'] ?? null);
        $this->assertSame('article', data_get($draftPackage, 'draft_briefs.0.target_model'));
        $this->assertContains('seo_title', data_get($draftPackage, 'draft_briefs.0.target_fields'));
        $this->assertContains('seo_description', data_get($draftPackage, 'draft_briefs.1.target_fields'));
        $this->assertContains('faq_items', data_get($draftPackage, 'draft_briefs.1.target_fields'));
        $this->assertContains('manual_review_required', data_get($draftPackage, 'draft_briefs.1.target_fields'));
        $this->assertSame('What Is the RIASEC Holland Code Career Interest Test? | FermatMind', data_get($draftPackage, 'draft_briefs.0.proposed_seo_title'));
        $this->assertSame('用霍兰德职业兴趣代码理解 MBTI 职业选择 | FermatMind', data_get($draftPackage, 'draft_briefs.1.proposed_seo_title'));
        $this->assertSame('这篇中文文章解释 MBTI 与霍兰德兴趣代码如何一起辅助职业选择。', data_get($draftPackage, 'draft_briefs.1.proposed_seo_description'));
        $this->assertSame('gsc_cohort_artifact', data_get($draftPackage, 'draft_briefs.1.proposal_quality.source'));
        $this->assertTrue((bool) data_get($draftPackage, 'draft_briefs.1.proposal_quality.locale_preserved'));
        $this->assertFalse((bool) data_get($draftPackage, 'draft_briefs.1.proposal_quality.slug_generated_copy', true));
        $this->assertSame('Add internal link review targets from GSC cohort.', data_get($draftPackage, 'draft_briefs.0.proposed_internal_link_actions.0'));

        $encoded = json_encode([$evidence, $source, $aggregate, $handoff, $verdict, $draftPackage], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        foreach ($this->forbiddenOutputStrings() as $forbidden) {
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
            'google_indexing_api_call',
        ] as $field) {
            $this->assertFalse((bool) data_get($evidence, 'negative_guarantees.'.$field, true), $field);
        }
    }

    #[Test]
    public function command_defers_missing_article_targets_without_failing_the_run(): void
    {
        $artifactDir = $this->artifactDir();
        $classifiedPath = $this->writeClassifiedArtifact();
        $proposalsPath = $this->writeProposalsArtifact([
            $this->articleProposal('/en/articles/missing-gsc-cohort-article', 180, 0, '0%', 12.0, 44, 55, 0),
            $this->personalityProposal('/zh/personality/istj-a'),
        ]);

        $exitCode = Artisan::call('seo-agent:gsc-cohort-handoff', [
            '--classified' => $classifiedPath,
            '--proposals' => $proposalsPath,
            '--artifact-dir' => $artifactDir,
            '--limit' => 10,
            '--json' => true,
        ]);

        $evidence = $this->readJson($this->latestArtifact($artifactDir, 'seo-agent-gsc-cohort-handoff-evidence-*.json'));
        $source = $this->readJson(data_get($evidence, 'artifacts.gsc_cohort_source.path'));
        $draftPackage = $this->readJson(data_get($evidence, 'artifacts.cms_draft_package_dry_run.path'));

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('success', $evidence['status'] ?? null);
        $this->assertSame(0, $evidence['candidate_count'] ?? null);
        $this->assertCount(1, $evidence['unresolved_article_targets'] ?? []);
        $this->assertSame('article_target_missing', data_get($source, 'unresolved_article_targets.0.reason'));
        $this->assertSame(0, $draftPackage['draft_brief_count'] ?? null);
    }

    #[Test]
    public function command_fails_closed_for_invalid_schema_and_forbidden_input_fields(): void
    {
        $classifiedPath = $this->writeJson(['schema_version' => 'wrong.v1']);
        $proposalsPath = $this->writeProposalsArtifact([]);

        $exitCode = Artisan::call('seo-agent:gsc-cohort-handoff', [
            '--classified' => $classifiedPath,
            '--proposals' => $proposalsPath,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertContains('classified_schema_invalid', $summary['issues'] ?? []);

        $classifiedPath = $this->writeClassifiedArtifact();
        $forbiddenPath = $this->writeJson([
            'schema_version' => 'fermatmind-seo-agent-gsc-draft-proposals.v1',
            'proposal_count' => 1,
            'proposals' => [
                [
                    'proposal_type' => 'article_title_meta_faq_internal_link_draft',
                    'raw_query' => 'forbidden',
                ],
            ],
        ]);

        $exitCode = Artisan::call('seo-agent:gsc-cohort-handoff', [
            '--classified' => $classifiedPath,
            '--proposals' => $forbiddenPath,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertContains('forbidden_input_field_present', $summary['issues'] ?? []);
    }

    #[Test]
    public function generated_contract_documents_gsc_cohort_handoff_boundaries(): void
    {
        $contract = $this->readJson(base_path('docs/seo/generated/seo-agent-gsc-cohort-handoff.v1.json'));

        $this->assertSame('seo-agent-gsc-cohort-handoff.v1', $contract['version'] ?? null);
        $this->assertSame('php artisan seo-agent:gsc-cohort-handoff', $contract['command'] ?? null);
        $this->assertSame('gsc_cohort_artifact', data_get($contract, 'candidate_contract.source_family'));
        $this->assertSame(['article'], data_get($contract, 'candidate_contract.allowed_subject_types'));
        $this->assertTrue((bool) data_get($contract, 'candidate_contract.safe_path_only'));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.database_write', true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.cms_write', true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.cms_publish', true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.search_channel_submit', true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.google_search_console_api_call', true));
    }

    private function createArticle(string $slug, string $locale): Article
    {
        return Article::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'locale' => $locale,
            'title' => Str::headline($slug),
            'excerpt' => 'Published article used for GSC cohort handoff routing.',
            'content_md' => 'Sentinel markdown body is not read or emitted by this command.',
            'content_html' => '<p>Sentinel HTML body is not read or emitted by this command.</p>',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'published_at' => now(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $proposals
     */
    private function writeProposalsArtifact(array $proposals): string
    {
        return $this->writeJson([
            'schema_version' => 'fermatmind-seo-agent-gsc-draft-proposals.v1',
            'captured_at' => '2026-06-22T07:04:53.055Z',
            'mode' => 'artifact_only',
            'proposal_count' => count($proposals),
            'proposals' => $proposals,
            'boundaries' => [
                'cms_write' => false,
                'cms_publish' => false,
                'search_submission' => false,
                'indexing_request' => false,
            ],
        ]);
    }

    private function writeClassifiedArtifact(): string
    {
        return $this->writeJson([
            'schema_version' => 'fermatmind-gsc-seo-agent-cohort.v1',
            'captured_at' => '2026-06-22T07:04:53.055Z',
            'row_counts' => [
                'pages_7d' => 451,
                'queries_7d' => 301,
                'pages_28d' => 628,
                'queries_28d' => 451,
            ],
            'groups' => [
                'riasec_bigfive_enneagram_articles' => [],
                'mbti_personality_variant' => [],
                'career_job_longtail' => [],
            ],
            'boundaries' => [
                'cms_write' => false,
                'cms_publish' => false,
                'search_submission' => false,
                'indexing_request' => false,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function articleProposal(
        string $safePath,
        int $impressions,
        int $clicks,
        string $ctr,
        float $position,
        int $titleLength,
        int $metaLength,
        int $jsonldTotal
    ): array {
        return [
            'target_url' => 'https://fermatmind.com'.$safePath,
            'group' => 'riasec_bigfive_enneagram_articles',
            'proposal_type' => 'article_title_meta_faq_internal_link_draft',
            'evidence_7d_or_28d' => [
                'clicks' => $clicks,
                'ctr' => $ctr,
                'impressions' => $impressions,
                'key' => 'https://fermatmind.com'.$safePath,
                'position' => $position,
            ],
            'draft_angle' => basename($safePath),
            'proposed_actions' => [
                'Add internal link review targets from GSC cohort.',
                'Review visible FAQ candidates after claim gate.',
            ],
            'runtime_seo_check' => [
                'http_status' => 200,
                'title' => $this->runtimeTitle($safePath),
                'meta_description' => $this->runtimeMetaDescription($safePath),
                'title_length' => $titleLength,
                'meta_description_length' => $metaLength,
                'jsonld_total' => $jsonldTotal,
                'internal_link_summary' => [
                    'total_internal_links' => 36,
                    'sample_internal_paths' => $this->sampleInternalPaths($safePath),
                ],
                'checks' => [
                    'html_200' => true,
                    'canonical_present' => true,
                    'title_present' => true,
                    'meta_description_present' => true,
                    'robots_not_noindex' => true,
                    'has_internal_links' => true,
                ],
            ],
        ];
    }

    private function runtimeTitle(string $safePath): string
    {
        return str_starts_with($safePath, '/zh/')
            ? '用霍兰德职业兴趣代码理解 MBTI 职业选择 | FermatMind'
            : 'What Is the RIASEC Holland Code Career Interest Test? | FermatMind';
    }

    private function runtimeMetaDescription(string $safePath): string
    {
        return str_starts_with($safePath, '/zh/')
            ? '这篇中文文章解释 MBTI 与霍兰德兴趣代码如何一起辅助职业选择。'
            : 'Learn how RIASEC Holland Codes connect interests, work environments, and career exploration with FermatMind.';
    }

    /**
     * @return list<string>
     */
    private function sampleInternalPaths(string $safePath): array
    {
        return str_starts_with($safePath, '/zh/')
            ? ['/zh/articles/riasec-holland-code', 'https://fermatmind.com/zh/careers']
            : ['/en/articles/career-interest-test', 'https://fermatmind.com/en/careers'];
    }

    /**
     * @return array<string, mixed>
     */
    private function personalityProposal(string $safePath): array
    {
        return [
            'target_url' => 'https://fermatmind.com'.$safePath,
            'group' => 'mbti_personality_variant',
            'proposal_type' => 'public_projection_tdk_schema_internal_link_check',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function careerProposal(string $safePath): array
    {
        return [
            'target_url' => 'https://fermatmind.com'.$safePath,
            'group' => 'career_job_longtail',
            'proposal_type' => 'indexability_query_mapping_only',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(array $payload): string
    {
        $path = storage_path('framework/testing/seo-agent-gsc-cohort-'.Str::uuid()->toString().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return $path;
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-agent-gsc-cohort-handoff-'.Str::uuid()->toString());
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
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return array_filter([
            'articles' => Article::query()->withoutGlobalScopes()->count(),
            'article_revisions' => Schema::hasTable('article_revisions') ? DB::table('article_revisions')->count() : null,
            'article_translation_revisions' => Schema::hasTable('article_translation_revisions') ? DB::table('article_translation_revisions')->count() : null,
        ], static fn ($value): bool => is_int($value));
    }

    /**
     * @return list<string>
     */
    private function forbiddenOutputStrings(): array
    {
        return [
            'raw_url',
            'raw_query',
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
        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
