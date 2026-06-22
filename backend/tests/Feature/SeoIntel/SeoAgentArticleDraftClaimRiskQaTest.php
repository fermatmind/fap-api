<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Article;
use App\Models\ArticleRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoAgentArticleDraftClaimRiskQaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_passes_claim_risk_qa_for_safe_article_draft_without_mutating_rows(): void
    {
        [$article, $proposal, $packagePath, $sha, $revision, $writeEvidencePath] = $this->draftFixture();
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-agent:article-draft-claim-risk-qa', [
            '--package' => $packagePath,
            '--write-evidence' => $writeEvidencePath,
            '--target' => $proposal['subject_ref'],
            '--revision-id' => (string) $revision->id,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertSame(0, $summary['critical_finding_count'] ?? null);
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.cms_write', true));
        $this->assertSame($countsBefore, $this->rowCounts());

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertTrue((bool) ($artifact['ok'] ?? false));
        $this->assertSame('success', $artifact['status'] ?? null);
        $this->assertSame($sha, $artifact['package_sha256'] ?? null);
        $this->assertSame((int) $revision->id, data_get($artifact, 'draft_revision.revision_id'));
        $this->assertSame((int) $article->published_revision_id, data_get($artifact, 'live_article_state.published_revision_id'));
        $this->assertFalse((bool) data_get($artifact, 'draft_revision.is_published_revision', true));
        $this->assertContains('title', array_column($artifact['field_verdicts'] ?? [], 'field'));
        $this->assertContains('meta', array_column($artifact['field_verdicts'] ?? [], 'field'));
        $this->assertContains('faq', array_column($artifact['field_verdicts'] ?? [], 'field'));
        $this->assertContains('internal_link', array_column($artifact['field_verdicts'] ?? [], 'field'));

        $encoded = json_encode($artifact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        foreach ($this->forbiddenStrings() as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded, $forbidden);
        }
    }

    #[Test]
    public function it_returns_review_required_for_non_critical_locale_or_ranking_risk(): void
    {
        [, $proposal, $packagePath, , $revision, $writeEvidencePath] = $this->draftFixture([
            'proposed_seo_title' => 'Top Big Five Career Fit | FermatMind',
        ]);

        $exitCode = Artisan::call('seo-agent:article-draft-claim-risk-qa', [
            '--package' => $packagePath,
            '--write-evidence' => $writeEvidencePath,
            '--target' => $proposal['subject_ref'],
            '--revision-id' => (string) $revision->id,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('review_required', $summary['status'] ?? null);
        $this->assertSame(0, $summary['critical_finding_count'] ?? null);
        $this->assertGreaterThanOrEqual(1, $summary['warning_finding_count'] ?? 0);

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertContains('ranking_or_certainty_claim', array_column($artifact['findings'] ?? [], 'issue'));
    }

    #[Test]
    public function it_blocks_critical_claims_and_unsafe_internal_link_actions(): void
    {
        [, $proposal, $packagePath, , $revision, $writeEvidencePath] = $this->draftFixture([
            'proposed_seo_description' => 'This diagnostic report guarantees career success.',
            'proposed_internal_link_actions' => ['Add link to https://example.com/private'],
        ]);

        $exitCode = Artisan::call('seo-agent:article-draft-claim-risk-qa', [
            '--package' => $packagePath,
            '--write-evidence' => $writeEvidencePath,
            '--target' => $proposal['subject_ref'],
            '--revision-id' => (string) $revision->id,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertGreaterThanOrEqual(1, $summary['critical_finding_count'] ?? 0);

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $issues = array_column($artifact['findings'] ?? [], 'issue');
        $this->assertContains('clinical_or_diagnostic_claim', $issues);
        $this->assertContains('guaranteed_outcome_claim', $issues);
        $this->assertContains('unsafe_full_url_internal_link_action', $issues);
    }

    #[Test]
    public function it_fails_closed_for_wrong_revision_schema_missing_target_and_forbidden_inputs(): void
    {
        [, $proposal, $packagePath, , $revision, $writeEvidencePath] = $this->draftFixture();

        $exitCode = Artisan::call('seo-agent:article-draft-claim-risk-qa', [
            '--package' => $packagePath,
            '--write-evidence' => $writeEvidencePath,
            '--target' => $proposal['subject_ref'],
            '--revision-id' => (string) (((int) $revision->id) + 999),
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('revision_id_write_evidence_mismatch', $summary['issues'] ?? []);

        $badSchema = $this->writeJson('bad-package-', ['schema_version' => 'wrong']);
        $exitCode = Artisan::call('seo-agent:article-draft-claim-risk-qa', [
            '--package' => $badSchema,
            '--write-evidence' => $writeEvidencePath,
            '--target' => $proposal['subject_ref'],
            '--revision-id' => (string) $revision->id,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('package_schema_invalid', $summary['issues'] ?? []);

        $exitCode = Artisan::call('seo-agent:article-draft-claim-risk-qa', [
            '--package' => $packagePath,
            '--write-evidence' => $writeEvidencePath,
            '--target' => 'article:999:en',
            '--revision-id' => (string) $revision->id,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('target_not_found_in_package', $summary['issues'] ?? []);

        $forbiddenPackage = $this->writeJson('forbidden-package-', [
            'schema_version' => 'seo-agent-cms-draft-package-dry-run.v1',
            'dry_run' => true,
            'cms_write_allowed' => false,
            'execution_permission' => false,
            'proposal_items' => [
                ['subject_ref' => $proposal['subject_ref'], 'raw_query' => 'blocked'],
            ],
        ]);
        $exitCode = Artisan::call('seo-agent:article-draft-claim-risk-qa', [
            '--package' => $forbiddenPackage,
            '--write-evidence' => $writeEvidencePath,
            '--target' => $proposal['subject_ref'],
            '--revision-id' => (string) $revision->id,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('forbidden_input_field_present', $summary['issues'] ?? []);
    }

    #[Test]
    public function generated_contract_documents_claim_risk_qa_boundaries(): void
    {
        $contract = $this->readJson(base_path('docs/seo/generated/seo-agent-article-draft-claim-risk-qa.v1.json'));

        $this->assertSame('seo-agent-article-draft-claim-risk-qa.v1', $contract['version'] ?? null);
        $this->assertSame('php artisan seo-agent:article-draft-claim-risk-qa', $contract['command'] ?? null);
        $this->assertContains('article', $contract['supported_targets'] ?? []);
        $this->assertContains('clinical_or_diagnostic_claim', $contract['risk_categories'] ?? []);
        $this->assertFalse((bool) ($contract['mutates_cms'] ?? true));
        $this->assertFalse((bool) ($contract['publishes_content'] ?? true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.search_channel_submit', true));
    }

    /**
     * @param  array<string, mixed>  $proposalOverrides
     * @return array{0: Article, 1: array<string, mixed>, 2: string, 3: string, 4: ArticleRevision, 5: string}
     */
    private function draftFixture(array $proposalOverrides = []): array
    {
        $article = $this->article();
        $proposal = [
            ...$this->proposal((int) $article->id),
            ...$proposalOverrides,
        ];
        $packagePath = $this->writePackage([$proposal]);
        $sha = hash_file('sha256', $packagePath) ?: '';
        $revision = $this->articleRevision($article, $proposal, $sha);
        $writeEvidencePath = $this->writeEvidence($sha, $proposal['subject_ref'], (int) $revision->id);

        return [$article, $proposal, $packagePath, $sha, $revision, $writeEvidencePath];
    }

    private function article(): Article
    {
        return Article::query()->create([
            'org_id' => 0,
            'slug' => 'big-five-career-fit',
            'locale' => 'en',
            'title' => 'Big Five Career Fit',
            'excerpt' => 'Existing excerpt.',
            'content_md' => 'Existing article markdown.',
            'content_html' => '<p>Existing article HTML.</p>',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'working_revision_id' => 101,
            'published_revision_id' => 100,
            'published_at' => now()->subDay(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function proposal(int $articleId): array
    {
        return [
            'source_id' => hash('sha256', 'article'.$articleId),
            'source_family' => 'gsc_cohort_artifact',
            'target_model' => 'article',
            'subject_type' => 'article',
            'subject_ref' => 'article:'.$articleId.':en',
            'safe_path' => '/en/articles/big-five-career-fit',
            'severity' => 'p1',
            'target_fields' => ['seo_title', 'seo_description', 'faq_items'],
            'proposed_seo_title' => 'Big Five Career Fit | FermatMind',
            'proposed_seo_description' => 'Review Big Five career fit patterns with careful, non-diagnostic guidance.',
            'proposed_faq_items' => [
                [
                    'question' => 'Can Big Five scores explain every career outcome?',
                    'answer' => 'No. They can support reflection, but they do not guarantee outcomes.',
                ],
            ],
            'proposed_internal_link_actions' => [
                'Add internal link to /en/tests/big-five-personality-test',
            ],
            'proposal_quality' => [
                'source' => 'gsc_cohort_artifact',
                'locale_preserved' => true,
                'slug_generated_copy' => false,
                'needs_human_approval' => true,
            ],
            'claim_gate_required' => true,
            'human_approval_required' => true,
            'execution_permission' => false,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $proposals
     */
    private function writePackage(array $proposals): string
    {
        return $this->writeJson('seo-agent-cms-draft-package-', [
            'schema_version' => 'seo-agent-cms-draft-package-dry-run.v1',
            'run_mode' => 'cms_draft_package_dry_run',
            'dry_run' => true,
            'cms_write_allowed' => false,
            'execution_permission' => false,
            'draft_brief_count' => count($proposals),
            'draft_briefs' => $proposals,
            'proposal_count' => count($proposals),
            'proposal_items' => $proposals,
            'claim_gate_required' => true,
            'human_approval_required' => true,
        ]);
    }

    private function writeEvidence(string $packageSha, string $subjectRef, int $revisionId): string
    {
        return $this->writeJson('seo-agent-controlled-cms-draft-write-', [
            'schema_version' => 'seo-agent-controlled-cms-draft-write.v1',
            'ok' => true,
            'status' => 'success',
            'dry_run' => false,
            'execute' => true,
            'package_sha256' => $packageSha,
            'writes_attempted' => true,
            'writes_committed' => true,
            'planned_count' => 1,
            'rows_created' => 1,
            'rows_skipped_existing' => 0,
            'rows_failed' => [],
            'affected_refs' => [
                [
                    'status' => 'created',
                    'target_model' => 'article',
                    'subject_ref' => $subjectRef,
                    'revision_id' => $revisionId,
                ],
            ],
            'negative_guarantees' => [
                'cms_publish' => false,
                'search_channel_submit' => false,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function articleRevision(Article $article, array $proposal, string $packageSha): ArticleRevision
    {
        return ArticleRevision::query()->create([
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'revision_no' => 2,
            'editor_admin_user_id' => null,
            'title' => (string) $article->title,
            'excerpt' => (string) $article->excerpt,
            'content_md' => (string) $article->content_md,
            'content_html' => (string) $article->content_html,
            'change_note' => 'SEO Agent controlled draft proposal',
            'payload_json' => [
                'seo_agent' => [
                    'task' => 'SEO-AGENT-CONTROLLED-CMS-DRAFT-WRITER-01',
                    'package_sha256' => $packageSha,
                    'subject_ref' => $proposal['subject_ref'],
                    'target_fields' => $proposal['target_fields'],
                    'claim_gate_required' => true,
                    'human_approval_required' => true,
                    'publish_allowed' => false,
                    'search_submit_allowed' => false,
                    'indexing_request_allowed' => false,
                ],
                'proposal' => [
                    'safe_path' => $proposal['safe_path'],
                    'proposed_seo_title' => $proposal['proposed_seo_title'],
                    'proposed_seo_description' => $proposal['proposed_seo_description'],
                    'proposed_faq_items' => $proposal['proposed_faq_items'],
                    'proposed_internal_link_actions' => $proposal['proposed_internal_link_actions'],
                    'proposal_quality' => $proposal['proposal_quality'],
                    'proposed_canonical_path' => null,
                    'proposed_indexability' => null,
                ],
            ],
            'created_at' => now(),
        ]);
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-agent-article-draft-claim-risk-qa-'.Str::uuid()->toString());
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $prefix, array $payload): string
    {
        $path = storage_path('framework/testing/'.$prefix.Str::uuid()->toString().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return $path;
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return [
            'articles' => Article::query()->count(),
            'article_revisions' => ArticleRevision::query()->count(),
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
    private function readJson(string $path): array
    {
        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($payload);

        return $payload;
    }
}
