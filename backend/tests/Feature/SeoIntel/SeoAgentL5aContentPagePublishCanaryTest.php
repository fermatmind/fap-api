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

final class SeoAgentL5aContentPagePublishCanaryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dry_run_plans_one_verified_content_page_publish_without_writing(): void
    {
        $page = $this->createContentPage();
        [$draftWriteEvidencePath] = $this->createDraftWriteEvidence($page);
        $artifactDir = storage_path('framework/testing/l5a-contentpage-publish-'.Str::uuid()->toString());
        $before = $this->pageState($page);

        $exitCode = Artisan::call('seo-agent:l5a-contentpage-publish-canary', [
            '--draft-write-evidence' => $draftWriteEvidencePath,
            '--limit' => 1,
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('planned', $summary['status'] ?? null);
        $this->assertTrue((bool) ($summary['dry_run'] ?? false));
        $this->assertSame(0, $summary['published_count'] ?? null);
        $this->assertSame($before, $this->pageState($page->refresh()));
        $this->assertFalse((bool) data_get($summary, 'boundaries.cms_publish', true));
        $this->assertFalse((bool) data_get($summary, 'boundaries.search_channel_enqueue', true));
        $this->assertFalse((bool) data_get($summary, 'boundaries.indexnow_live_submit', true));
        $this->assertTrue((bool) ($summary['url_truth_required'] ?? false));

        $artifactPath = (string) data_get($summary, 'artifact.path');
        $this->assertFileExists($artifactPath);
        $artifact = $this->readJson($artifactPath);
        $this->assertSame('seo-agent-l5a-contentpage-publish-canary.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('SEO-AGENT-L5A-CONTENTPAGE-PUBLISH-CANARY1-01', $artifact['task'] ?? null);
        $this->assertSame('content_page:'.$page->id.':en', data_get($artifact, 'selected_candidate.subject_ref'));
        $this->assertSame('/about', $artifact['published_safe_path'] ?? null);
        $this->assertFalse((bool) data_get($artifact, 'boundaries.cms_publish', true));
        $this->assertNoForbiddenStrings($artifact);
    }

    #[Test]
    public function execute_publishes_one_verified_content_page_draft_and_writes_rollback_evidence(): void
    {
        $page = $this->createContentPage();
        [$draftWriteEvidencePath, $packagePath] = $this->createDraftWriteEvidence($page);
        $draftWriteSha = hash_file('sha256', $draftWriteEvidencePath) ?: '';
        $packageSha = hash_file('sha256', $packagePath) ?: '';
        $artifactDir = storage_path('framework/testing/l5a-contentpage-publish-'.Str::uuid()->toString());

        $exitCode = Artisan::call('seo-agent:l5a-contentpage-publish-canary', [
            '--draft-write-evidence' => $draftWriteEvidencePath,
            '--limit' => 1,
            '--artifact-dir' => $artifactDir,
            '--confirm-draft-write-sha256' => $draftWriteSha,
            '--auto-approve-low-risk' => true,
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertFalse((bool) ($summary['dry_run'] ?? true));
        $this->assertSame(1, $summary['published_count'] ?? null);
        $this->assertSame(0, $summary['rows_skipped_existing'] ?? null);
        $this->assertTrue((bool) data_get($summary, 'rollback_evidence.available'));
        $this->assertTrue((bool) data_get($summary, 'boundaries.cms_publish'));
        $this->assertFalse((bool) data_get($summary, 'boundaries.search_channel_submit', true));
        $this->assertFalse((bool) data_get($summary, 'boundaries.indexnow_live_submit', true));
        $this->assertFalse((bool) data_get($summary, 'boundaries.google_indexing_api_call', true));

        $fresh = $page->refresh();
        $revision = CmsTranslationRevision::query()->firstOrFail();
        $this->assertSame('About FermatMind | FermatMind', (string) $fresh->seo_title);
        $this->assertSame('Learn about FermatMind and its product boundaries.', (string) $fresh->seo_description);
        $this->assertSame('/about', (string) $fresh->canonical_path);
        $this->assertSame('passed', (string) $fresh->claim_gate_status);
        $this->assertSame((int) $revision->id, (int) $fresh->published_revision_id);
        $this->assertSame(CmsTranslationRevision::STATUS_PUBLISHED, (string) $revision->revision_status);
        $this->assertSame($packageSha, data_get($revision->payload_json, 'seo_agent.package_sha256'));
        $this->assertSame('low_risk_auto_approved', data_get($revision->payload_json, 'seo_agent_publish_canary.approval_mode'));

        $artifactPath = (string) data_get($summary, 'artifact.path');
        $this->assertFileExists($artifactPath);
        $artifact = $this->readJson($artifactPath);
        $this->assertSame('success', $artifact['status'] ?? null);
        $this->assertSame(1, $artifact['published_count'] ?? null);
        $this->assertTrue((bool) data_get($artifact, 'rollback_evidence.available'));
        $this->assertSame('content_page:'.$page->id.':en', data_get($artifact, 'selected_candidate.subject_ref'));
        $this->assertNoForbiddenStrings($artifact);

        $exitCode = Artisan::call('seo-agent:l5a-contentpage-publish-canary', [
            '--draft-write-evidence' => $draftWriteEvidencePath,
            '--limit' => 1,
            '--artifact-dir' => $artifactDir,
            '--confirm-draft-write-sha256' => $draftWriteSha,
            '--auto-approve-low-risk' => true,
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertSame(0, $summary['published_count'] ?? null);
        $this->assertSame(1, $summary['rows_skipped_existing'] ?? null);
    }

    #[Test]
    public function execute_fails_closed_without_matching_draft_write_sha_or_rollback_pointer(): void
    {
        $page = $this->createContentPage();
        [$draftWriteEvidencePath, , $draftWriteEvidence] = $this->createDraftWriteEvidence($page);

        $exitCode = Artisan::call('seo-agent:l5a-contentpage-publish-canary', [
            '--draft-write-evidence' => $draftWriteEvidencePath,
            '--limit' => 1,
            '--confirm-draft-write-sha256' => str_repeat('0', 64),
            '--auto-approve-low-risk' => true,
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertContains('draft_write_sha256_confirmation_mismatch', $summary['issues'] ?? []);
        $this->assertNull($page->refresh()->working_revision_id);

        unset($draftWriteEvidence['rollback_pointer']['candidate_revision_id']);
        File::put($draftWriteEvidencePath, json_encode($draftWriteEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $exitCode = Artisan::call('seo-agent:l5a-contentpage-publish-canary', [
            '--draft-write-evidence' => $draftWriteEvidencePath,
            '--limit' => 1,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertContains('rollback_evidence_missing', $summary['issues'] ?? []);
        $this->assertNull($page->refresh()->working_revision_id);
    }

    private function createContentPage(): ContentPage
    {
        return ContentPage::query()->create([
            'org_id' => 0,
            'slug' => 'about',
            'path' => '/about',
            'kind' => ContentPage::KIND_COMPANY,
            'page_type' => 'about',
            'title' => 'About FermatMind',
            'summary' => 'Existing summary.',
            'seo_title' => 'About FermatMind | FermatMind',
            'seo_description' => 'Learn about FermatMind and its product boundaries.',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => 'en',
            'translation_group_id' => 'about',
            'source_locale' => 'en',
            'translation_status' => ContentPage::TRANSLATION_STATUS_PUBLISHED,
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
            'working_revision_id' => null,
            'published_at' => now()->subDay(),
        ]);
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function createDraftWriteEvidence(ContentPage $page): array
    {
        $candidate = [
            'source_id' => hash('sha256', 'content_page'.$page->id.'canonical'),
            'source_family' => 'cms_tdk_gap',
            'subject_type' => 'content_page',
            'subject_ref' => 'content_page:'.$page->id.':en',
            'target_model' => 'content_page',
            'safe_path' => '/about',
            'severity' => 'p1',
            'gap_codes' => ['missing_canonical'],
            'target_fields' => ['canonical_url_or_path'],
        ];
        $proposal = [
            ...$candidate,
            'proposed_seo_title' => null,
            'proposed_seo_description' => null,
            'proposed_faq_items' => [],
            'proposed_canonical_path' => '/about',
            'proposed_indexability' => null,
            'draft_instructions' => [
                'prepare_field_level_proposal_only',
                'do_not_generate_final_body_copy',
                'run_claim_gate_before_any_cms_write',
            ],
            'claim_gate_required' => true,
            'human_approval_required' => true,
            'execution_permission' => false,
        ];
        $package = [
            'schema_version' => 'seo-agent-cms-draft-package-dry-run.v1',
            'dry_run' => true,
            'cms_write_allowed' => false,
            'execution_permission' => false,
            'proposal_count' => 1,
            'proposal_items' => [$proposal],
            'claim_gate_required' => true,
            'human_approval_required' => true,
            'l5a_canary' => [
                'task' => 'SEO-AGENT-L5A-CMS-DRAFT-WRITE-CANARY1-01',
                'selected_subject_ref' => $candidate['subject_ref'],
                'selected_safe_path' => $candidate['safe_path'],
                'source_candidate_review_schema' => 'seo-agent-l5a-candidate-review.v1',
            ],
        ];
        $dir = storage_path('framework/testing/l5a-contentpage-publish-'.Str::uuid()->toString());
        File::ensureDirectoryExists($dir);
        $packagePath = $dir.'/filtered-package.json';
        File::put($packagePath, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $packageSha = hash_file('sha256', $packagePath) ?: '';

        $exitCode = Artisan::call('seo-agent:cms-draft-write', [
            '--package' => $packagePath,
            '--limit' => 1,
            '--auto-approve-low-risk' => true,
            '--execute' => true,
            '--json' => true,
        ]);
        $this->assertSame(0, $exitCode, Artisan::output());

        $revision = CmsTranslationRevision::query()->firstOrFail();
        $draftWriteEvidence = [
            'schema_version' => 'seo-agent-l5a-cms-draft-write-canary.v1',
            'task' => 'SEO-AGENT-L5A-CMS-DRAFT-WRITE-CANARY1-01',
            'status' => 'success',
            'dry_run' => false,
            'execute' => true,
            'limit' => 1,
            'filtered_package' => [
                'path' => $packagePath,
                'size' => filesize($packagePath) ?: 0,
                'sha256' => $packageSha,
                'schema_version' => 'seo-agent-cms-draft-package-dry-run.v1',
            ],
            'selected_candidate' => $candidate,
            'planned_count' => 1,
            'rows_created' => 1,
            'rows_skipped_existing' => 0,
            'draft_refs' => [[
                'status' => 'created',
                'target_model' => 'content_page',
                'subject_ref' => $candidate['subject_ref'],
                'revision_id' => (int) $revision->id,
            ]],
            'idempotency_key' => $packageSha,
            'rollback_pointer' => [
                'available' => true,
                'candidate_revision_id' => (int) $revision->id,
                'previous_working_revision_id' => null,
                'previous_published_revision_id' => (int) $page->published_revision_id,
                'latest_draft_revision_id' => (int) $revision->id,
            ],
            'negative_guarantees' => [
                'cms_publish' => false,
                'search_channel_enqueue' => false,
                'search_channel_submit' => false,
                'indexnow_live_submit' => false,
                'google_indexing_api_call' => false,
                'scheduler_activation' => false,
            ],
        ];
        $draftWriteEvidencePath = $dir.'/draft-write-evidence.json';
        File::put($draftWriteEvidencePath, json_encode($draftWriteEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return [$draftWriteEvidencePath, $packagePath, $draftWriteEvidence];
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
            'working_revision_id' => $page->working_revision_id,
            'claim_gate_status' => $page->claim_gate_status,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertNoForbiddenStrings(array $payload): void
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        foreach (['raw_url', 'raw_query', 'credential_path', 'client_email', 'private_key', 'Bearer ', 'content_md', 'content_html', 'cms_draft_body'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded, $forbidden);
        }
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
