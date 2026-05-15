<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerExecuteCanonicalRolloutBatchTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpProjectionPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpProjectionPath = sys_get_temp_dir().'/test-projection-'.uniqid().'.json';

        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'test-family',
            'title_en' => 'Test Family',
            'title_zh' => '测试族',
        ]);

        foreach (['actuaries', 'economists', 'financial-analysts', 'web-developers', 'software-developers', 'cn-engineers'] as $slug) {
            Occupation::query()->create([
                'family_id' => $family->id,
                'canonical_slug' => $slug,
                'entity_level' => 'market_child',
                'truth_market' => 'US',
                'display_market' => 'US',
                'crosswalk_mode' => 'global_standard',
                'canonical_title_en' => ucfirst(str_replace('-', ' ', $slug)),
                'canonical_title_zh' => $slug,
                'search_h1_zh' => $slug,
            ]);
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpProjectionPath)) {
            @unlink($this->tmpProjectionPath);
        }

        parent::tearDown();
    }

    public function test_command_reports_dry_run_plan(): void
    {
        $this->writeProjection($this->candidateProjection(['actuaries']));

        $exitCode = Artisan::call('career:execute-canonical-rollout-batch', [
            '--batch-id' => 'batch-001',
            '--slugs' => 'actuaries',
            '--locales' => 'en,zh',
            '--rollback-group' => 'actuaries',
            '--dry-run' => true,
            '--projection' => $this->tmpProjectionPath,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertIsArray($payload);
        $this->assertSame('planned', $payload['status'] ?? null);
        $this->assertTrue($payload['dry_run'] ?? false);
        $this->assertFalse($payload['writes_database'] ?? true);
        $this->assertSame(['actuaries'], $payload['promoted_slugs'] ?? []);
    }

    public function test_command_prefers_candidate_aware_top_level_items_over_projection_metadata(): void
    {
        $candidateProjection = $this->candidateProjection(['actuaries']);
        $staleProjectionMetadata = $this->publishedProjection(['actuaries']);
        $candidateAwareProjection = [
            'status' => 'pass',
            'projection_kind' => 'career_runtime_publish_projection_candidate_aware',
            'source_authority' => 'candidate_prep_apply_overlay',
            'candidate_aware_overlay' => [
                'source' => 'candidate_prep_apply_overlay',
                'runtime_publish_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE,
                'slug_count' => 1,
                'locale_count' => 2,
                'expected_locale_rows' => 2,
                'canonical_ledger_authority_claimed' => false,
            ],
            'items' => $candidateProjection['items'],
            'projection' => [
                'projection_kind' => 'career_runtime_publish_projection',
                'items' => $staleProjectionMetadata['items'],
            ],
        ];

        $this->writeRawProjection($candidateAwareProjection);
        $writtenProjection = json_decode((string) file_get_contents($this->tmpProjectionPath), true);
        $this->assertSame(
            CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE,
            $writtenProjection['items'][0]['runtime_publish_state'] ?? null,
        );

        $exitCode = Artisan::call('career:execute-canonical-rollout-batch', [
            '--batch-id' => 'batch-001',
            '--slugs' => 'actuaries',
            '--locales' => 'en,zh',
            '--rollback-group' => 'actuaries',
            '--dry-run' => true,
            '--projection' => $this->tmpProjectionPath,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $payload = json_decode(Artisan::output(), true);

        $this->assertIsArray($payload);
        $this->assertSame('planned', $payload['status'] ?? null);
        $this->assertSame('pass', $payload['plan_validation']['status'] ?? null);
        $this->assertSame(2, $payload['promoted_locale_rows'] ?? null);
        $this->assertFalse($payload['writes_database'] ?? true);
    }

    public function test_command_rejects_blocked_state(): void
    {
        $this->writeProjection($this->blockedProjection(['actuaries']));

        $exitCode = Artisan::call('career:execute-canonical-rollout-batch', [
            '--batch-id' => 'batch-001',
            '--slugs' => 'actuaries',
            '--locales' => 'en,zh',
            '--rollback-group' => 'actuaries',
            '--dry-run' => true,
            '--projection' => $this->tmpProjectionPath,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertIsArray($payload);
        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertFalse($payload['writes_database'] ?? true);
    }

    public function test_command_rejects_software_developers(): void
    {
        $this->writeProjection($this->candidateProjection(['software-developers']));

        $exitCode = Artisan::call('career:execute-canonical-rollout-batch', [
            '--batch-id' => 'batch-001',
            '--slugs' => 'software-developers',
            '--locales' => 'en,zh',
            '--rollback-group' => 'software-developers',
            '--dry-run' => true,
            '--projection' => $this->tmpProjectionPath,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertIsArray($payload);
        $this->assertSame('blocked', $payload['status'] ?? null);
    }

    public function test_command_rejects_cn_slugs(): void
    {
        $this->writeProjection($this->candidateProjection(['cn-engineers']));

        $exitCode = Artisan::call('career:execute-canonical-rollout-batch', [
            '--batch-id' => 'batch-001',
            '--slugs' => 'cn-engineers',
            '--locales' => 'en,zh',
            '--rollback-group' => 'cn-engineers',
            '--dry-run' => true,
            '--projection' => $this->tmpProjectionPath,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertIsArray($payload);
        $this->assertSame('blocked', $payload['status'] ?? null);
    }

    public function test_command_requires_batch_id(): void
    {
        $exitCode = Artisan::call('career:execute-canonical-rollout-batch', [
            '--slugs' => 'actuaries',
            '--locales' => 'en,zh',
            '--rollback-group' => 'actuaries',
            '--dry-run' => true,
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_command_requires_dry_run_or_apply(): void
    {
        $exitCode = Artisan::call('career:execute-canonical-rollout-batch', [
            '--batch-id' => 'batch-001',
            '--slugs' => 'actuaries',
            '--locales' => 'en,zh',
            '--rollback-group' => 'actuaries',
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_command_dry_run_and_apply_mutually_exclusive(): void
    {
        $this->writeProjection($this->candidateProjection(['actuaries']));

        $exitCode = Artisan::call('career:execute-canonical-rollout-batch', [
            '--batch-id' => 'batch-001',
            '--slugs' => 'actuaries',
            '--locales' => 'en,zh',
            '--rollback-group' => 'actuaries',
            '--dry-run' => true,
            '--apply' => true,
            '--projection' => $this->tmpProjectionPath,
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_command_handles_multiple_slugs_in_dry_run(): void
    {
        $slugs = ['actuaries', 'economists', 'web-developers'];
        $this->writeProjection($this->candidateProjection($slugs));

        $exitCode = Artisan::call('career:execute-canonical-rollout-batch', [
            '--batch-id' => 'batch-001',
            '--slugs' => implode(',', $slugs),
            '--locales' => 'en,zh',
            '--rollback-group' => implode(',', $slugs),
            '--dry-run' => true,
            '--projection' => $this->tmpProjectionPath,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertIsArray($payload);
        $this->assertSame('planned', $payload['status'] ?? null);
        $this->assertSame(6, $payload['promoted_locale_rows'] ?? 0);
    }

    public function test_apply_uses_explicit_batch_ledger_authority_for_stale_blocked_override_member(): void
    {
        $this->writeProjection($this->candidateProjection(['financial-analysts']));

        $exitCode = Artisan::call('career:execute-canonical-rollout-batch', [
            '--batch-id' => 'batch-financial-analysts',
            '--slugs' => 'financial-analysts',
            '--locales' => 'en,zh',
            '--rollback-group' => 'financial-analysts',
            '--apply' => true,
            '--projection' => $this->tmpProjectionPath,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $payload = json_decode(Artisan::output(), true);

        $this->assertIsArray($payload);
        $this->assertSame('promoted_success', $payload['status'] ?? null);
        $this->assertTrue($payload['writes_database'] ?? false);
        $this->assertTrue($payload['write_verified'] ?? false);
        $this->assertSame(['financial-analysts'], $payload['promoted_slugs'] ?? null);
        $this->assertSame(2, $payload['promoted_locale_rows'] ?? null);
        $this->assertSame(2, data_get($payload, 'persistence_check.found_published'));
        $this->assertSame(0, data_get($payload, 'persistence_check.not_published_count'));
        $this->assertFalse($payload['rollback_required'] ?? true);
    }

    public function test_command_writes_audit_report(): void
    {
        $this->writeProjection($this->candidateProjection(['actuaries']));

        $auditDir = storage_path('app/private/career_canonical_rollout_batch_executions');
        if (is_dir($auditDir)) {
            File::cleanDirectory($auditDir);
        }

        Artisan::call('career:execute-canonical-rollout-batch', [
            '--batch-id' => 'batch-001-audit',
            '--slugs' => 'actuaries',
            '--locales' => 'en,zh',
            '--rollback-group' => 'actuaries',
            '--dry-run' => true,
            '--projection' => $this->tmpProjectionPath,
        ]);

        $files = is_dir($auditDir) ? File::files($auditDir) : [];
        $this->assertNotEmpty($files, 'Audit report should be written');

        $content = json_decode((string) file_get_contents($files[0]->getPathname()), true);
        $this->assertSame('planned', $content['status'] ?? null);
    }

    public function test_command_help_shows_all_options(): void
    {
        $exitCode = Artisan::call('career:execute-canonical-rollout-batch', [
            '--help' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();

        $this->assertStringContainsString('--batch-id', $output);
        $this->assertStringContainsString('--slugs', $output);
        $this->assertStringContainsString('--locales', $output);
        $this->assertStringContainsString('--rollback-group', $output);
        $this->assertStringContainsString('--dry-run', $output);
        $this->assertStringContainsString('--apply', $output);
        $this->assertStringContainsString('--quarantine-on-failure', $output);
    }

    public function test_dry_run_with_quarantine_flag_is_accepted(): void
    {
        $this->writeProjection($this->candidateProjection(['actuaries']));

        $exitCode = Artisan::call('career:execute-canonical-rollout-batch', [
            '--batch-id' => 'batch-001',
            '--slugs' => 'actuaries',
            '--locales' => 'en,zh',
            '--rollback-group' => 'actuaries',
            '--dry-run' => true,
            '--quarantine-on-failure' => true,
            '--projection' => $this->tmpProjectionPath,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertIsArray($payload);
        $this->assertSame('planned', $payload['status'] ?? null);
        $this->assertTrue($payload['dry_run'] ?? false);
        $this->assertFalse($payload['writes_database'] ?? true);
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, mixed>
     */
    private function candidateProjection(array $slugs): array
    {
        return $this->buildProjection($slugs, CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE);
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, mixed>
     */
    private function blockedProjection(array $slugs): array
    {
        return $this->buildProjection($slugs, CareerRuntimePublishProjectionService::STATE_BLOCKED);
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, mixed>
     */
    private function publishedProjection(array $slugs): array
    {
        return $this->buildProjection($slugs, CareerRuntimePublishProjectionService::STATE_PUBLISHED);
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, mixed>
     */
    private function buildProjection(array $slugs, string $state): array
    {
        $items = [];
        $isPublished = $state === CareerRuntimePublishProjectionService::STATE_PUBLISHED;

        foreach ($slugs as $slug) {
            foreach (['en', 'zh'] as $locale) {
                $items[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'public_resolution_type' => 'public_canonical_job',
                    'runtime_publish_state' => $state,
                    'detail_route_enabled' => $isPublished,
                    'dataset_visible' => $isPublished,
                    'search_visible' => $isPublished,
                    'sitemap_live' => $isPublished,
                    'llms_live' => $isPublished,
                    'llms_full_live' => $isPublished,
                    'canonical_url' => $isPublished ? 'https://fermatmind.com/'.$locale.'/career/jobs/'.$slug : null,
                    'canonical_self' => $isPublished,
                    'robots_indexable' => $isPublished,
                    'release_gate_pass' => $isPublished,
                    'blockers' => [],
                ];
            }
        }

        return [
            'projection_kind' => 'career_runtime_publish_projection',
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $projection
     */
    private function writeProjection(array $projection): void
    {
        $payload = ['projection' => $projection];
        File::put($this->tmpProjectionPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $projection
     */
    private function writeRawProjection(array $projection): void
    {
        File::put($this->tmpProjectionPath, json_encode($projection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
