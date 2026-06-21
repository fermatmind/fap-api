<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoAgentCmsPublishCanaryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dry_run_plans_one_content_page_canary_without_writing(): void
    {
        $page = $this->createContentPage();
        [$packagePath, $draftEvidencePath] = $this->createPackageAndDraftEvidence($page);
        $before = $this->pageState($page);

        $exitCode = Artisan::call('seo-agent:cms-publish-canary', [
            '--package' => $packagePath,
            '--draft-write-evidence' => $draftEvidencePath,
            '--limit' => 1,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('planned', $summary['status'] ?? null);
        $this->assertTrue((bool) ($summary['would_publish'] ?? false));
        $this->assertFalse((bool) ($summary['writes_attempted'] ?? true));
        $this->assertFalse((bool) ($summary['writes_committed'] ?? true));
        $this->assertSame($before, $this->pageState($page->refresh()));
        $this->assertSame(CmsTranslationRevision::STATUS_DRAFT, CmsTranslationRevision::query()->firstOrFail()->revision_status);
    }

    #[Test]
    public function execute_low_risk_canary_publishes_one_content_page_draft_only(): void
    {
        $page = $this->createContentPage();
        [$packagePath, $draftEvidencePath] = $this->createPackageAndDraftEvidence($page);
        $packageSha = hash_file('sha256', $packagePath) ?: '';

        $exitCode = Artisan::call('seo-agent:cms-publish-canary', [
            '--package' => $packagePath,
            '--draft-write-evidence' => $draftEvidencePath,
            '--limit' => 1,
            '--confirm-package-sha256' => $packageSha,
            '--auto-approve-low-risk' => true,
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertTrue((bool) ($summary['writes_attempted'] ?? false));
        $this->assertTrue((bool) ($summary['writes_committed'] ?? false));
        $this->assertSame(1, $summary['published_count'] ?? null);
        $this->assertSame(0, $summary['rows_skipped_existing'] ?? null);
        $this->assertTrue((bool) data_get($summary, 'boundaries.cms_publish'));
        $this->assertFalse((bool) data_get($summary, 'boundaries.search_channel_enqueue', true));
        $this->assertFalse((bool) data_get($summary, 'boundaries.search_channel_submit', true));
        $this->assertFalse((bool) data_get($summary, 'boundaries.indexing_request', true));
        $this->assertTrue((bool) data_get($summary, 'rollback_evidence.available'));

        $fresh = $page->refresh();
        $revision = CmsTranslationRevision::query()->firstOrFail();
        $this->assertSame('Content Page Candidate | FermatMind', (string) $fresh->seo_title);
        $this->assertSame('Review candidate with FermatMind guidance.', (string) $fresh->seo_description);
        $this->assertSame('/zh/content-page-candidate', (string) $fresh->canonical_path);
        $this->assertSame('passed', (string) $fresh->claim_gate_status);
        $this->assertSame([], $fresh->forbidden_claims ?? []);
        $this->assertSame((int) $revision->id, (int) $fresh->published_revision_id);
        $this->assertSame(CmsTranslationRevision::STATUS_PUBLISHED, (string) $revision->revision_status);
        $this->assertSame($packageSha, data_get($revision->payload_json, 'seo_agent.package_sha256'));
        $this->assertSame('low_risk_auto_approved', data_get($revision->payload_json, 'seo_agent_publish_canary.approval_mode'));

        $exitCode = Artisan::call('seo-agent:cms-publish-canary', [
            '--package' => $packagePath,
            '--draft-write-evidence' => $draftEvidencePath,
            '--limit' => 1,
            '--confirm-package-sha256' => $packageSha,
            '--auto-approve-low-risk' => true,
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertFalse((bool) ($summary['writes_committed'] ?? true));
        $this->assertSame(1, $summary['rows_skipped_existing'] ?? null);

        $encoded = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        foreach ($this->forbiddenStrings() as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded, $forbidden);
        }
    }

    #[Test]
    public function execute_fails_closed_for_article_target_or_bad_package_sha(): void
    {
        $page = $this->createContentPage();
        [$packagePath, $draftEvidencePath] = $this->createPackageAndDraftEvidence($page);

        $exitCode = Artisan::call('seo-agent:cms-publish-canary', [
            '--package' => $packagePath,
            '--draft-write-evidence' => $draftEvidencePath,
            '--limit' => 1,
            '--confirm-package-sha256' => str_repeat('0', 64),
            '--auto-approve-low-risk' => true,
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('package_sha256_confirmation_mismatch', $summary['issues'] ?? []);

        [$articlePackagePath, $articleEvidencePath] = $this->createPackageAndDraftEvidence($page, [
            'target_model' => 'article',
            'subject_type' => 'article',
            'subject_ref' => 'article:123:zh-CN',
        ]);

        $exitCode = Artisan::call('seo-agent:cms-publish-canary', [
            '--package' => $articlePackagePath,
            '--draft-write-evidence' => $articleEvidencePath,
            '--limit' => 1,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('article_publish_canary_not_supported_in_v1', $summary['issues'] ?? []);
    }

    #[Test]
    public function generated_contract_documents_publish_canary_boundaries(): void
    {
        $artifact = $this->readJson(base_path('docs/seo/generated/seo-agent-cms-publish-canary.v1.json'));

        $this->assertSame('seo-agent-cms-publish-canary.v1', $artifact['version'] ?? null);
        $this->assertSame('php artisan seo-agent:cms-publish-canary', $artifact['command'] ?? null);
        $this->assertSame(1, $artifact['max_rows_per_execution'] ?? null);
        $this->assertContains('content_page', $artifact['supported_targets_v1'] ?? []);
        $this->assertSame(false, data_get($artifact, 'post_publish_boundaries.search_channel_enqueue'));
        $this->assertSame(false, data_get($artifact, 'post_publish_boundaries.indexing_request'));
    }

    private function createContentPage(): ContentPage
    {
        return ContentPage::query()->create([
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
            'published_at' => now()->subDay(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $proposalOverrides
     * @return array{0: string, 1: string}
     */
    private function createPackageAndDraftEvidence(ContentPage $page, array $proposalOverrides = []): array
    {
        $proposal = array_merge([
            'source_id' => hash('sha256', 'content-page'.(string) $page->id),
            'source_family' => 'cms_tdk_gap',
            'target_model' => 'content_page',
            'subject_type' => 'content_page',
            'subject_ref' => 'content_page:'.(int) $page->id.':zh-CN',
            'safe_path' => '/zh/content-page-candidate',
            'severity' => 'p2',
            'target_fields' => ['seo_title', 'seo_description', 'canonical_url_or_path'],
            'proposed_seo_title' => 'Content Page Candidate | FermatMind',
            'proposed_seo_description' => 'Review candidate with FermatMind guidance.',
            'proposed_canonical_path' => '/zh/content-page-candidate',
            'claim_gate_required' => true,
            'human_approval_required' => true,
            'execution_permission' => false,
        ], $proposalOverrides);

        $packagePath = $this->writeJson('seo-agent-cms-draft-package-', [
            'schema_version' => 'seo-agent-cms-draft-package-dry-run.v1',
            'dry_run' => true,
            'cms_write_allowed' => false,
            'execution_permission' => false,
            'proposal_count' => 1,
            'proposal_items' => [$proposal],
            'claim_gate_required' => true,
            'human_approval_required' => true,
        ]);

        if (($proposal['target_model'] ?? '') === 'content_page') {
            $exitCode = Artisan::call('seo-agent:cms-draft-write', [
                '--package' => $packagePath,
                '--limit' => 1,
                '--auto-approve-low-risk' => true,
                '--execute' => true,
                '--json' => true,
            ]);
            $this->assertSame(0, $exitCode, Artisan::output());
            $draftEvidence = [
                'schema_version' => 'seo-agent-controlled-cms-draft-write.v1',
                'ok' => true,
                'status' => 'success',
                'execute' => true,
                'approval_mode' => 'low_risk_auto_approved',
                'package_sha256' => hash_file('sha256', $packagePath) ?: '',
                'writes_attempted' => true,
                'writes_committed' => true,
                'rows_created' => 1,
                'negative_guarantees' => [
                    'cms_publish' => false,
                    'search_channel_submit' => false,
                    'indexing_request' => false,
                ],
            ];
        } else {
            $draftEvidence = [
                'schema_version' => 'seo-agent-controlled-cms-draft-write.v1',
                'ok' => true,
                'status' => 'success',
                'execute' => true,
                'approval_mode' => 'low_risk_auto_approved',
                'package_sha256' => hash_file('sha256', $packagePath) ?: '',
                'writes_attempted' => true,
                'writes_committed' => true,
                'rows_created' => 1,
                'negative_guarantees' => [
                    'cms_publish' => false,
                    'search_channel_submit' => false,
                    'indexing_request' => false,
                ],
            ];
        }

        $draftEvidencePath = $this->writeJson('seo-agent-controlled-cms-draft-write-', $draftEvidence);

        return [$packagePath, $draftEvidencePath];
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
     * @return array<string, mixed>
     */
    private function pageState(ContentPage $page): array
    {
        return [
            'seo_title' => $page->seo_title,
            'seo_description' => $page->seo_description,
            'canonical_path' => $page->canonical_path,
            'published_revision_id' => $page->published_revision_id,
            'claim_gate_status' => $page->claim_gate_status,
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
