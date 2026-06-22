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

final class SeoAgentCmsDraftPayloadRepairCanaryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dry_run_plans_one_repair_without_writing_rows(): void
    {
        [$article, $proposal, $packagePath, $sha, $oldRevision, $writeEvidencePath] = $this->oldDraftMissingOptionalFields();
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-agent:cms-draft-payload-repair-canary', [
            '--package' => $packagePath,
            '--write-evidence' => $writeEvidencePath,
            '--target' => $proposal['subject_ref'],
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('planned', $summary['status'] ?? null);
        $this->assertTrue((bool) ($summary['dry_run'] ?? false));
        $this->assertTrue((bool) ($summary['would_append_revision'] ?? false));
        $this->assertSame($sha, $summary['package_sha256'] ?? null);
        $this->assertSame((int) $oldRevision->id, data_get($summary, 'old_revision.revision_id'));
        $this->assertNull(data_get($summary, 'new_revision.revision_id'));
        $this->assertSame([
            'proposal.proposal_quality',
            'proposal.proposed_internal_link_actions',
        ], $summary['mismatches_repaired'] ?? null);
        $this->assertStringContainsString($proposal['subject_ref'], (string) ($summary['required_confirmation_phrase'] ?? ''));
        $this->assertSame($countsBefore, $this->rowCounts());
        $this->assertSame((int) $article->published_revision_id, Article::query()->findOrFail((int) $article->id)->published_revision_id);

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertSame('planned', $artifact['status'] ?? null);
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.cms_publish', true));
    }

    #[Test]
    public function execute_appends_repaired_revision_and_readback_qa_succeeds_with_compatible_evidence(): void
    {
        [$article, $proposal, $packagePath, $sha, $oldRevision, $writeEvidencePath] = $this->oldDraftMissingOptionalFields();
        $oldPayload = $oldRevision->payload_json;
        $phrase = $this->approvalPhrase($proposal['subject_ref'], $sha);
        $artifactDir = $this->artifactDir();

        $exitCode = Artisan::call('seo-agent:cms-draft-payload-repair-canary', [
            '--package' => $packagePath,
            '--write-evidence' => $writeEvidencePath,
            '--target' => $proposal['subject_ref'],
            '--confirm-package-sha256' => $sha,
            '--confirm-repair' => $phrase,
            '--artifact-dir' => $artifactDir,
            '--execute' => true,
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertFalse((bool) ($summary['dry_run'] ?? true));
        $this->assertSame(1, $summary['rows_created'] ?? null);
        $this->assertSame(2, ArticleRevision::query()->where('article_id', (int) $article->id)->count());

        $oldRevision->refresh();
        $this->assertSame($oldPayload, $oldRevision->payload_json);

        $newRevision = ArticleRevision::query()->findOrFail((int) data_get($summary, 'new_revision.revision_id'));
        $this->assertSame(((int) $oldRevision->revision_no) + 1, (int) $newRevision->revision_no);
        $this->assertSame((string) $oldRevision->content_md, (string) $newRevision->content_md);
        $this->assertSame($proposal['proposed_internal_link_actions'], data_get($newRevision->payload_json, 'proposal.proposed_internal_link_actions'));
        $this->assertSame('gsc_cohort_artifact', data_get($newRevision->payload_json, 'proposal.proposal_quality.source'));
        $this->assertSame('SEO-AGENT-CMS-DRAFT-PAYLOAD-REPAIR-CANARY-01', data_get($newRevision->payload_json, 'seo_agent.repair_task'));

        $freshArticle = Article::query()->withoutGlobalScopes()->findOrFail((int) $article->id);
        $this->assertSame((int) $article->published_revision_id, (int) $freshArticle->published_revision_id);
        $this->assertSame((int) $article->working_revision_id, (int) $freshArticle->working_revision_id);

        $compatibleEvidencePath = (string) data_get($summary, 'compatible_write_evidence.path');
        $compatibleEvidence = $this->readJson($compatibleEvidencePath);
        $this->assertSame('seo-agent-controlled-cms-draft-write.v1', $compatibleEvidence['schema_version'] ?? null);
        $this->assertSame((int) $newRevision->id, data_get($compatibleEvidence, 'affected_refs.0.revision_id'));

        $exitCode = Artisan::call('seo-agent:cms-draft-readback-qa', [
            '--package' => $packagePath,
            '--write-evidence' => $compatibleEvidencePath,
            '--target' => $proposal['subject_ref'],
            '--package-sha256' => $sha,
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ]);
        $readback = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('success', $readback['status'] ?? null);
        $this->assertSame(0, $readback['mismatch_count'] ?? null);
    }

    #[Test]
    public function it_blocks_mismatch_sets_outside_optional_payload_fields(): void
    {
        [, $proposal, $packagePath, , , $writeEvidencePath] = $this->oldDraftMissingOptionalFields([
            'proposed_seo_title' => 'Different title',
        ]);

        $exitCode = Artisan::call('seo-agent:cms-draft-payload-repair-canary', [
            '--package' => $packagePath,
            '--write-evidence' => $writeEvidencePath,
            '--target' => $proposal['subject_ref'],
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('mismatch_set_not_repairable', $summary['issues'] ?? []);
        $this->assertContains('proposal.proposed_seo_title', $summary['mismatches'] ?? []);
    }

    #[Test]
    public function it_blocks_invalid_inputs_missing_article_already_clean_and_forbidden_fields(): void
    {
        [$article, $proposal, $packagePath, $sha, $oldRevision, $writeEvidencePath] = $this->oldDraftMissingOptionalFields();

        $exitCode = Artisan::call('seo-agent:cms-draft-payload-repair-canary', [
            '--package' => $packagePath,
            '--write-evidence' => $writeEvidencePath,
            '--target' => $proposal['subject_ref'],
            '--confirm-package-sha256' => str_repeat('0', 64),
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('package_sha256_confirmation_mismatch', $summary['issues'] ?? []);

        $badSchema = $this->writeJson('bad-package-', ['schema_version' => 'wrong']);
        $exitCode = Artisan::call('seo-agent:cms-draft-payload-repair-canary', [
            '--package' => $badSchema,
            '--write-evidence' => $writeEvidencePath,
            '--target' => $proposal['subject_ref'],
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('package_schema_invalid', $summary['issues'] ?? []);

        $exitCode = Artisan::call('seo-agent:cms-draft-payload-repair-canary', [
            '--package' => $packagePath,
            '--write-evidence' => $writeEvidencePath,
            '--target' => 'article:999:en',
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
        $exitCode = Artisan::call('seo-agent:cms-draft-payload-repair-canary', [
            '--package' => $forbiddenPackage,
            '--write-evidence' => $writeEvidencePath,
            '--target' => $proposal['subject_ref'],
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('forbidden_input_field_present', $summary['issues'] ?? []);

        $oldRevision->forceFill([
            'payload_json' => $this->revisionPayload($proposal, $sha, includeOptionalProposalFields: true),
        ])->save();
        $exitCode = Artisan::call('seo-agent:cms-draft-payload-repair-canary', [
            '--package' => $packagePath,
            '--write-evidence' => $writeEvidencePath,
            '--target' => $proposal['subject_ref'],
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('old_revision_already_clean', $summary['issues'] ?? []);

        $this->assertSame(1, ArticleRevision::query()->where('article_id', (int) $article->id)->count());
    }

    #[Test]
    public function generated_contract_documents_payload_repair_boundaries(): void
    {
        $contract = $this->readJson(base_path('docs/seo/generated/seo-agent-cms-draft-payload-repair-canary.v1.json'));

        $this->assertSame('seo-agent-cms-draft-payload-repair-canary.v1', $contract['version'] ?? null);
        $this->assertSame('php artisan seo-agent:cms-draft-payload-repair-canary', $contract['command'] ?? null);
        $this->assertSame('append_article_revision', $contract['repair_mode'] ?? null);
        $this->assertFalse((bool) ($contract['mutates_old_revision'] ?? true));
        $this->assertFalse((bool) ($contract['publishes_content'] ?? true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.search_channel_submit', true));
    }

    /**
     * @param  array<string, mixed>  $oldProposalOverrides
     * @return array{0: Article, 1: array<string, mixed>, 2: string, 3: string, 4: ArticleRevision, 5: string}
     */
    private function oldDraftMissingOptionalFields(array $oldProposalOverrides = []): array
    {
        $article = $this->article();
        $proposal = $this->proposal((int) $article->id);
        $packagePath = $this->writePackage([$proposal]);
        $sha = hash_file('sha256', $packagePath) ?: '';
        $oldProposal = [
            ...$proposal,
            ...$oldProposalOverrides,
        ];
        $oldRevision = $this->articleRevision($article, $oldProposal, $sha, includeOptionalProposalFields: false);
        $writeEvidencePath = $this->writeEvidence($sha, $proposal['subject_ref'], (int) $oldRevision->id);

        return [$article, $proposal, $packagePath, $sha, $oldRevision, $writeEvidencePath];
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
                    'question' => 'Can Big Five scores predict career success?',
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
    private function articleRevision(Article $article, array $proposal, string $packageSha, bool $includeOptionalProposalFields): ArticleRevision
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
            'payload_json' => $this->revisionPayload($proposal, $packageSha, $includeOptionalProposalFields),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    private function revisionPayload(array $proposal, string $packageSha, bool $includeOptionalProposalFields): array
    {
        $proposalPayload = [
            'safe_path' => $proposal['safe_path'],
            'proposed_seo_title' => $proposal['proposed_seo_title'],
            'proposed_seo_description' => $proposal['proposed_seo_description'],
            'proposed_faq_items' => $proposal['proposed_faq_items'],
            'proposed_canonical_path' => null,
            'proposed_indexability' => null,
        ];
        if ($includeOptionalProposalFields) {
            $proposalPayload['proposed_internal_link_actions'] = $proposal['proposed_internal_link_actions'];
            $proposalPayload['proposal_quality'] = $proposal['proposal_quality'];
        }

        return [
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
            'proposal' => $proposalPayload,
        ];
    }

    private function approvalPhrase(string $target, string $sha): string
    {
        return 'I explicitly approve SEO-AGENT-CMS-DRAFT-PAYLOAD-REPAIR-CANARY-01 to append 1 repaired CMS draft revision for '.$target.' from package sha256 '.$sha.'; no publish, no queue, no search, no indexing, no scheduler.';
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-agent-cms-draft-payload-repair-canary-'.Str::uuid()->toString());
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
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($payload);

        return $payload;
    }
}
