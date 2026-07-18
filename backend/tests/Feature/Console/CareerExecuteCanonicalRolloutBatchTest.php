<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionExporter;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerExecuteCanonicalRolloutBatchTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpProjectionPath;

    private ?string $materializedProjectionDir = null;

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
        if ($this->materializedProjectionDir !== null && is_dir($this->materializedProjectionDir)) {
            File::deleteDirectory($this->materializedProjectionDir);
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
        $this->assertSame([
            'build_projection',
            'stage_immutable_detail_projection',
            'verify_staged_detail_payload',
            'expose_runtime_projection_flags',
            'activate_detail_pointer_batch',
            'rebuild_and_activate_directory_read_model',
        ], $payload['atomic_exposure_sequence'] ?? null);
    }

    public function test_apply_prepares_a_cold_candidate_cache_before_public_exposure(): void
    {
        $candidateProjection = $this->candidateProjection(['actuaries']);
        $this->writeProjection($candidateProjection);
        $this->writeMaterializedProjection($candidateProjection);
        $this->assertSame(
            CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE,
            app(CareerRuntimePublishProjectionVisibility::class)->itemForSlug('actuaries', 'en')['runtime_publish_state'] ?? null,
        );
        $cache = app(PublicCareerAuthorityResponseCache::class);
        $this->assertFalse($cache->jobDetailCacheIsReady('actuaries', 'en'));
        $this->assertFalse($cache->jobDetailCacheIsReady('actuaries', 'zh'));
        $this->assertNotContains(
            'actuaries',
            array_map(
                static fn (array $item): string => (string) data_get($item, 'identity.canonical_slug', ''),
                $cache->jobIndexPayload('en')['items'],
            ),
        );

        $exitCode = Artisan::call('career:execute-canonical-rollout-batch', [
            '--batch-id' => 'batch-actuaries-cache-cold',
            '--slugs' => 'actuaries',
            '--locales' => 'en,zh',
            '--rollback-group' => 'actuaries',
            '--apply' => true,
            '--projection' => $this->tmpProjectionPath,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('promoted_success', $payload['status'] ?? null);
        $this->assertSame('pass', data_get($payload, 'cache_preparation.status'));
        $this->assertCount(2, data_get($payload, 'cache_preparation.entries'));
        $this->assertSame('pass', data_get($payload, 'detail_cache_activation.status'));
        $this->assertTrue($cache->jobDetailCacheIsReady('actuaries', 'en'));
        $this->assertTrue($cache->jobDetailCacheIsReady('actuaries', 'zh'));
        $cachedPayload = $cache->jobDetailCacheReadiness('actuaries', 'en')['payload'];
        $this->assertTrue(
            (bool) data_get($cachedPayload, 'seo_contract.index_eligible'),
            json_encode(data_get($cachedPayload, 'seo_contract'), JSON_THROW_ON_ERROR),
        );
        $this->assertSame('cached', data_get($payload, 'directory_activation.career_directory_en.status'));
        $this->assertSame('cached', data_get($payload, 'directory_activation.career_directory_zh_cn.status'));
        $this->assertTrue(data_get($payload, 'directory_activation.career_directory_en.job_index_activated'));
        $this->assertContains(
            'actuaries',
            array_map(
                static fn (array $item): string => (string) data_get($item, 'identity.canonical_slug', ''),
                $cache->jobIndexPayload('en')['items'],
            ),
        );
        $this->assertContains(
            'actuaries',
            array_column($cache->directoryReadModelPayload('en')['items'], 'slug'),
        );

        $cache->warmDirectoryReadModels(['en']);

        $this->assertContains(
            'actuaries',
            array_column($cache->directoryReadModelPayload('en')['items'], 'slug'),
            'A later directory-only warm must preserve a verified snapshot-backed promotion.',
        );

        $cache->warm();

        $this->assertContains(
            'actuaries',
            array_column($cache->directoryReadModelPayload('en')['items'], 'slug'),
            'A later broad authority warm must preserve a verified snapshot-backed promotion.',
        );
    }

    public function test_candidate_request_cannot_purge_a_staged_pre_exposure_payload(): void
    {
        $candidateProjection = $this->candidateProjection(['actuaries']);
        $this->writeProjection($candidateProjection);
        $this->writeMaterializedProjection($candidateProjection);
        $publishedProjection = $this->publishedProjection(['actuaries']);
        $publishedEn = collect($publishedProjection['items'])
            ->first(fn (array $item): bool => ($item['locale'] ?? null) === 'en');
        $this->assertIsArray($publishedEn);

        $cache = app(PublicCareerAuthorityResponseCache::class);
        $prepared = $cache->prepareJobDetailPayloadForExposure('actuaries', 'en', $publishedEn);

        $this->assertSame('ready', $prepared['status']);
        $this->assertSame('ready_staged', $prepared['classification']);
        $this->assertFalse($cache->jobDetailCacheIsReady('actuaries', 'en'));
        $this->assertSame('not_found', $cache->jobDetailRead('actuaries', 'en')['state']);

        $activation = $cache->activatePreparedJobDetailPayloadsForExposure([$prepared]);

        $this->assertSame('pass', $activation['status']);
        $this->assertTrue($cache->jobDetailCacheIsReady('actuaries', 'en'));
        $this->assertSame($prepared['version'], $cache->jobDetailCacheReadiness('actuaries', 'en')['version']);
        $this->assertSame('fresh', $cache->jobDetailRead('actuaries', 'en')['state']);
        $this->assertTrue($cache->jobDetailCacheIsReady('actuaries', 'en'));
    }

    public function test_apply_reports_committed_write_as_not_rolled_back_when_remediation_is_unverified(): void
    {
        $candidateProjection = $this->candidateProjection(['actuaries']);
        $this->writeProjection($candidateProjection);
        $this->writeMaterializedProjection($candidateProjection);
        $cache = app(PublicCareerAuthorityResponseCache::class);
        $cacheManager = Cache::getFacadeRoot();
        $zhActiveKey = $cache->jobDetailActiveVersionKey('actuaries', 'zh');

        try {
            $cacheMock = Cache::partialMock();
            $cacheMock->shouldReceive('lock')
                ->andReturnUsing(static fn (string $key, int $seconds) => $cacheManager->lock($key, $seconds));
            $cacheMock->shouldReceive('get')
                ->andReturnUsing(static fn (string $key, mixed $default = null): mixed => $cacheManager->get($key, $default));
            $cacheMock->shouldReceive('has')
                ->andReturnUsing(static fn (string $key): bool => $cacheManager->has($key));
            $cacheMock->shouldReceive('forget')
                ->andReturnUsing(static fn (string $key): bool => $cacheManager->forget($key));
            $cacheMock->shouldReceive('put')
                ->andReturnUsing(static fn (string $key, mixed $value, mixed $ttl = null): bool => $cacheManager->put($key, $value, $ttl));
            $cacheMock->shouldReceive('add')
                ->andReturnUsing(static fn (string $key, mixed $value, mixed $ttl = null): bool => $cacheManager->add($key, $value, $ttl));
            $cacheMock->shouldReceive('store')
                ->andReturnUsing(static fn (?string $name = null) => $cacheManager->store($name));
            $cacheMock->shouldReceive('forever')
                ->andReturnUsing(static function (string $key, mixed $value) use ($cacheManager, $zhActiveKey): bool {
                    if ($key === $zhActiveKey) {
                        throw new \RuntimeException('synthetic post-commit detail activation failure');
                    }

                    return $cacheManager->forever($key, $value);
                });

            $exitCode = Artisan::call('career:execute-canonical-rollout-batch', [
                '--batch-id' => 'batch-actuaries-post-commit-cache-failure',
                '--slugs' => 'actuaries',
                '--locales' => 'en,zh',
                '--rollback-group' => 'actuaries',
                '--apply' => true,
                '--projection' => $this->tmpProjectionPath,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true);
        } finally {
            Cache::swap($cacheManager);
        }

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($payload);
        $this->assertSame('rollback_write_not_persisted', $payload['status'] ?? null);
        $this->assertTrue($payload['writes_database'] ?? false);
        $this->assertTrue($payload['database_commit_succeeded'] ?? false);
        $this->assertFalse($payload['promotion_rolled_back'] ?? true);
        $this->assertTrue($payload['rollback_required'] ?? false);
        $this->assertFalse(data_get($payload, 'remediation.succeeded', true));
        $this->assertSame('rollback_not_persisted', data_get($payload, 'remediation.status'));
    }

    public function test_prepared_detail_activation_blocks_when_exposure_projection_snapshot_is_missing(): void
    {
        $publishedProjection = $this->publishedProjection(['actuaries']);
        $publishedEn = collect($publishedProjection['items'])
            ->first(fn (array $item): bool => ($item['locale'] ?? null) === 'en');
        $this->assertIsArray($publishedEn);

        $cache = app(PublicCareerAuthorityResponseCache::class);
        $prepared = $cache->prepareJobDetailPayloadForExposure('actuaries', 'en', $publishedEn);
        $cache->forgetPreparedJobDetailExposureProjectionSnapshots([$prepared]);

        $activation = $cache->activatePreparedJobDetailPayloadsForExposure([$prepared]);

        $this->assertSame('blocked', $activation['status']);
        $this->assertSame('prepared_detail_activation_failed', data_get($activation, 'failures.0.reason'));
        $this->assertFalse($cache->jobDetailCacheIsReady('actuaries', 'en'));
    }

    public function test_prepared_detail_activation_restores_all_pointers_when_a_later_locale_fails(): void
    {
        $publishedProjection = $this->publishedProjection(['actuaries']);
        $cache = app(PublicCareerAuthorityResponseCache::class);
        $oldEnVersion = $cache->publishJobDetailReadModel('actuaries', 'en', ['fixture' => 'old-en']);
        $oldZhVersion = $cache->publishJobDetailReadModel('actuaries', 'zh', ['fixture' => 'old-zh']);
        $prepared = collect($publishedProjection['items'])
            ->map(fn (array $item): array => $cache->prepareJobDetailPayloadForExposure(
                'actuaries',
                (string) $item['locale'],
                $item,
            ))
            ->values()
            ->all();
        $cacheManager = Cache::getFacadeRoot();
        $zhActiveKey = $cache->jobDetailActiveVersionKey('actuaries', 'zh');

        try {
            $cacheMock = Cache::partialMock();
            $cacheMock->shouldReceive('lock')
                ->andReturnUsing(static fn (string $key, int $seconds) => $cacheManager->lock($key, $seconds));
            $cacheMock->shouldReceive('get')
                ->andReturnUsing(static fn (string $key, mixed $default = null): mixed => $cacheManager->get($key, $default));
            $cacheMock->shouldReceive('has')
                ->andReturnUsing(static fn (string $key): bool => $cacheManager->has($key));
            $cacheMock->shouldReceive('forget')
                ->andReturnUsing(static fn (string $key): bool => $cacheManager->forget($key));
            $cacheMock->shouldReceive('forever')
                ->andReturnUsing(static function (string $key, mixed $value) use ($cacheManager, $zhActiveKey, $oldZhVersion): bool {
                    if ($key === $zhActiveKey && $value !== $oldZhVersion) {
                        throw new \RuntimeException('synthetic zh detail activation failure');
                    }

                    return $cacheManager->forever($key, $value);
                });

            $activation = $cache->activatePreparedJobDetailPayloadsForExposure($prepared);
        } finally {
            Cache::swap($cacheManager);
        }

        $this->assertSame('blocked', $activation['status']);
        $this->assertSame($oldEnVersion, $cache->jobDetailCacheReadiness('actuaries', 'en')['version']);
        $this->assertSame($oldZhVersion, $cache->jobDetailCacheReadiness('actuaries', 'zh')['version']);
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

    public function test_dry_run_can_skip_audit_report_write_for_production_preflight(): void
    {
        $this->writeProjection($this->candidateProjection(['actuaries']));

        $auditDir = storage_path('app/private/career_canonical_rollout_batch_executions');
        if (is_dir($auditDir)) {
            File::cleanDirectory($auditDir);
        }

        $exitCode = Artisan::call('career:execute-canonical-rollout-batch', [
            '--batch-id' => 'batch-001-no-audit',
            '--slugs' => 'actuaries',
            '--locales' => 'en,zh',
            '--rollback-group' => 'actuaries',
            '--dry-run' => true,
            '--no-audit-write' => true,
            '--projection' => $this->tmpProjectionPath,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('planned', $payload['status'] ?? null);
        $this->assertFalse($payload['writes_database'] ?? true);

        $files = is_dir($auditDir) ? File::files($auditDir) : [];
        $this->assertSame([], $files, 'No audit report should be written when --dry-run --no-audit-write is used.');
    }

    public function test_apply_rejects_no_audit_write(): void
    {
        $this->writeProjection($this->candidateProjection(['actuaries']));

        $exitCode = Artisan::call('career:execute-canonical-rollout-batch', [
            '--batch-id' => 'batch-001-no-audit-apply',
            '--slugs' => 'actuaries',
            '--locales' => 'en,zh',
            '--rollback-group' => 'actuaries',
            '--apply' => true,
            '--no-audit-write' => true,
            '--projection' => $this->tmpProjectionPath,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--no-audit-write is only allowed with --dry-run', Artisan::output());
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
        $this->assertStringContainsString('--no-audit-write', $output);
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

    /** @param array<string, mixed> $projection */
    private function writeMaterializedProjection(array $projection): void
    {
        $this->materializedProjectionDir = storage_path(
            'app/private/career_runtime_publish_projection/test-stale-candidate-'.uniqid(),
        );
        File::ensureDirectoryExists($this->materializedProjectionDir);
        $path = $this->materializedProjectionDir.'/'.CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME;
        File::put($path, json_encode($projection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        touch($path, time() + 3600);
    }
}
