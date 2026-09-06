<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Governance\RuntimeCapabilitySnapshotBuilder;
use App\Services\SeoCouncil\Platform12\Platform12RuntimeControl;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class SeoPlatform12A08ActivationEvidenceTest extends TestCase
{
    private string $directory;

    private string $sha = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/seo-council-a08-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        config()->set('seo_council.scheduler_enabled', true);
        config()->set('seo_council.daily_read_only_enabled', true);
        config()->set('seo_council.runtime_cache_store', 'array');
        config()->set('seo_council.activation_receipt_path', $this->directory.'/activation.json');
        config()->set('seo_council.release_revision_path', $this->directory.'/REVISION');
        file_put_contents($this->directory.'/REVISION', $this->sha."\n");
        Cache::store('array')->forget(Platform12RuntimeControl::CACHE_KEY);
        config()->set('cache.stores.array.driver', 'redis');
        $this->app->instance('env', 'production');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_scoped_exact_sha_evidence_allows_activation_without_nightly_and_forgery_is_rejected(): void
    {
        $manifest = $this->manifest($this->sha, $this->sha);
        $this->write($manifest);
        $runtime = app(Platform12RuntimeControl::class);
        $this->assertSame('READY', $runtime->prerequisite());
        $this->assertSame('ACTIVE_READ_ONLY', $runtime->change(false)['state']);

        data_set($manifest, 'validation.staging_acceptance.source_connected', false);
        $this->write($manifest);
        $this->assertSame('SCOPED_ACCEPTANCE_EVIDENCE_HOLD', $runtime->prerequisite());

        $manifest = $this->manifest($this->sha, $this->sha);
        data_set($manifest, 'validation.ci.artifact_digest', str_repeat('b', 64));
        $this->write($manifest);
        $this->assertSame('SCOPED_ACCEPTANCE_EVIDENCE_HOLD', $runtime->prerequisite());

        $manifest = $this->manifest($this->sha, $this->sha);
        data_set($manifest, 'runtime.version_vector.policy', str_repeat('0', 64));
        $this->write($manifest);
        $this->assertSame('DEPENDENCY_CHANGED_HOLD', $runtime->prerequisite());

        $manifest = $this->manifest($this->sha, $this->sha);
        data_set($manifest, 'validation.production_controlled_acceptance.receipt_to_ui_verified', false);
        $this->write($manifest);
        $this->assertSame('PRODUCTION_CONTROLLED_ACCEPTANCE_HOLD', $runtime->prerequisite());
    }

    public function test_missing_and_legacy_evidence_have_distinct_first_activation_reasons(): void
    {
        $runtime = app(Platform12RuntimeControl::class);
        $this->assertSame('NOT_ACTIVATED_HOLD', $runtime->prerequisite());
        $this->write(['schema_version' => 'seo.platform12_a08_activation.v1']);
        $this->assertSame('LEGACY_ACTIVATION_EVIDENCE_HOLD', $runtime->prerequisite());
    }

    public function test_controlled_acceptance_blocks_natural_work_and_never_overwrites_new_manual_pause(): void
    {
        $manifest = $this->manifest($this->sha, $this->sha, 'CONTROLLED_ACCEPTANCE_ONLY');
        $this->write($manifest);
        $runtime = app(Platform12RuntimeControl::class);
        $operation = 'deploy:12:1:'.$this->sha;
        $begun = $runtime->beginControlledAcceptance($operation);
        $this->assertSame('CONTROLLED_ACCEPTANCE_ONLY', $begun['state']);
        $this->assertTrue($begun['controlled_acceptance_enabled']);
        $this->assertFalse($begun['computation_enabled']);
        $generation = $begun['generation'];

        $manual = $runtime->change(true);
        $this->assertSame(Platform12RuntimeControl::PAUSE_MANUAL, $manual['pause_source']);
        $this->write($this->manifest($this->sha, $this->sha));
        $after = $runtime->finishControlledAcceptance($operation, $generation, true);
        $this->assertSame('MANUAL_PAUSE_HOLD', $after['state']);
        $this->assertSame($manual['generation'], $after['generation']);
    }

    public function test_legacy_boolean_pause_requires_explicit_staging_adoption_and_legacy_running_can_be_protected(): void
    {
        $this->write($this->manifest($this->sha, $this->sha, 'CONTROLLED_ACCEPTANCE_ONLY'));
        $runtime = app(Platform12RuntimeControl::class);
        Cache::store('array')->forever(Platform12RuntimeControl::CACHE_KEY, ['paused' => true, 'generation' => str_repeat('a', 32)]);
        $this->assertSame('HISTORICAL_PAUSE_UNKNOWN_HOLD', $runtime->status()['state']);
        $this->assertFalse($runtime->beginControlledAcceptance('deploy:12:1:'.$this->sha)['controlled_acceptance_enabled']);
        $this->assertFalse($runtime->beginControlledAcceptance('deploy:12:1:'.$this->sha, true)['controlled_acceptance_enabled']);

        $this->app->instance('env', 'staging');
        $begun = $runtime->beginControlledAcceptance('deploy:12:1:'.$this->sha, true);
        $this->assertTrue($begun['controlled_acceptance_enabled']);
        $this->assertTrue($begun['historical_pause_adopted']);
        $finished = $runtime->finishControlledAcceptance(
            'deploy:12:1:'.$this->sha,
            $begun['generation'],
            true,
        );
        $this->assertSame('ACTIVE_READ_ONLY', $finished['state']);
        $this->assertNull($finished['pause_source']);
        $this->assertNull($finished['pause_reason']);
        $this->assertFalse($finished['historical_pause_adopted']);

        $manual = $runtime->change(true);
        $this->assertSame(Platform12RuntimeControl::PAUSE_MANUAL, $manual['pause_source']);
        $this->assertFalse($runtime->beginControlledAcceptance('deploy:13:1:'.$this->sha, true)['controlled_acceptance_enabled']);

        Cache::store('array')->forever(Platform12RuntimeControl::CACHE_KEY, ['paused' => false, 'generation' => str_repeat('b', 32)]);
        $this->assertTrue($runtime->beginControlledAcceptance('deploy:12:1:'.$this->sha)['controlled_acceptance_enabled']);
    }

    public function test_compatible_descendant_rebinds_release_and_preserves_operator_pause(): void
    {
        $runtime = app(Platform12RuntimeControl::class);
        $this->write($this->manifest($this->sha, $this->sha));
        $active = $runtime->change(false);
        $this->assertSame('MANUAL_PAUSE_HOLD', $runtime->change(true)['state']);

        $next = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        file_put_contents($this->directory.'/REVISION', $next."\n");
        $this->write($this->manifest($this->sha, $next));
        $status = $runtime->status();
        $this->assertSame('MANUAL_PAUSE_HOLD', $status['state']);
        $this->assertSame('PAUSED', $status['pause_intent']);
        $this->assertSame($active['activated_at'], $status['activated_at']);
        $this->assertSame($this->sha, $status['activation_source_sha']);
        $this->assertSame($next, $status['activation_bound_sha']);
    }

    public function test_staging_workflow_preserves_unhealthy_probe_as_business_evidence_and_always_writes_receipt(): void
    {
        $workflow = (string) file_get_contents(base_path('../.github/workflows/deploy.yml'));
        $start = strpos($workflow, 'name: Validate three A08 sources through controlled read-only Missions');
        $end = strpos($workflow, 'name: Record staging timing', $start ?: 0);
        $this->assertIsInt($start);
        $this->assertIsInt($end);
        $step = substr($workflow, $start, $end - $start);

        $this->assertStringContainsString('set +e', $step);
        $this->assertStringContainsString('-o ServerAliveInterval=15 -o ServerAliveCountMax=8', $step);
        $this->assertStringContainsString('public_probe_exit=$?', $step);
        $this->assertStringContainsString('write_hold_receipt', $step);
        $this->assertStringContainsString('failure_stage="sitemap_observation_refresh"', $step);
        $this->assertStringContainsString(
            'seo:warm-sitemap-source-cache --refresh-if-changed --json',
            $step,
        );
        $this->assertStringContainsString('IN("verified_unchanged","rebuilt")', $step);
        $this->assertStringContainsString('failure_stage="runtime_observation_refresh"', $step);
        $this->assertStringContainsString(
            'php -d max_execution_time=0 artisan seo:runtime-probe-scheduled --trigger=manual --scope=private-negative-set --json',
            $step,
        );
        $this->assertStringContainsString('.production_calibration.deploy_revision == $sha', $step);
        $this->assertStringContainsString('runtime_receipt_schema_valid:', $step);
        $this->assertStringContainsString('release_sha_valid:', $step);
        $this->assertStringContainsString('sanitized_boundaries_valid:', $step);
        $this->assertStringContainsString('failure_stage="public_probe_observation"', $step);
        $this->assertStringContainsString('failure_stage="acceptance_complete"', $step);
        $this->assertStringContainsString('probe_exit_code:$probe_exit', $step);
        $this->assertStringContainsString('probe_summary:$probe_summary', $step);
        $this->assertStringContainsString('runtime_error_code', $step);
        $this->assertStringContainsString('runtime_error_fingerprint', $step);
        $this->assertStringContainsString('source_connected=true', $step);
        $this->assertStringContainsString('length == 3', $step);
        $this->assertStringContainsString(
            '["l1_mbti_intj_a_en","l2_big_five_hub_en","l3_career_industries_en"]',
            $step,
        );
        $this->assertStringContainsString('((keys | sort) ==', $step);
        $this->assertStringContainsString('probe_now_epoch - 1800', $step);
        foreach (['receipt_readback', 'notification_delivery', 'notification_deduplication', 'acceptance_complete'] as $stage) {
            $this->assertStringContainsString('failure_stage="'.$stage.'"', $step);
        }
        $this->assertStringNotContainsString('.ok == true and .scope', $step);
        $this->assertStringNotContainsString('.items | all(.ok == true', $step);
        $this->assertMatchesRegularExpression(
            '/abort_acceptance\(\).*?write_hold_receipt.*?acceptance-abort/s',
            $step,
        );
        $this->assertLessThan(
            strpos($step, 'for mission in'),
            strpos($step, 'failure_stage="sitemap_observation_refresh"'),
        );

        $productionStart = strpos($workflow, 'name: Run first production controlled acceptance');
        $productionEnd = strpos($workflow, 'name: Record compatible carry or HOLD receipt', $productionStart ?: 0);
        $this->assertIsInt($productionStart);
        $this->assertIsInt($productionEnd);
        $productionStep = substr($workflow, $productionStart, $productionEnd - $productionStart);
        $this->assertStringContainsString(
            '-o ServerAliveInterval=15 -o ServerAliveCountMax=8',
            $productionStep,
        );
        $this->assertStringContainsString(
            'seo:warm-sitemap-source-cache --refresh-if-changed --json',
            $productionStep,
        );
        $this->assertStringContainsString(
            'php -d max_execution_time=0 artisan seo:runtime-probe-scheduled --trigger=manual --scope=private-negative-set --json',
            $productionStep,
        );
        $this->assertLessThan(
            strpos($productionStep, 'for mission in'),
            strpos($productionStep, 'sitemap_refresh='),
        );
    }

    /** @return array<string,mixed> */
    private function manifest(string $sourceSha, string $productionSha, string $state = 'ACTIVE_READ_ONLY'): array
    {
        $vector = app(RuntimeCapabilitySnapshotBuilder::class)->snapshot()['version_vector'];
        $artifact = 'sha256:'.str_repeat('c', 64);

        return [
            'schema_version' => 'seo.platform12_a08_activation.v2',
            'repository' => 'fermatmind/fap-api',
            'activation_basis' => 'A08_SCOPED_READ_ONLY_ACCEPTANCE',
            'activation_state' => $state,
            'bound_production_sha' => $productionSha,
            'validation' => [
                'ci' => ['repository' => 'fermatmind/fap-api', 'workflow_name' => 'CI',
                    'workflow_path' => '.github/workflows/ci.yml', 'head_branch' => 'main', 'event' => 'push',
                    'sha' => $productionSha, 'status' => 'success', 'run_id' => 11, 'run_attempt' => 1,
                    'artifact_digest' => $artifact],
                'deploy' => ['repository' => 'fermatmind/fap-api', 'workflow_name' => 'Deploy',
                    'workflow_path' => '.github/workflows/deploy.yml', 'head_branch' => 'main', 'event' => 'workflow_run',
                    'sha' => $productionSha, 'status' => 'success', 'run_id' => 12, 'run_attempt' => 1,
                    'artifact_digest' => $artifact],
                'staging_acceptance' => ['sha' => $productionSha, 'status' => 'pass', 'source_connected' => true,
                    'mission_count' => 3, 'trigger_mode' => 'controlled_acceptance', 'natural_slot_receipt' => false,
                    'notification_delivery_verified' => true, 'pause_resume_verified' => true,
                    'receipt_to_ui_verified' => true,
                    'deploy_run_id' => 12, 'deploy_run_attempt' => 1, 'artifact_digest' => $artifact],
                'production_smoke' => ['sha' => $productionSha, 'status' => 'pass', 'deploy_run_id' => 12,
                    'deploy_run_attempt' => 1, 'artifact_digest' => $artifact],
                'production_controlled_acceptance' => $state === 'ACTIVE_READ_ONLY'
                    ? ['status' => 'pass', 'source_connected' => true, 'mission_count' => 3,
                        'enabled_daily_missions' => 3, 'notification_configuration_verified' => true,
                        'receipt_to_ui_verified' => true,
                        'receipt_hashes' => array_fill(0, 3, str_repeat('f', 64))]
                    : ['status' => 'pending', 'source_connected' => false, 'mission_count' => 0,
                        'enabled_daily_missions' => 0, 'notification_configuration_verified' => false,
                        'receipt_to_ui_verified' => false, 'receipt_hashes' => []],
            ],
            'compatibility' => ['mode' => $sourceSha === $productionSha ? 'exact_sha' : 'compatible_descendant',
                'source_sha' => $sourceSha, 'bound_sha' => $productionSha,
                'fingerprint' => ['scope_version' => 'seo-council-a08-runtime.v2',
                    'sha256' => str_repeat('e', 64), 'file_count' => 100]],
            'runtime' => ['version_vector' => $vector,
                'version_vector_hash' => app(SeoRegistryHasher::class)->hash($vector)],
            'permissions' => ['model_runtime_enabled' => false, 'tool_broker_enabled' => false,
                'post12_agent_write_enabled' => false, 'L2' => 'artifact_only', 'L3' => false, 'L4' => false,
                'cms_agent_write' => false, 'publish' => false, 'canonical_write' => false, 'robots_write' => false,
                'url_truth_write' => false, 'search_submission' => false, 'business_write_enabled' => false,
                'active_action_manifest_count' => 0, 'trusted_signing_key_count' => 0],
            'measurement' => ['day_28_started' => false, 'baseline_state' => 'MEASUREMENT_BASELINE_HOLD',
                'efficiency_claim_allowed' => false],
        ];
    }

    private function write(array $manifest): void
    {
        $bytes = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->directory.'/activation.json', $bytes);
        file_put_contents($this->directory.'/activation.json.sha256', hash('sha256', $bytes)."\n");
    }
}
