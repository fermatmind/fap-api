<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoAgentAutoRollbackGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-21 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function preflight_blocks_upstream_blocked_evidence_without_writes(): void
    {
        $dir = $this->artifactDir();
        $evidencePath = $this->writeJson($dir, 'blocked-run.json', [
            'schema_version' => 'seo-agent-run-evidence.v1',
            'status' => 'blocked',
            'negative_guarantees' => [
                'cms_bulk_publish' => false,
                'google_indexing_api_call' => false,
                'scheduler_activation' => false,
            ],
        ]);

        $exitCode = Artisan::call('seo-agent:auto-rollback-guard', [
            '--run-evidence' => $evidencePath,
            '--mode' => 'preflight',
            '--artifact-dir' => $dir,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $artifact = $this->readJson($this->latestArtifact($dir));
        $this->assertSame('blocked', $artifact['status'] ?? null);
        $this->assertTrue((bool) ($artifact['stop_the_line'] ?? false));
        $this->assertContains('pause_publish', data_get($artifact, 'result.guard_actions'));
        $this->assertSame(0, data_get($artifact, 'result.rollback_executed_count'));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.search_channel_enqueue', true));
    }

    #[Test]
    public function post_publish_plan_validates_rollback_evidence_without_writing(): void
    {
        [$page, $previous, $candidate] = $this->createPublishedCanaryState();
        $dir = $this->artifactDir();
        $evidencePath = $this->writePublishEvidence($dir, $page, $previous, $candidate);

        $exitCode = Artisan::call('seo-agent:auto-rollback-guard', [
            '--run-evidence' => $evidencePath,
            '--mode' => 'post-publish',
            '--artifact-dir' => $dir,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $artifact = $this->readJson($this->latestArtifact($dir));
        $this->assertSame('pass', $artifact['status'] ?? null);
        $this->assertFalse((bool) ($artifact['stop_the_line'] ?? true));
        $this->assertTrue((bool) data_get($artifact, 'result.rollback_plan.available'));
        $this->assertSame(0, data_get($artifact, 'result.rollback_executed_count'));
        $this->assertSame((int) $candidate->id, (int) $page->refresh()->published_revision_id);
    }

    #[Test]
    public function execute_rolls_back_one_content_page_canary_only(): void
    {
        [$page, $previous, $candidate] = $this->createPublishedCanaryState();
        $dir = $this->artifactDir();
        $evidencePath = $this->writePublishEvidence($dir, $page, $previous, $candidate);

        $exitCode = Artisan::call('seo-agent:auto-rollback-guard', [
            '--run-evidence' => $evidencePath,
            '--mode' => 'post-publish',
            '--artifact-dir' => $dir,
            '--execute' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $artifact = $this->readJson($this->latestArtifact($dir));
        $this->assertSame('pass', $artifact['status'] ?? null);
        $this->assertSame(1, data_get($artifact, 'result.rollback_executed_count'));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.content_page_rollback_beyond_one', true));
        $this->assertSame((int) $previous->id, (int) $page->refresh()->published_revision_id);
        $this->assertSame(CmsTranslationRevision::STATUS_ARCHIVED, (string) $candidate->refresh()->revision_status);
        $this->assertSame(CmsTranslationRevision::STATUS_PUBLISHED, (string) $previous->refresh()->revision_status);
    }

    #[Test]
    public function generated_contract_documents_guard_boundaries(): void
    {
        $contract = $this->readJson(base_path('docs/seo/generated/seo-agent-auto-rollback-guard.v1.json'));

        $this->assertSame('seo-agent-auto-rollback-guard.v1', $contract['version'] ?? null);
        $this->assertSame('php artisan seo-agent:auto-rollback-guard', $contract['command'] ?? null);
        $this->assertSame(1, data_get($contract, 'execute_mode.max_content_page_rollback_count'));
        $this->assertFalse((bool) data_get($contract, 'execute_mode.article_rollback', true));
        $this->assertFalse((bool) data_get($contract, 'execute_mode.bulk_rollback', true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.search_channel_submit', true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.google_indexing_api_call', true));
    }

    /**
     * @return array{0: ContentPage, 1: CmsTranslationRevision, 2: CmsTranslationRevision}
     */
    private function createPublishedCanaryState(): array
    {
        $page = ContentPage::query()->create([
            'org_id' => 0,
            'slug' => 'rollback-guard-page',
            'path' => '/zh/rollback-guard-page',
            'kind' => ContentPage::KIND_HELP,
            'page_type' => 'support_static',
            'title' => 'Rollback Guard Page',
            'summary' => 'Existing summary.',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => 'zh-CN',
            'is_public' => true,
            'is_indexable' => true,
            'schema_enabled' => false,
            'publish_allowed' => true,
            'operator_approval_required' => false,
            'legal_review_required' => false,
            'science_review_required' => false,
            'claim_gate_status' => 'passed',
            'forbidden_claims' => [],
            'status' => ContentPage::STATUS_PUBLISHED,
            'review_state' => 'approved',
            'published_at' => now()->subDay(),
        ]);

        $previous = CmsTranslationRevision::query()->create([
            'org_id' => 0,
            'content_type' => 'content_page',
            'content_id' => (int) $page->id,
            'translation_group_id' => (string) $page->translation_group_id,
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => CmsTranslationRevision::STATUS_PUBLISHED,
            'payload_json' => ['title' => 'Previous'],
            'published_at' => now()->subDay(),
        ]);
        $candidate = CmsTranslationRevision::query()->create([
            'org_id' => 0,
            'content_type' => 'content_page',
            'content_id' => (int) $page->id,
            'translation_group_id' => (string) $page->translation_group_id,
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
            'revision_number' => 2,
            'revision_status' => CmsTranslationRevision::STATUS_PUBLISHED,
            'payload_json' => ['title' => 'Candidate'],
            'published_at' => now(),
        ]);
        $page->forceFill([
            'working_revision_id' => (int) $candidate->id,
            'published_revision_id' => (int) $candidate->id,
        ])->save();

        return [$page->refresh(), $previous, $candidate];
    }

    private function writePublishEvidence(string $dir, ContentPage $page, CmsTranslationRevision $previous, CmsTranslationRevision $candidate): string
    {
        return $this->writeJson($dir, 'publish-evidence.json', [
            'schema_version' => 'seo-agent-cms-publish-canary.v1',
            'ok' => true,
            'status' => 'success',
            'execute' => true,
            'writes_attempted' => true,
            'writes_committed' => true,
            'published_count' => 1,
            'rows_skipped_existing' => 0,
            'affected_refs' => [[
                'status' => 'published',
                'target_model' => 'content_page',
                'subject_ref' => 'content_page:'.$page->id.':zh-CN',
                'revision_id' => (int) $candidate->id,
                'safe_path' => '/zh/rollback-guard-page',
            ]],
            'rollback_evidence' => [
                'available' => true,
                'content_page_ref' => 'content_page:'.$page->id.':zh-CN',
                'previous_revision_id' => (int) $previous->id,
                'candidate_revision_id' => (int) $candidate->id,
            ],
            'boundaries' => [
                'search_channel_enqueue' => false,
                'indexing_request' => false,
            ],
            'negative_guarantees' => [
                'search_channel_enqueue' => false,
                'search_channel_submit' => false,
                'indexing_request' => false,
                'scheduler_activation' => false,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $dir, string $filename, array $payload): string
    {
        $path = rtrim($dir, '/').'/'.$filename;
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return $path;
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-agent-auto-rollback-guard-'.Str::uuid()->toString());
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    private function latestArtifact(string $dir): ?string
    {
        $paths = File::glob(rtrim($dir, '/').'/seo-agent-auto-rollback-guard-*.json') ?: [];
        rsort($paths);

        return $paths[0] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(?string $path): array
    {
        $this->assertIsString($path);
        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($payload);

        return $payload;
    }
}
