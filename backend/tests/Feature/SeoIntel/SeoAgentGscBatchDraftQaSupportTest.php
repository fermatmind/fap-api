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

final class SeoAgentGscBatchDraftQaSupportTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_summarizes_multiple_written_article_drafts_without_mutating_rows(): void
    {
        [$packagePath, $writeEvidencePath] = $this->batchFixture();
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-agent:gsc-batch-draft-qa-support', [
            '--package' => $packagePath,
            '--write-evidence' => $writeEvidencePath,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertSame(2, $summary['target_count'] ?? null);
        $this->assertSame(0, $summary['mismatch_count'] ?? null);
        $this->assertSame($countsBefore, $this->rowCounts());

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertSame(2, $artifact['target_count'] ?? null);
        $this->assertCount(2, $artifact['target_verdicts'] ?? []);
        $this->assertContains('proposal.proposed_internal_link_actions', data_get($artifact, 'target_verdicts.0.matched_fields', []));
        $this->assertContains('proposal.proposal_quality', data_get($artifact, 'target_verdicts.0.matched_fields', []));
        $this->assertSame('required_pending', data_get($artifact, 'target_verdicts.0.claim_risk_handoff_status'));
        $this->assertSame('gsc_cohort_artifact', data_get($artifact, 'target_verdicts.0.proposal_quality.source'));
        $this->assertNotEmpty(data_get($artifact, 'target_verdicts.0.internal_link_actions'));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.cms_write', true));
    }

    #[Test]
    public function it_reports_per_target_mismatches_without_breaking_single_target_readback_qa(): void
    {
        [$packagePath, $writeEvidencePath, $first] = $this->batchFixture(mismatchFirst: true);

        $exitCode = Artisan::call('seo-agent:gsc-batch-draft-qa-support', [
            '--package' => $packagePath,
            '--write-evidence' => $writeEvidencePath,
            '--target' => [$first],
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('review_required', $summary['status'] ?? null);
        $this->assertSame(1, $summary['target_count'] ?? null);
        $this->assertGreaterThanOrEqual(1, $summary['mismatch_count'] ?? 0);

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertContains('field_mismatch:proposal.proposed_seo_title', data_get($artifact, 'target_verdicts.0.mismatches', []));
    }

    #[Test]
    public function it_fails_closed_for_invalid_schema_package_sha_mismatch_and_forbidden_inputs(): void
    {
        [$packagePath, $writeEvidencePath] = $this->batchFixture();
        $invalid = $this->writeJson('bad-package-', ['schema_version' => 'wrong']);

        $exitCode = Artisan::call('seo-agent:gsc-batch-draft-qa-support', [
            '--package' => $invalid,
            '--write-evidence' => $writeEvidencePath,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('package_schema_invalid', $summary['issues'] ?? []);

        $badWrite = $this->writeEvidence('bad-sha', []);
        $exitCode = Artisan::call('seo-agent:gsc-batch-draft-qa-support', [
            '--package' => $packagePath,
            '--write-evidence' => $badWrite,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('write_evidence_package_sha256_mismatch', $summary['issues'] ?? []);

        $forbidden = $this->writeJson('forbidden-write-', [
            'schema_version' => 'seo-agent-controlled-cms-draft-write.v1',
            'raw_query' => 'blocked',
        ]);
        $exitCode = Artisan::call('seo-agent:gsc-batch-draft-qa-support', [
            '--package' => $packagePath,
            '--write-evidence' => $forbidden,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('forbidden_input_field_present', $summary['issues'] ?? []);
    }

    #[Test]
    public function generated_contract_documents_batch_draft_qa_boundaries(): void
    {
        $contract = $this->readJson(base_path('docs/seo/generated/seo-agent-gsc-batch-draft-qa-support.v1.json'));

        $this->assertSame('seo-agent-gsc-batch-draft-qa-support.v1', $contract['version'] ?? null);
        $this->assertContains('target_verdicts', $contract['output_fields'] ?? []);
        $this->assertTrue((bool) data_get($contract, 'compatibility.single_target_readback_qa_unchanged'));
        $this->assertFalse((bool) ($contract['mutates_cms'] ?? true));
        $this->assertFalse((bool) ($contract['publishes_content'] ?? true));
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function batchFixture(bool $mismatchFirst = false): array
    {
        $articleA = $this->article('article-a', 'en');
        $articleB = $this->article('article-b', 'zh-CN');
        $proposalA = $this->proposal((int) $articleA->id, 'en');
        $proposalB = $this->proposal((int) $articleB->id, 'zh-CN');
        $packagePath = $this->writePackage([$proposalA, $proposalB]);
        $sha = hash_file('sha256', $packagePath) ?: '';
        $revisionA = $this->revision($articleA, $proposalA, $sha, $mismatchFirst);
        $revisionB = $this->revision($articleB, $proposalB, $sha);
        $writeEvidencePath = $this->writeEvidence($sha, [
            ['target_model' => 'article', 'subject_ref' => $proposalA['subject_ref'], 'revision_id' => (int) $revisionA->id],
            ['target_model' => 'article', 'subject_ref' => $proposalB['subject_ref'], 'revision_id' => (int) $revisionB->id],
        ]);

        return [$packagePath, $writeEvidencePath, $proposalA['subject_ref']];
    }

    private function article(string $slug, string $locale): Article
    {
        return Article::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'locale' => $locale,
            'title' => $slug,
            'excerpt' => 'Existing excerpt.',
            'content_md' => 'Existing markdown.',
            'content_html' => '<p>Existing HTML.</p>',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_revision_id' => 100,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function proposal(int $articleId, string $locale): array
    {
        return [
            'source_family' => 'gsc_cohort_artifact',
            'subject_type' => 'article',
            'target_model' => 'article',
            'subject_ref' => 'article:'.$articleId.':'.$locale,
            'safe_path' => $locale === 'en' ? '/en/articles/article-a' : '/zh/articles/article-b',
            'proposed_seo_title' => $locale === 'en' ? 'Article A | FermatMind' : '文章 B | FermatMind',
            'proposed_seo_description' => $locale === 'en' ? 'Careful description.' : '谨慎的中文描述。',
            'proposed_faq_items' => [['question' => 'Question?', 'answer' => 'Answer.']],
            'proposed_internal_link_actions' => ['Add internal link to /en/tests/big-five-personality-test'],
            'proposal_quality' => [
                'source' => 'gsc_cohort_artifact',
                'locale_preserved' => true,
                'slug_generated_copy' => false,
                'needs_human_approval' => true,
            ],
            'claim_gate_required' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function revision(Article $article, array $proposal, string $sha, bool $mismatch = false): ArticleRevision
    {
        return ArticleRevision::query()->create([
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'revision_no' => 2,
            'title' => (string) $article->title,
            'excerpt' => (string) $article->excerpt,
            'content_md' => (string) $article->content_md,
            'content_html' => (string) $article->content_html,
            'payload_json' => [
                'seo_agent' => [
                    'package_sha256' => $sha,
                    'subject_ref' => $proposal['subject_ref'],
                ],
                'proposal' => [
                    'proposed_seo_title' => $mismatch ? 'Wrong title' : $proposal['proposed_seo_title'],
                    'proposed_seo_description' => $proposal['proposed_seo_description'],
                    'proposed_faq_items' => $proposal['proposed_faq_items'],
                    'proposed_internal_link_actions' => $proposal['proposed_internal_link_actions'],
                    'proposal_quality' => $proposal['proposal_quality'],
                ],
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $proposals
     */
    private function writePackage(array $proposals): string
    {
        return $this->writeJson('seo-agent-cms-draft-package-', [
            'schema_version' => 'seo-agent-cms-draft-package-dry-run.v1',
            'dry_run' => true,
            'cms_write_allowed' => false,
            'execution_permission' => false,
            'draft_brief_count' => count($proposals),
            'draft_briefs' => $proposals,
            'proposal_items' => $proposals,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $refs
     */
    private function writeEvidence(string $sha, array $refs): string
    {
        return $this->writeJson('seo-agent-controlled-cms-draft-write-', [
            'schema_version' => 'seo-agent-controlled-cms-draft-write.v1',
            'status' => 'success',
            'execute' => true,
            'writes_attempted' => true,
            'package_sha256' => $sha,
            'affected_refs' => $refs,
        ]);
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-agent-gsc-batch-draft-qa-support-'.Str::uuid()->toString());
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
