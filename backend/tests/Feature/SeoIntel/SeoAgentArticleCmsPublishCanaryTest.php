<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoAgentArticleCmsPublishCanaryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dry_run_plans_one_article_publish_without_writing(): void
    {
        $fixture = $this->fixture();
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-agent:article-cms-publish-canary', [
            '--package' => $fixture['package_path'],
            '--write-evidence' => $fixture['write_evidence_path'],
            '--publish-gate-evidence' => $fixture['gate_evidence_path'],
            '--target' => $fixture['target'],
            '--revision-id' => $fixture['draft_revision_id'],
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('planned', $summary['status'] ?? null);
        $this->assertTrue((bool) ($summary['would_publish'] ?? false));
        $this->assertFalse((bool) ($summary['writes_attempted'] ?? true));
        $this->assertSame($countsBefore, $this->rowCounts());
        $this->assertSame($fixture['approval_phrase'], $summary['required_confirmation_phrase'] ?? null);
        $this->assertFalse((bool) data_get($summary, 'boundaries.cms_publish', true));

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertSame('planned', $artifact['status'] ?? null);
        $this->assertSame($fixture['write_evidence_sha256'], $artifact['write_evidence_sha256'] ?? null);
    }

    #[Test]
    public function dry_run_blocks_seo_meta_title_that_exceeds_runtime_column_limit(): void
    {
        $fixture = $this->fixture([
            'proposed_seo_title' => 'What Is RIASEC? Holland Code Test & 6 Career Types | FermatMind',
        ]);
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-agent:article-cms-publish-canary', [
            '--package' => $fixture['package_path'],
            '--write-evidence' => $fixture['write_evidence_path'],
            '--publish-gate-evidence' => $fixture['gate_evidence_path'],
            '--target' => $fixture['target'],
            '--revision-id' => $fixture['draft_revision_id'],
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertContains('seo_meta_field_length_exceeded', $summary['issues'] ?? []);
        $this->assertSame('fail', data_get($summary, 'field_length_preflight.status'));
        $this->assertSame('article_seo_meta.seo_title', data_get($summary, 'field_length_preflight.violations.0.target_column'));
        $this->assertFalse((bool) ($summary['writes_committed'] ?? true));
        $this->assertSame($countsBefore, $this->rowCounts());
    }

    #[Test]
    public function execute_publishes_one_article_revision_through_translation_revision_without_search_side_effects(): void
    {
        $fixture = $this->fixture();

        $exitCode = Artisan::call('seo-agent:article-cms-publish-canary', [
            '--package' => $fixture['package_path'],
            '--write-evidence' => $fixture['write_evidence_path'],
            '--publish-gate-evidence' => $fixture['gate_evidence_path'],
            '--target' => $fixture['target'],
            '--revision-id' => $fixture['draft_revision_id'],
            '--artifact-dir' => $this->artifactDir(),
            '--confirm-write-evidence-sha256' => $fixture['write_evidence_sha256'],
            '--confirm-publish' => $fixture['approval_phrase'],
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertTrue((bool) ($summary['writes_attempted'] ?? false));
        $this->assertTrue((bool) ($summary['writes_committed'] ?? false));
        $this->assertSame(1, $summary['published_count'] ?? null);
        $this->assertTrue((bool) data_get($summary, 'boundaries.cms_publish'));
        $this->assertFalse((bool) data_get($summary, 'boundaries.url_truth_write', true));
        $this->assertFalse((bool) data_get($summary, 'boundaries.indexnow_submit', true));
        $this->assertFalse((bool) data_get($summary, 'boundaries.search_channel_submit', true));
        $this->assertFalse((bool) data_get($summary, 'boundaries.indexing_request', true));
        $this->assertFalse((bool) data_get($summary, 'boundaries.scheduler_activation', true));

        $article = Article::query()->withoutGlobalScopes()->findOrFail($fixture['article_id']);
        $translationRevisionId = (int) data_get($summary, 'affected_refs.0.article_translation_revision_id');
        $translationRevision = ArticleTranslationRevision::query()->withoutGlobalScopes()->findOrFail($translationRevisionId);

        $this->assertSame($translationRevisionId, (int) $article->published_revision_id);
        $this->assertSame($translationRevisionId, (int) $article->working_revision_id);
        $this->assertSame(ArticleTranslationRevision::STATUS_PUBLISHED, (string) $translationRevision->revision_status);
        $this->assertSame('Improved Article Title | FermatMind', (string) $translationRevision->seo_title);
        $this->assertSame('Improved description for search readers.', (string) $translationRevision->seo_description);
        $this->assertSame('Improved Article Title | FermatMind', (string) $article->seoMeta?->seo_title);
        $this->assertSame('Improved description for search readers.', (string) $article->seoMeta?->seo_description);
        $this->assertTrue(ArticleRevision::query()->withoutGlobalScopes()->whereKey($fixture['draft_revision_id'])->exists());

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertSame('success', $artifact['status'] ?? null);
        $this->assertSame($translationRevisionId, data_get($artifact, 'affected_refs.0.article_translation_revision_id'));
    }

    #[Test]
    public function execute_supports_legacy_foreign_published_revision_pointer_without_superseding_it(): void
    {
        $fixture = $this->fixture(['foreign_current_pointer' => true]);

        $exitCode = Artisan::call('seo-agent:article-cms-publish-canary', [
            '--package' => $fixture['package_path'],
            '--write-evidence' => $fixture['write_evidence_path'],
            '--publish-gate-evidence' => $fixture['gate_evidence_path'],
            '--target' => $fixture['target'],
            '--revision-id' => $fixture['draft_revision_id'],
            '--artifact-dir' => $this->artifactDir(),
            '--confirm-write-evidence-sha256' => $fixture['write_evidence_sha256'],
            '--confirm-publish' => $fixture['approval_phrase'],
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $translationRevisionId = (int) data_get($summary, 'affected_refs.0.article_translation_revision_id');
        $translationRevision = ArticleTranslationRevision::query()->withoutGlobalScopes()->findOrFail($translationRevisionId);

        $this->assertNull($translationRevision->supersedes_revision_id);
        $this->assertSame($fixture['legacy_foreign_revision_id'], data_get($summary, 'rollback_evidence.previous_article_state.published_revision_id'));
        $this->assertSame($translationRevisionId, (int) Article::query()->withoutGlobalScopes()->findOrFail($fixture['article_id'])->published_revision_id);
    }

    #[Test]
    public function execute_fails_closed_for_bad_gate_or_write_evidence_confirmation(): void
    {
        $fixture = $this->fixture([
            'gate_status' => 'review_required',
            'approval_phrase' => null,
        ]);

        $exitCode = Artisan::call('seo-agent:article-cms-publish-canary', [
            '--package' => $fixture['package_path'],
            '--write-evidence' => $fixture['write_evidence_path'],
            '--publish-gate-evidence' => $fixture['gate_evidence_path'],
            '--target' => $fixture['target'],
            '--revision-id' => $fixture['draft_revision_id'],
            '--artifact-dir' => $this->artifactDir(),
            '--confirm-write-evidence-sha256' => str_repeat('0', 64),
            '--confirm-publish' => 'wrong',
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertContains('publish_gate_not_ready', $summary['issues'] ?? []);
        $this->assertContains('write_evidence_sha256_confirmation_mismatch', $summary['issues'] ?? []);
        $this->assertFalse((bool) ($summary['writes_committed'] ?? true));
        $this->assertSame(1, ArticleTranslationRevision::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function generated_contract_documents_article_publish_boundaries(): void
    {
        $contract = $this->readJson(base_path('docs/seo/generated/seo-agent-article-cms-publish-canary.v1.json'));

        $this->assertSame('seo-agent-article-cms-publish-canary.v1', $contract['version'] ?? null);
        $this->assertSame('php artisan seo-agent:article-cms-publish-canary', $contract['command'] ?? null);
        $this->assertContains('article', $contract['supported_targets_v1'] ?? []);
        $this->assertSame(1, $contract['max_rows_per_execution'] ?? null);
        $this->assertFalse((bool) data_get($contract, 'post_publish_boundaries.indexnow_submit', true));
        $this->assertFalse((bool) data_get($contract, 'post_publish_boundaries.search_channel_submit', true));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fixture(array $overrides = []): array
    {
        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => 'article-candidate',
            'locale' => 'en',
            'translation_group_id' => (string) Str::uuid(),
            'source_locale' => 'en',
            'title' => 'Article Candidate',
            'excerpt' => 'Existing excerpt.',
            'content_md' => 'Existing article markdown.',
            'content_html' => '<p>Existing article HTML.</p>',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'published_at' => now()->subDay(),
        ]);
        $publishedRevision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => 'en',
            'source_locale' => 'en',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => 'Article Candidate',
            'excerpt' => 'Existing excerpt.',
            'content_md' => 'Existing article markdown.',
            'seo_title' => 'Old Article Title | FermatMind',
            'seo_description' => 'Old description.',
            'published_at' => now()->subDay(),
        ]);
        $legacyForeignRevisionId = null;
        if ((bool) ($overrides['foreign_current_pointer'] ?? false)) {
            $foreignArticle = Article::query()->create([
                'org_id' => 0,
                'slug' => 'foreign-article',
                'locale' => 'en',
                'translation_group_id' => (string) Str::uuid(),
                'source_locale' => 'en',
                'title' => 'Foreign Article',
                'excerpt' => 'Foreign excerpt.',
                'content_md' => 'Foreign markdown.',
                'content_html' => '<p>Foreign HTML.</p>',
                'status' => 'published',
                'is_public' => true,
                'is_indexable' => true,
                'published_at' => now()->subDay(),
            ]);
            $foreignRevision = ArticleTranslationRevision::query()->create([
                'org_id' => 0,
                'article_id' => (int) $foreignArticle->id,
                'source_article_id' => (int) $foreignArticle->id,
                'translation_group_id' => (string) $foreignArticle->translation_group_id,
                'locale' => 'en',
                'source_locale' => 'en',
                'revision_number' => 1,
                'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
                'title' => 'Foreign Article',
                'excerpt' => 'Foreign excerpt.',
                'content_md' => 'Foreign markdown.',
                'published_at' => now()->subDay(),
            ]);
            $legacyForeignRevisionId = (int) $foreignRevision->id;
        }
        $article->forceFill([
            'working_revision_id' => $legacyForeignRevisionId ?: (int) $publishedRevision->id,
            'published_revision_id' => $legacyForeignRevisionId ?: (int) $publishedRevision->id,
        ])->save();
        ArticleSeoMeta::query()->create([
            'article_id' => (int) $article->id,
            'locale' => 'en',
            'seo_title' => 'Old Article Title | FermatMind',
            'seo_description' => 'Old description.',
            'canonical_url' => '/en/articles/article-candidate',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        $target = 'article:'.$article->id.':en';
        $proposedSeoTitle = (string) ($overrides['proposed_seo_title'] ?? 'Improved Article Title | FermatMind');
        $packagePath = $this->writePackage($target, $proposedSeoTitle);
        $packageSha = hash_file('sha256', $packagePath) ?: '';
        $draftRevision = ArticleRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'revision_no' => 2,
            'editor_admin_user_id' => null,
            'title' => 'Article Candidate',
            'excerpt' => 'Existing excerpt.',
            'content_md' => 'Existing article markdown.',
            'content_html' => '<p>Existing article HTML.</p>',
            'change_note' => 'SEO Agent controlled draft proposal',
            'payload_json' => [
                'seo_agent' => [
                    'task' => 'SEO-AGENT-CONTROLLED-CMS-DRAFT-WRITER-01',
                    'package_sha256' => $packageSha,
                    'subject_ref' => $target,
                    'target_fields' => ['seo_title', 'seo_description'],
                    'claim_gate_required' => true,
                    'human_approval_required' => true,
                    'publish_allowed' => false,
                    'search_submit_allowed' => false,
                    'indexing_request_allowed' => false,
                ],
                'proposal' => [
                    'safe_path' => '/en/articles/article-candidate',
                    'proposed_seo_title' => $proposedSeoTitle,
                    'proposed_seo_description' => 'Improved description for search readers.',
                    'proposed_faq_items' => [],
                    'proposal_quality' => [
                        'source' => 'gsc_cohort_artifact',
                        'locale_preserved' => true,
                        'slug_generated_copy' => false,
                        'needs_human_approval' => true,
                    ],
                ],
            ],
            'created_at' => now(),
        ]);
        $writeEvidencePath = $this->writeEvidence($target, (int) $draftRevision->id, $packageSha);
        $writeSha = hash_file('sha256', $writeEvidencePath) ?: '';
        $approvalPhrase = $overrides['approval_phrase'] ?? 'I explicitly approve production CMS publish canary for '.$target.' revision '.$draftRevision->id.' using write evidence sha256 '.$writeSha.'; no URL Truth, no sitemap, no IndexNow, no search, no indexing, no scheduler.';
        $gateEvidencePath = $this->writeGateEvidence($target, (int) $draftRevision->id, (string) ($overrides['gate_status'] ?? 'publish_ready'), $approvalPhrase);

        return [
            'article_id' => (int) $article->id,
            'target' => $target,
            'draft_revision_id' => (int) $draftRevision->id,
            'package_path' => $packagePath,
            'package_sha256' => $packageSha,
            'write_evidence_path' => $writeEvidencePath,
            'write_evidence_sha256' => $writeSha,
            'gate_evidence_path' => $gateEvidencePath,
            'approval_phrase' => $approvalPhrase,
            'legacy_foreign_revision_id' => $legacyForeignRevisionId,
        ];
    }

    private function writePackage(string $target, string $proposedSeoTitle): string
    {
        return $this->writeJson('seo-agent-cms-draft-package-', [
            'schema_version' => 'seo-agent-cms-draft-package-dry-run.v1',
            'dry_run' => true,
            'cms_write_allowed' => false,
            'execution_permission' => false,
            'proposal_count' => 1,
            'proposal_items' => [
                [
                    'target_model' => 'article',
                    'subject_type' => 'article',
                    'subject_ref' => $target,
                    'safe_path' => '/en/articles/article-candidate',
                    'target_fields' => ['seo_title', 'seo_description'],
                    'proposed_seo_title' => $proposedSeoTitle,
                    'proposed_seo_description' => 'Improved description for search readers.',
                    'claim_gate_required' => true,
                    'human_approval_required' => true,
                    'execution_permission' => false,
                ],
            ],
        ]);
    }

    private function writeEvidence(string $target, int $revisionId, string $packageSha): string
    {
        return $this->writeJson('seo-agent-controlled-cms-draft-write-', [
            'schema_version' => 'seo-agent-controlled-cms-draft-write.v1',
            'ok' => true,
            'status' => 'success',
            'execute' => true,
            'package_sha256' => $packageSha,
            'affected_refs' => [
                [
                    'status' => 'created',
                    'target_model' => 'article',
                    'subject_ref' => $target,
                    'revision_id' => $revisionId,
                ],
            ],
            'negative_guarantees' => [
                'cms_publish' => false,
                'search_channel_enqueue' => false,
                'indexing_request' => false,
            ],
        ]);
    }

    private function writeGateEvidence(string $target, int $revisionId, string $status, ?string $approvalPhrase): string
    {
        return $this->writeJson('seo-agent-gsc-draft-publish-gate-readiness-', [
            'schema_version' => 'seo-agent-gsc-draft-publish-gate-readiness.v1',
            'ok' => true,
            'status' => $status === 'publish_ready' ? 'publish_ready' : 'review_required',
            'draft_count' => 1,
            'publish_ready_count' => $status === 'publish_ready' ? 1 : 0,
            'draft_verdicts' => [
                [
                    'subject_ref' => $target,
                    'revision_id' => $revisionId,
                    'gate_status' => $status,
                    'issues' => $status === 'publish_ready' ? [] : ['claim_risk_qa_missing'],
                    'readback_qa_status' => 'success',
                    'claim_risk_qa_status' => $status === 'publish_ready' ? 'success' : 'missing',
                    'preview_runtime_qa_status' => 'success',
                    'publish_approval_phrase' => $approvalPhrase,
                ],
            ],
            'negative_guarantees' => [
                'cms_publish' => false,
                'indexnow_submit' => false,
                'search_channel_submit' => false,
            ],
        ]);
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-agent-article-cms-publish-canary-'.Str::uuid()->toString());
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
            'article_revisions' => ArticleRevision::query()->withoutGlobalScopes()->count(),
            'article_translation_revisions' => ArticleTranslationRevision::query()->withoutGlobalScopes()->count(),
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
