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

final class SeoAgentArticleDraftPreviewRuntimeQaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_verifies_draft_preview_runtime_state_without_mutating_rows(): void
    {
        [$article, $publishedRevision, $draftRevision, $writeEvidencePath] = $this->draftFixture();
        $countsBefore = $this->rowCounts();
        $articleStateBefore = $this->articleState($article);

        $exitCode = Artisan::call('seo-agent:article-draft-preview-runtime-qa', [
            '--write-evidence' => $writeEvidencePath,
            '--target' => 'article:'.$article->id.':en',
            '--revision-id' => (string) $draftRevision->id,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exitCode, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: Artisan::output());
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertTrue((bool) ($summary['preview_readable'] ?? false));
        $this->assertTrue((bool) ($summary['public_runtime_uses_published_revision'] ?? false));
        $this->assertFalse((bool) ($summary['mutation_detected'] ?? true));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.cms_publish', true));

        $this->assertSame($countsBefore, $this->rowCounts());
        $this->assertSame($articleStateBefore, $this->articleState($article->refresh()));

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertTrue((bool) ($artifact['ok'] ?? false));
        $this->assertSame((int) $draftRevision->id, data_get($artifact, 'preview_read.revision_id'));
        $this->assertSame((int) $publishedRevision->id, data_get($artifact, 'public_runtime.published_revision_id'));
        $this->assertFalse((bool) data_get($artifact, 'preview_read.is_published_revision', true));
        $this->assertFalse((bool) data_get($artifact, 'public_runtime.draft_revision_leaked_to_public_runtime', true));
        $this->assertTrue((bool) data_get($artifact, 'read_only_invariants.article_state_unchanged_by_this_read', false));

        $encoded = json_encode($artifact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        foreach ($this->forbiddenStrings() as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded, $forbidden);
        }
    }

    #[Test]
    public function it_blocks_when_the_locked_revision_is_the_public_published_revision(): void
    {
        [$article, $publishedRevision, , $writeEvidencePath] = $this->draftFixture(usePublishedRevisionAsWriteTarget: true);

        $exitCode = Artisan::call('seo-agent:article-draft-preview-runtime-qa', [
            '--write-evidence' => $writeEvidencePath,
            '--target' => 'article:'.$article->id.':en',
            '--revision-id' => (string) $publishedRevision->id,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertContains('draft_revision_is_public_published_revision', array_column($artifact['qa_findings'] ?? [], 'issue'));
        $this->assertFalse((bool) data_get($artifact, 'preview_read.preview_readable', true));
    }

    #[Test]
    public function it_accepts_legacy_inline_published_articles_when_revision_pointer_targets_another_article(): void
    {
        [$article, $foreignRevision, $draftRevision, $writeEvidencePath] = $this->legacyInlinePublishedFixture();

        $exitCode = Artisan::call('seo-agent:article-draft-preview-runtime-qa', [
            '--write-evidence' => $writeEvidencePath,
            '--target' => 'article:'.$article->id.':en',
            '--revision-id' => (string) $draftRevision->id,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exitCode, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: Artisan::output());
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertTrue((bool) ($summary['preview_readable'] ?? false));
        $this->assertFalse((bool) ($summary['public_runtime_uses_published_revision'] ?? true));
        $this->assertFalse((bool) ($summary['mutation_detected'] ?? true));

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertTrue((bool) ($artifact['ok'] ?? false));
        $this->assertSame('article_inline_published_content', data_get($artifact, 'public_runtime.public_runtime_source'));
        $this->assertTrue((bool) data_get($artifact, 'public_runtime.public_runtime_safe', false));
        $this->assertFalse((bool) data_get($artifact, 'public_runtime.published_revision_exists', true));
        $this->assertTrue((bool) data_get($artifact, 'public_runtime.published_revision_pointer_exists', false));
        $this->assertSame((int) $foreignRevision->article_id, data_get($artifact, 'public_runtime.published_revision_pointer_article_id'));
        $this->assertFalse((bool) data_get($artifact, 'public_runtime.draft_revision_leaked_to_public_runtime', true));
        $this->assertSame(0, (int) data_get($artifact, 'critical_finding_count'));
        $this->assertSame(1, (int) data_get($artifact, 'warning_finding_count'));
        $this->assertContains('published_revision_pointer_not_same_article_using_inline_article_content', array_column($artifact['qa_findings'] ?? [], 'issue'));
    }

    #[Test]
    public function it_fails_closed_for_wrong_revision_invalid_schema_missing_target_and_forbidden_inputs(): void
    {
        [$article, , $draftRevision, $writeEvidencePath] = $this->draftFixture();

        $exitCode = Artisan::call('seo-agent:article-draft-preview-runtime-qa', [
            '--write-evidence' => $writeEvidencePath,
            '--target' => 'article:'.$article->id.':en',
            '--revision-id' => (string) (((int) $draftRevision->id) + 999),
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('revision_id_write_evidence_mismatch', $summary['issues'] ?? []);

        $badSchema = $this->writeJson('bad-write-evidence-', ['schema_version' => 'wrong']);
        $exitCode = Artisan::call('seo-agent:article-draft-preview-runtime-qa', [
            '--write-evidence' => $badSchema,
            '--target' => 'article:'.$article->id.':en',
            '--revision-id' => (string) $draftRevision->id,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('write_evidence_schema_invalid', $summary['issues'] ?? []);

        $exitCode = Artisan::call('seo-agent:article-draft-preview-runtime-qa', [
            '--write-evidence' => $writeEvidencePath,
            '--target' => 'article:999:en',
            '--revision-id' => (string) $draftRevision->id,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('target_not_found_in_write_evidence', $summary['issues'] ?? []);

        $forbidden = $this->writeJson('forbidden-write-evidence-', [
            'schema_version' => 'seo-agent-controlled-cms-draft-write.v1',
            'raw_url' => 'https://example.com/private',
        ]);
        $exitCode = Artisan::call('seo-agent:article-draft-preview-runtime-qa', [
            '--write-evidence' => $forbidden,
            '--target' => 'article:'.$article->id.':en',
            '--revision-id' => (string) $draftRevision->id,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('forbidden_input_field_present', $summary['issues'] ?? []);
    }

    #[Test]
    public function generated_contract_documents_preview_runtime_qa_boundaries(): void
    {
        $contract = $this->readJson(base_path('docs/seo/generated/seo-agent-article-draft-preview-runtime-qa.v1.json'));

        $this->assertSame('seo-agent-article-draft-preview-runtime-qa.v1', $contract['version'] ?? null);
        $this->assertSame('php artisan seo-agent:article-draft-preview-runtime-qa', $contract['command'] ?? null);
        $this->assertContains('article', $contract['supported_targets'] ?? []);
        $this->assertFalse((bool) ($contract['mutates_cms'] ?? true));
        $this->assertFalse((bool) ($contract['publishes_content'] ?? true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.live_gsc_api_call', true));
    }

    /**
     * @return array{0: Article, 1: ArticleRevision, 2: ArticleRevision, 3: string}
     */
    private function draftFixture(bool $usePublishedRevisionAsWriteTarget = false): array
    {
        $article = Article::query()->create([
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
            'working_revision_id' => null,
            'published_revision_id' => null,
            'published_at' => now()->subDay(),
        ]);

        $packageSha = hash('sha256', 'package:article:'.$article->id);
        $publishedRevision = $this->articleRevision($article, 1, [
            'seo_agent' => [
                'task' => 'historical_publish',
            ],
        ]);
        $draftRevision = $this->articleRevision($article, 2, [
            'seo_agent' => [
                'task' => 'SEO-AGENT-CONTROLLED-CMS-DRAFT-WRITER-01',
                'package_sha256' => $packageSha,
                'subject_ref' => 'article:'.$article->id.':en',
                'target_fields' => ['seo_title', 'seo_description', 'faq_items'],
                'publish_allowed' => false,
                'search_submit_allowed' => false,
                'indexing_request_allowed' => false,
            ],
            'proposal' => [
                'safe_path' => '/en/articles/big-five-career-fit',
                'proposal_quality' => [
                    'source' => 'gsc_cohort_artifact',
                    'slug_generated_copy' => false,
                ],
            ],
        ]);

        $article->forceFill([
            'working_revision_id' => (int) $draftRevision->id,
            'published_revision_id' => (int) $publishedRevision->id,
        ])->save();

        $writeTargetRevision = $usePublishedRevisionAsWriteTarget ? $publishedRevision : $draftRevision;

        return [
            $article->refresh(),
            $publishedRevision,
            $draftRevision,
            $this->writeEvidence($packageSha, 'article:'.$article->id.':en', (int) $writeTargetRevision->id),
        ];
    }

    /**
     * @return array{0: Article, 1: ArticleRevision, 2: ArticleRevision, 3: string}
     */
    private function legacyInlinePublishedFixture(): array
    {
        $foreignArticle = Article::query()->create([
            'org_id' => 0,
            'slug' => 'foreign-article',
            'locale' => 'en',
            'title' => 'Foreign Article',
            'excerpt' => 'Foreign excerpt.',
            'content_md' => 'Foreign markdown.',
            'content_html' => '<p>Foreign HTML.</p>',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subDays(2),
        ]);
        $foreignRevision = $this->articleRevision($foreignArticle, 1, [
            'seo_agent' => [
                'task' => 'historical_foreign_publish',
            ],
        ]);

        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => 'legacy-inline-published',
            'locale' => 'en',
            'title' => 'Legacy Inline Published',
            'excerpt' => 'Existing legacy excerpt.',
            'content_md' => 'Existing legacy article markdown.',
            'content_html' => '',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'working_revision_id' => (int) $foreignRevision->id,
            'published_revision_id' => (int) $foreignRevision->id,
            'published_at' => now()->subDay(),
        ]);

        $packageSha = hash('sha256', 'package:legacy-inline:'.$article->id);
        $draftRevision = $this->articleRevision($article, 1, [
            'seo_agent' => [
                'task' => 'SEO-AGENT-CONTROLLED-CMS-DRAFT-WRITER-01',
                'package_sha256' => $packageSha,
                'subject_ref' => 'article:'.$article->id.':en',
                'target_fields' => ['seo_title', 'seo_description', 'faq_items'],
                'publish_allowed' => false,
                'search_submit_allowed' => false,
                'indexing_request_allowed' => false,
            ],
            'proposal' => [
                'safe_path' => '/en/articles/legacy-inline-published',
            ],
        ]);

        return [
            $article->refresh(),
            $foreignRevision,
            $draftRevision,
            $this->writeEvidence($packageSha, 'article:'.$article->id.':en', (int) $draftRevision->id),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function articleRevision(Article $article, int $revisionNo, array $payload): ArticleRevision
    {
        return ArticleRevision::query()->create([
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'revision_no' => $revisionNo,
            'editor_admin_user_id' => null,
            'title' => (string) $article->title,
            'excerpt' => (string) $article->excerpt,
            'content_md' => (string) $article->content_md,
            'content_html' => (string) $article->content_html,
            'change_note' => 'SEO Agent preview runtime QA fixture',
            'payload_json' => $payload,
            'created_at' => now(),
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

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-agent-article-draft-preview-runtime-qa-'.Str::uuid()->toString());
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
    private function articleState(Article $article): array
    {
        return [
            'working_revision_id' => $article->working_revision_id ? (int) $article->working_revision_id : null,
            'published_revision_id' => $article->published_revision_id ? (int) $article->published_revision_id : null,
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
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
