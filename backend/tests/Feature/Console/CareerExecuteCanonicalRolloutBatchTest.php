<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerExecuteCanonicalRolloutBatchTest extends TestCase
{
    private string $tmpProjectionPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpProjectionPath = sys_get_temp_dir().'/test-projection-'.uniqid().'.json';
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
}
