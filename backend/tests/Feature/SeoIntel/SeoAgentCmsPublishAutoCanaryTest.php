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

final class SeoAgentCmsPublishAutoCanaryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dry_run_plans_low_risk_content_page_canaries_without_publishing(): void
    {
        $pages = [
            $this->createContentPage('auto-canary-one'),
            $this->createContentPage('auto-canary-two'),
            $this->createContentPage('auto-canary-three'),
        ];
        [$packagePath, $draftEvidencePath] = $this->createPackageAndDraftEvidence($pages);

        $exitCode = Artisan::call('seo-agent:cms-publish-auto-canary', [
            '--package' => $packagePath,
            '--draft-write-evidence' => $draftEvidencePath,
            '--limit' => 3,
            '--artifact-dir' => storage_path('framework/testing/seo-agent-publish-auto'),
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertSame(3, $summary['selected_count'] ?? null);
        $this->assertSame(3, $summary['published_or_planned_count'] ?? null);
        $this->assertSame(0, $summary['rows_skipped_existing'] ?? null);
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.search_channel_enqueue', true));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.indexing_request', true));

        foreach ($pages as $page) {
            $fresh = $page->refresh();
            $this->assertSame(88, (int) $fresh->published_revision_id);
            $this->assertSame('not_reviewed', (string) $fresh->claim_gate_status);
        }

        $artifactPath = (string) data_get($summary, 'artifact.path');
        $this->assertFileExists($artifactPath);
        $artifact = $this->readJson($artifactPath);
        $this->assertSame('seo-agent-cms-publish-auto-canary.v1', $artifact['schema_version'] ?? null);
        $this->assertSame(3, data_get($artifact, 'publish_summary.selected_count'));
        $this->assertSame(false, data_get($artifact, 'negative_guarantees.search_channel_submit'));
    }

    #[Test]
    public function execute_auto_publishes_at_most_three_content_page_canaries(): void
    {
        $pages = [
            $this->createContentPage('auto-canary-one'),
            $this->createContentPage('auto-canary-two'),
            $this->createContentPage('auto-canary-three'),
        ];
        [$packagePath, $draftEvidencePath] = $this->createPackageAndDraftEvidence($pages);

        $exitCode = Artisan::call('seo-agent:cms-publish-auto-canary', [
            '--package' => $packagePath,
            '--draft-write-evidence' => $draftEvidencePath,
            '--limit' => 3,
            '--artifact-dir' => storage_path('framework/testing/seo-agent-publish-auto'),
            '--auto-approve-low-risk' => true,
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertSame(3, $summary['selected_count'] ?? null);
        $this->assertSame(3, $summary['published_or_planned_count'] ?? null);
        $this->assertSame(0, $summary['rows_skipped_existing'] ?? null);

        foreach ($pages as $page) {
            $fresh = $page->refresh();
            $this->assertSame('passed', (string) $fresh->claim_gate_status);
            $this->assertSame('approved', (string) $fresh->review_state);
            $this->assertNotSame(88, (int) $fresh->published_revision_id);
            $revision = CmsTranslationRevision::query()->findOrFail((int) $fresh->published_revision_id);
            $this->assertSame(CmsTranslationRevision::STATUS_PUBLISHED, (string) $revision->revision_status);
            $this->assertSame(hash_file('sha256', $packagePath), data_get($revision->payload_json, 'seo_agent_publish_canary.package_sha256'));
        }

        $exitCode = Artisan::call('seo-agent:cms-publish-auto-canary', [
            '--package' => $packagePath,
            '--draft-write-evidence' => $draftEvidencePath,
            '--limit' => 3,
            '--artifact-dir' => storage_path('framework/testing/seo-agent-publish-auto'),
            '--auto-approve-low-risk' => true,
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame(0, $summary['published_or_planned_count'] ?? null);
        $this->assertSame(3, $summary['rows_skipped_existing'] ?? null);

        $encoded = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        foreach ($this->forbiddenStrings() as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded, $forbidden);
        }
    }

    #[Test]
    public function execute_fails_closed_without_auto_approval_or_with_limit_above_three(): void
    {
        $pages = [$this->createContentPage('auto-canary-one')];
        [$packagePath, $draftEvidencePath] = $this->createPackageAndDraftEvidence($pages);

        $exitCode = Artisan::call('seo-agent:cms-publish-auto-canary', [
            '--package' => $packagePath,
            '--draft-write-evidence' => $draftEvidencePath,
            '--limit' => 4,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('limit_out_of_bounds', $summary['issues'] ?? []);

        $exitCode = Artisan::call('seo-agent:cms-publish-auto-canary', [
            '--package' => $packagePath,
            '--draft-write-evidence' => $draftEvidencePath,
            '--limit' => 1,
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('auto_approve_low_risk_required_for_execute', $summary['issues'] ?? []);
        $this->assertSame(88, (int) $pages[0]->refresh()->published_revision_id);
    }

    #[Test]
    public function article_candidates_are_not_selected_for_auto_publish(): void
    {
        $page = $this->createContentPage('auto-canary-one');
        [$packagePath, $draftEvidencePath] = $this->createPackageAndDraftEvidence([$page], [[
            'target_model' => 'article',
            'subject_type' => 'article',
            'subject_ref' => 'article:123:zh-CN',
        ]], runDraftWriter: false);

        $exitCode = Artisan::call('seo-agent:cms-publish-auto-canary', [
            '--package' => $packagePath,
            '--draft-write-evidence' => $draftEvidencePath,
            '--limit' => 3,
            '--artifact-dir' => storage_path('framework/testing/seo-agent-publish-auto'),
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame(0, $summary['selected_count'] ?? null);
        $this->assertSame(0, $summary['published_or_planned_count'] ?? null);
        $this->assertSame(88, (int) $page->refresh()->published_revision_id);
    }

    #[Test]
    public function generated_contract_documents_publish_auto_canary_boundaries(): void
    {
        $artifact = $this->readJson(base_path('docs/seo/generated/seo-agent-cms-publish-auto-canary.v1.json'));

        $this->assertSame('seo-agent-cms-publish-auto-canary.v1', $artifact['version'] ?? null);
        $this->assertSame('php artisan seo-agent:cms-publish-auto-canary', $artifact['command'] ?? null);
        $this->assertSame(3, $artifact['max_rows_per_execution'] ?? null);
        $this->assertContains('content_page', $artifact['supported_targets_v1'] ?? []);
        $this->assertSame(false, data_get($artifact, 'post_publish_boundaries.search_channel_enqueue'));
        $this->assertSame(false, data_get($artifact, 'post_publish_boundaries.indexing_request'));
    }

    private function createContentPage(string $slug): ContentPage
    {
        return ContentPage::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'path' => '/zh/'.$slug,
            'kind' => ContentPage::KIND_HELP,
            'page_type' => 'support_static',
            'title' => Str::headline($slug),
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
     * @param  list<ContentPage>  $pages
     * @param  list<array<string, mixed>>  $overrides
     * @return array{0: string, 1: string}
     */
    private function createPackageAndDraftEvidence(array $pages, array $overrides = [], bool $runDraftWriter = true): array
    {
        $proposals = [];
        foreach ($pages as $index => $page) {
            $proposals[] = array_merge([
                'source_id' => hash('sha256', 'content-page'.(string) $page->id),
                'source_family' => 'cms_tdk_gap',
                'target_model' => 'content_page',
                'subject_type' => 'content_page',
                'subject_ref' => 'content_page:'.(int) $page->id.':zh-CN',
                'safe_path' => '/zh/'.(string) $page->slug,
                'severity' => 'p2',
                'target_fields' => ['seo_title', 'seo_description', 'canonical_url_or_path'],
                'proposed_seo_title' => Str::headline((string) $page->slug).' | FermatMind',
                'proposed_seo_description' => 'Review candidate with FermatMind guidance.',
                'proposed_canonical_path' => '/zh/'.(string) $page->slug,
                'claim_gate_required' => true,
                'human_approval_required' => true,
                'execution_permission' => false,
            ], $overrides[$index] ?? []);
        }

        $packagePath = $this->writeJson('seo-agent-cms-draft-package-', [
            'schema_version' => 'seo-agent-cms-draft-package-dry-run.v1',
            'dry_run' => true,
            'cms_write_allowed' => false,
            'execution_permission' => false,
            'proposal_count' => count($proposals),
            'proposal_items' => $proposals,
            'claim_gate_required' => true,
            'human_approval_required' => true,
        ]);

        $packageSha = hash_file('sha256', $packagePath) ?: '';
        if ($runDraftWriter) {
            $exitCode = Artisan::call('seo-agent:cms-draft-write', [
                '--package' => $packagePath,
                '--limit' => count($proposals),
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
                'package_sha256' => $packageSha,
                'writes_attempted' => true,
                'writes_committed' => true,
                'rows_created' => count($proposals),
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
                'package_sha256' => $packageSha,
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
    private function readJson(string $path): array
    {
        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($payload);

        return $payload;
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
}
