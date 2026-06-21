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

final class SeoAgentL5aCmsDraftWriteCanaryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dry_run_builds_filtered_package_and_never_writes_cms(): void
    {
        $page = $this->createContentPage();
        [$candidateReviewPath] = $this->writeCandidateReview($page);
        $artifactDir = storage_path('framework/testing/l5a-draft-write-'.Str::uuid()->toString());

        $exitCode = Artisan::call('seo-agent:l5a-cms-draft-write-canary', [
            '--candidate-review' => $candidateReviewPath,
            '--limit' => 1,
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('planned', $summary['status'] ?? null);
        $this->assertTrue((bool) ($summary['dry_run'] ?? false));
        $this->assertSame(1, $summary['planned_count'] ?? null);
        $this->assertSame(0, CmsTranslationRevision::query()->count());
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.cms_publish', true));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.search_channel_enqueue', true));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.indexnow_live_submit', true));

        $artifactPath = (string) data_get($summary, 'artifact.path');
        $this->assertFileExists($artifactPath);
        $artifact = $this->readJson($artifactPath);
        $this->assertSame('seo-agent-l5a-cms-draft-write-canary.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('content_page:'.$page->id.':en', data_get($artifact, 'selected_candidate.subject_ref'));
        $this->assertSame(1, $artifact['planned_count'] ?? null);
        $this->assertNoForbiddenStrings($artifact);
    }

    #[Test]
    public function dry_run_resolves_pathless_candidate_review_package_by_sha(): void
    {
        $page = $this->createContentPage();
        [$candidateReviewPath, $packagePath, $candidateReview] = $this->writeCandidateReview($page);
        $seoAgentDir = storage_path('app/seo-agent/l5a-pathless-package-'.Str::uuid()->toString());
        File::ensureDirectoryExists($seoAgentDir);
        $resolvedPackagePath = $seoAgentDir.'/source-package.json';
        File::copy($packagePath, $resolvedPackagePath);

        unset($candidateReview['input_artifacts']['cms_draft_package_dry_run']['path']);
        $candidateReview['input_artifacts']['cms_draft_package_dry_run']['sha256'] = hash_file('sha256', $resolvedPackagePath) ?: '';
        $candidateReview['input_artifacts']['cms_draft_package_dry_run']['size'] = filesize($resolvedPackagePath) ?: 0;
        File::put($candidateReviewPath, json_encode($candidateReview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $exitCode = Artisan::call('seo-agent:l5a-cms-draft-write-canary', [
            '--candidate-review' => $candidateReviewPath,
            '--limit' => 1,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('planned', $summary['status'] ?? null);
        $this->assertSame(1, $summary['planned_count'] ?? null);
        $this->assertSame(0, CmsTranslationRevision::query()->count());
        $this->assertNoForbiddenStrings($summary);
    }

    #[Test]
    public function execute_fails_closed_without_matching_candidate_review_sha(): void
    {
        $page = $this->createContentPage();
        [$candidateReviewPath] = $this->writeCandidateReview($page);

        $exitCode = Artisan::call('seo-agent:l5a-cms-draft-write-canary', [
            '--candidate-review' => $candidateReviewPath,
            '--limit' => 1,
            '--confirm-candidate-review-sha256' => str_repeat('0', 64),
            '--auto-approve-low-risk' => true,
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertContains('candidate_review_sha256_confirmation_mismatch', $summary['issues'] ?? []);
        $this->assertSame(0, CmsTranslationRevision::query()->count());
    }

    #[Test]
    public function execute_writes_one_content_page_draft_then_skips_duplicate(): void
    {
        $page = $this->createContentPage();
        [$candidateReviewPath] = $this->writeCandidateReview($page);
        $sha = hash_file('sha256', $candidateReviewPath) ?: '';

        $exitCode = Artisan::call('seo-agent:l5a-cms-draft-write-canary', [
            '--candidate-review' => $candidateReviewPath,
            '--limit' => 1,
            '--confirm-candidate-review-sha256' => $sha,
            '--auto-approve-low-risk' => true,
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertFalse((bool) ($summary['dry_run'] ?? true));
        $this->assertSame(1, $summary['rows_created'] ?? null);
        $this->assertSame(0, $summary['rows_skipped_existing'] ?? null);
        $this->assertSame(1, CmsTranslationRevision::query()->count());
        $this->assertNotSame('', (string) ($summary['idempotency_key'] ?? ''));
        $this->assertSame((int) $page->published_revision_id, data_get($summary, 'rollback_pointer.previous_published_revision_id'));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.cms_publish', true));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.search_channel_submit', true));

        $revision = CmsTranslationRevision::query()->firstOrFail();
        $this->assertSame(CmsTranslationRevision::STATUS_DRAFT, $revision->revision_status);
        $this->assertNull($revision->published_at);
        $this->assertSame('content_page:'.$page->id.':en', data_get($revision->payload_json, 'seo_agent.subject_ref'));
        $this->assertSame('/about', data_get($revision->payload_json, 'proposal.proposed_canonical_path'));

        $exitCode = Artisan::call('seo-agent:l5a-cms-draft-write-canary', [
            '--candidate-review' => $candidateReviewPath,
            '--limit' => 1,
            '--confirm-candidate-review-sha256' => $sha,
            '--auto-approve-low-risk' => true,
            '--execute' => true,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame(0, $summary['rows_created'] ?? null);
        $this->assertSame(1, $summary['rows_skipped_existing'] ?? null);
        $this->assertSame(1, CmsTranslationRevision::query()->count());
        $this->assertNoForbiddenStrings($summary);
    }

    #[Test]
    public function candidate_review_with_forbidden_field_is_rejected(): void
    {
        $page = $this->createContentPage();
        [$candidateReviewPath, , $candidateReview] = $this->writeCandidateReview($page);
        $candidateReview['selected_candidate']['raw_url'] = 'https://example.test/about';
        File::put($candidateReviewPath, json_encode($candidateReview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $exitCode = Artisan::call('seo-agent:l5a-cms-draft-write-canary', [
            '--candidate-review' => $candidateReviewPath,
            '--limit' => 1,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertContains('forbidden_candidate_review_field_present', $summary['issues'] ?? []);
        $this->assertSame(0, CmsTranslationRevision::query()->count());
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
    private function writeCandidateReview(ContentPage $page): array
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
            'recommended_next_step' => 'cms_draft_write_canary_limit_1',
            'risk_flags' => [],
            'blocked_actions' => ['cms_publish', 'search_channel_submit', 'indexnow_live_submit'],
        ];
        $package = [
            'schema_version' => 'seo-agent-cms-draft-package-dry-run.v1',
            'dry_run' => true,
            'cms_write_allowed' => false,
            'execution_permission' => false,
            'proposal_count' => 1,
            'proposal_items' => [[
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
            ]],
            'claim_gate_required' => true,
            'human_approval_required' => true,
        ];
        $dir = storage_path('framework/testing/l5a-candidate-review-'.Str::uuid()->toString());
        File::ensureDirectoryExists($dir);
        $packagePath = $dir.'/source-package.json';
        File::put($packagePath, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $candidateReview = [
            'schema_version' => 'seo-agent-l5a-candidate-review.v1',
            'task' => 'SEO-AGENT-L5A-PREFLIGHT-CANDIDATE-REVIEW-01',
            'status' => 'success',
            'run_mode' => 'readonly_l5a_preflight_candidate_review',
            'limit' => 1,
            'selected_count' => 1,
            'selected_candidates' => [$candidate],
            'selected_candidate' => $candidate,
            'input_artifacts' => [
                'cms_draft_package_dry_run' => [
                    'path' => $packagePath,
                    'size' => filesize($packagePath) ?: 0,
                    'sha256' => hash_file('sha256', $packagePath) ?: '',
                    'schema_version' => 'seo-agent-cms-draft-package-dry-run.v1',
                ],
            ],
            'negative_guarantees' => [
                'cms_write' => false,
                'cms_publish' => false,
                'search_channel_submit' => false,
                'indexnow_live_submit' => false,
            ],
        ];
        $candidateReviewPath = $dir.'/candidate-review.json';
        File::put($candidateReviewPath, json_encode($candidateReview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return [$candidateReviewPath, $packagePath, $candidateReview];
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
