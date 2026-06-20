<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoAgentControlledCmsDraftWriterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dry_run_outputs_required_confirmation_without_writing(): void
    {
        [$article, $page] = $this->createTargets();
        $packagePath = $this->writePackage([
            $this->proposal('article', (int) $article->id),
            $this->proposal('content_page', (int) $page->id),
        ]);
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-agent:cms-draft-write', [
            '--package' => $packagePath,
            '--limit' => 2,
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('planned', $summary['status'] ?? null);
        $this->assertTrue((bool) ($summary['would_write'] ?? false));
        $this->assertSame(2, $summary['planned_count'] ?? null);
        $this->assertStringContainsString(hash_file('sha256', $packagePath), (string) ($summary['required_confirmation_phrase'] ?? ''));
        $this->assertFalse((bool) ($summary['writes_attempted'] ?? true));
        $this->assertSame($countsBefore, $this->rowCounts());
    }

    #[Test]
    public function execute_fails_closed_without_exact_approval_or_with_bad_limit(): void
    {
        [$article] = $this->createTargets();
        $packagePath = $this->writePackage([$this->proposal('article', (int) $article->id)]);

        $exitCode = Artisan::call('seo-agent:cms-draft-write', [
            '--package' => $packagePath,
            '--limit' => 11,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('limit_out_of_bounds', $summary['issues'] ?? []);

        $exitCode = Artisan::call('seo-agent:cms-draft-write', [
            '--package' => $packagePath,
            '--limit' => 1,
            '--confirm-package-sha256' => str_repeat('0', 64),
            '--confirm-write' => 'wrong',
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertContains('package_sha256_confirmation_mismatch', $summary['issues'] ?? []);
        $this->assertSame(0, ArticleRevision::query()->count());
    }

    #[Test]
    public function execute_writes_bounded_article_and_content_page_draft_revisions_then_skips_duplicates(): void
    {
        [$article, $page] = $this->createTargets();
        $packagePath = $this->writePackage([
            $this->proposal('article', (int) $article->id),
            $this->proposal('content_page', (int) $page->id),
        ]);
        $sha = hash_file('sha256', $packagePath) ?: '';
        $phrase = $this->approvalPhrase(2, $sha);

        $exitCode = Artisan::call('seo-agent:cms-draft-write', [
            '--package' => $packagePath,
            '--limit' => 2,
            '--confirm-package-sha256' => $sha,
            '--confirm-write' => $phrase,
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertTrue((bool) ($summary['writes_attempted'] ?? false));
        $this->assertTrue((bool) ($summary['writes_committed'] ?? false));
        $this->assertSame(2, $summary['rows_created'] ?? null);
        $this->assertSame(0, $summary['rows_skipped_existing'] ?? null);
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.cms_publish', true));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.search_channel_submit', true));

        $articleRevision = ArticleRevision::query()->firstOrFail();
        $this->assertSame('SEO Agent controlled draft proposal', $articleRevision->change_note);
        $this->assertSame($sha, data_get($articleRevision->payload_json, 'seo_agent.package_sha256'));
        $this->assertSame('Article Candidate | FermatMind', data_get($articleRevision->payload_json, 'proposal.proposed_seo_title'));

        $pageRevision = CmsTranslationRevision::query()->firstOrFail();
        $this->assertSame(CmsTranslationRevision::STATUS_DRAFT, $pageRevision->revision_status);
        $this->assertNull($pageRevision->published_at);
        $this->assertSame($sha, data_get($pageRevision->payload_json, 'seo_agent.package_sha256'));

        $freshArticle = Article::query()->withoutGlobalScopes()->findOrFail((int) $article->id);
        $freshPage = ContentPage::query()->withoutGlobalScopes()->findOrFail((int) $page->id);
        $this->assertSame($article->published_revision_id, $freshArticle->published_revision_id);
        $this->assertSame($page->published_revision_id, $freshPage->published_revision_id);
        $this->assertSame(ContentPage::STATUS_PUBLISHED, $freshPage->status);

        $exitCode = Artisan::call('seo-agent:cms-draft-write', [
            '--package' => $packagePath,
            '--limit' => 2,
            '--confirm-package-sha256' => $sha,
            '--confirm-write' => $phrase,
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, $summary['rows_created'] ?? null);
        $this->assertSame(2, $summary['rows_skipped_existing'] ?? null);
        $this->assertSame(1, ArticleRevision::query()->count());
        $this->assertSame(1, CmsTranslationRevision::query()->count());

        $encoded = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        foreach ($this->forbiddenStrings() as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded, $forbidden);
        }
    }

    #[Test]
    public function generated_contract_documents_writer_boundaries(): void
    {
        $artifact = $this->readJson(base_path('docs/seo/generated/seo-agent-controlled-cms-draft-write.v1.json'));

        $this->assertSame('seo-agent-controlled-cms-draft-write.v1', $artifact['version'] ?? null);
        $this->assertSame('php artisan seo-agent:cms-draft-write', $artifact['command'] ?? null);
        $this->assertSame(10, $artifact['max_rows_per_execution'] ?? null);
        $this->assertFalse((bool) ($artifact['publishes_content'] ?? true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.indexing_request', true));
    }

    /**
     * @return array{0: Article, 1: ContentPage}
     */
    private function createTargets(): array
    {
        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => 'article-candidate',
            'locale' => 'zh-CN',
            'title' => 'Article Candidate',
            'excerpt' => 'Existing excerpt.',
            'content_md' => 'Existing article markdown.',
            'content_html' => '<p>Existing article HTML.</p>',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_revision_id' => 99,
            'published_at' => now()->subDay(),
        ]);

        $page = ContentPage::query()->create([
            'org_id' => 0,
            'slug' => 'content-page-candidate',
            'path' => '/zh/content-page-candidate',
            'kind' => ContentPage::KIND_HELP,
            'page_type' => 'support_static',
            'title' => 'Content Page Candidate',
            'summary' => 'Existing summary.',
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
            'review_state' => 'draft',
            'published_revision_id' => 88,
        ]);

        return [$article, $page];
    }

    /**
     * @param  list<array<string, mixed>>  $proposals
     */
    private function writePackage(array $proposals): string
    {
        $payload = [
            'schema_version' => 'seo-agent-cms-draft-package-dry-run.v1',
            'dry_run' => true,
            'cms_write_allowed' => false,
            'execution_permission' => false,
            'proposal_count' => count($proposals),
            'proposal_items' => $proposals,
            'claim_gate_required' => true,
            'human_approval_required' => true,
        ];
        $path = storage_path('framework/testing/seo-agent-cms-draft-package-'.Str::uuid()->toString().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function proposal(string $targetModel, int $id): array
    {
        $safeLabel = $targetModel === 'article' ? 'article-candidate' : 'content-page-candidate';

        return [
            'source_id' => hash('sha256', $targetModel.(string) $id),
            'target_model' => $targetModel,
            'subject_type' => $targetModel,
            'subject_ref' => $targetModel.':'.$id.':zh-CN',
            'safe_path' => '/zh/'.$safeLabel,
            'target_fields' => ['seo_title', 'seo_description'],
            'proposed_seo_title' => ($targetModel === 'article' ? 'Article Candidate' : 'Content Page Candidate').' | FermatMind',
            'proposed_seo_description' => 'Review candidate with FermatMind guidance.',
            'claim_gate_required' => true,
            'human_approval_required' => true,
        ];
    }

    private function approvalPhrase(int $limit, string $sha): string
    {
        return 'I explicitly approve SEO-AGENT-CONTROLLED-CMS-DRAFT-WRITER-01 to write at most '.$limit.' CMS draft rows from package sha256 '.$sha.'; no publish, no queue, no search, no indexing, no scheduler.';
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return [
            'article_revisions' => ArticleRevision::query()->count(),
            'cms_translation_revisions' => CmsTranslationRevision::query()->count(),
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
