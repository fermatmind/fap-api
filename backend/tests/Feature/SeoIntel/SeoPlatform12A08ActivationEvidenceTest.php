<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Governance\RuntimeCapabilitySnapshotBuilder;
use App\Services\SeoCouncil\Platform12\Platform12DailyMissionSet;
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
        // Keep the already-resolved in-memory store while exercising the
        // production-only shared-store admission branch.
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

    public function test_scoped_evidence_does_not_authorize_or_unpause_and_source_acceptance_is_separate(): void
    {
        $runtime = app(Platform12RuntimeControl::class);
        $this->write($this->manifest());
        $this->assertSame('READY', $runtime->prerequisite());
        $this->assertSame('PAUSED', $runtime->status()['state']);
        $this->assertSame([], $runtime->status()['selected_missions']);
        $this->assertSame('MISSION_SELECTION_DENIED', $runtime->change(false)['state']);
        $result = $runtime->change(false, [Platform12DailyMissionSet::IDS[0]]);
        $this->assertTrue($result['missions'][Platform12DailyMissionSet::IDS[0]]['acceptance_allowed']);
        $this->assertSame([], $result['effective_mission_ids']);
        $this->assertFalse($result['missions'][Platform12DailyMissionSet::IDS[1]]['acceptance_allowed']);
        $this->assertSame('PAUSED', $runtime->change(true)['state']);
        $generation = $runtime->status()['generation'];
        $this->write($this->manifest());
        $this->assertSame($generation, $runtime->status()['generation']);
        $this->assertSame('PAUSED', $runtime->status()['state']);
    }

    public function test_hash_sha_scope_version_and_public_boundary_fail_closed(): void
    {
        $runtime = app(Platform12RuntimeControl::class);
        foreach ([['schema_version', 'seo.platform12_a08_activation.v1', 'LEGACY_EVIDENCE_NOT_AUTHORIZATION'],
            ['bound_production_sha', str_repeat('b', 40), 'RELEASE_SHA_HOLD'],
            ['validation.public_checks.check_scope', 'daily_operations', 'PUBLIC_SCOPED_EVIDENCE_HOLD'],
            ['validation.public_checks.check_scope', 'weekly_full_checks', 'PUBLIC_SCOPED_EVIDENCE_HOLD'],
            ['runtime.version_vector.policy', str_repeat('0', 64), 'PUBLIC_VERSION_VECTOR_HOLD'],
            ['validation.production.completed_job', false, 'DEPLOYMENT_SMOKE_EVIDENCE_HOLD']] as [$key, $value, $reason]) {
            $manifest = $this->manifest();
            data_set($manifest, $key, $value);
            $this->write($manifest);
            $this->assertSame($reason, $runtime->prerequisite());
            $this->assertSame([], $runtime->status()['effective_mission_ids']);
        }
        $this->write($this->manifest());
        file_put_contents($this->directory.'/activation.json.sha256', str_repeat('f', 64));
        $this->assertSame('ACTIVATION_EVIDENCE_CORRUPT', $runtime->prerequisite());
    }

    public function test_mission_failure_does_not_block_other_missions_or_partially_update_selection(): void
    {
        $manifest = $this->manifest();
        unset($manifest['missions'][Platform12DailyMissionSet::IDS[2]]);
        $this->write($manifest);
        $runtime = app(Platform12RuntimeControl::class);
        $first = $runtime->change(false, [Platform12DailyMissionSet::IDS[0]]);
        $this->assertSame('ACTIVE_READ_ONLY', $first['state']);
        $runtime->change(false, Platform12DailyMissionSet::IDS);
        $this->assertSame($first['generation'], $runtime->status()['generation']);
        $this->assertSame([Platform12DailyMissionSet::IDS[0]], $runtime->status()['selected_missions']);
        foreach ([[], ['*'], ['unknown'], [Platform12DailyMissionSet::IDS[0], Platform12DailyMissionSet::IDS[0]]] as $ids) {
            $this->assertSame('MISSION_SELECTION_DENIED', $runtime->change(false, $ids)['state']);
        }
    }

    public function test_new_source_evidence_never_starts_natural_scheduling_until_explicit_selection(): void
    {
        $manifest = $this->manifest();
        $id = Platform12DailyMissionSet::IDS[0];
        $this->write($manifest);
        $runtime = app(Platform12RuntimeControl::class);
        $runtime->change(false, [$id]);
        $before = $runtime->status();
        $this->assertNull($before['missions'][$id]['first_enabled_at']);
        $manifest['missions'][$id]['source_acceptance'] = [
            'status' => 'pass', 'stage' => 'controlled_source_acceptance', 'environment' => 'production', 'mission_id' => $id,
            'bound_sha' => $this->sha, 'source_sha' => $this->sha, 'receipt_digest' => str_repeat('f', 64),
            'artifact_digest' => 'sha256:'.str_repeat('a', 64), 'fingerprint' => $manifest['missions'][$id]['checks']['fingerprint'],
            'version_vector' => $manifest['runtime']['version_vector'],
        ];
        $this->write($manifest);
        $this->assertSame(0, $runtime->status()['effective_enabled_missions']);
        $this->assertSame($before['generation'], $runtime->status()['generation']);
        $this->assertSame(0, $runtime->change(false, [$id])['effective_enabled_missions']);
        $manifest['missions'][$id]['end_to_end_acceptance'] = [
            'status' => 'pass', 'stage' => 'controlled_mission_terminal_and_ui', 'environment' => 'production',
            'mission_id' => $id, 'bound_sha' => $this->sha, 'source_receipt_digest' => str_repeat('f', 64),
            'fingerprint' => $manifest['missions'][$id]['checks']['fingerprint'],
            'version_vector' => $manifest['runtime']['version_vector'], 'terminal_committed' => true,
            'receipt_to_ui_verified' => true, 'runtime_boundaries_verified' => true,
            'receipt_hash' => str_repeat('b', 64), 'receipt_digest' => str_repeat('c', 64),
            'artifact_digest' => 'sha256:'.str_repeat('d', 64),
        ];
        $this->write($manifest);
        $after = $runtime->change(false, [$id]);
        $this->assertSame(1, $after['effective_enabled_missions']);
        $this->assertNotNull($after['missions'][$id]['first_enabled_at']);
        $manifest['missions'][$id]['checks']['scope_id'] = Platform12DailyMissionSet::IDS[2];
        $this->write($manifest);
        $this->assertSame(0, $runtime->status()['effective_enabled_missions']);
    }

    private function manifest(): array
    {
        $vector = app(RuntimeCapabilitySnapshotBuilder::class)->snapshot()['version_vector'];
        $artifact = 'sha256:'.str_repeat('c', 64);
        $check = ['check_scope' => 'a08_scoped_checks', 'sha' => $this->sha, 'scope_version' => 'seo-council-a08-dependencies.v2',
            'status' => 'pass', 'fingerprint' => str_repeat('d', 64), 'result_digest' => str_repeat('e', 64), 'scope_id' => 'public', 'tests' => \App\Services\SeoCouncil\Platform12\Platform12ActivationEvidence::REQUIRED_TESTS['public']];
        $deploy = ['sha' => $this->sha, 'check_scope' => 'deployment_smoke_and_readonly_state', 'status' => 'pass',
            'completed_job' => true, 'run_id' => 12, 'artifact_digest' => $artifact, 'pause_preserved' => true, 'business_guards_closed' => true];

        return ['schema_version' => 'seo.platform12_a08_activation.v2', 'repository' => 'fermatmind/fap-api', 'bound_production_sha' => $this->sha,
            'validation' => ['public_checks' => $check,
                'ci' => ['repository' => 'fermatmind/fap-api', 'workflow_name' => 'CI', 'workflow_path' => '.github/workflows/ci.yml',
                    'head_branch' => 'main', 'event' => 'push', 'sha' => $this->sha, 'status' => 'success', 'run_id' => 11, 'run_attempt' => 1, 'artifact_digest' => $artifact],
                'staging' => [...$deploy, 'environment' => 'staging'], 'production' => [...$deploy, 'environment' => 'production']],
            'missions' => array_combine(Platform12DailyMissionSet::IDS, array_map(static fn (string $id): array => ['checks' => [...$check, 'scope_id' => $id, 'tests' => \App\Services\SeoCouncil\Platform12\Platform12ActivationEvidence::REQUIRED_TESTS[$id]], 'source_acceptance' => ['status' => 'pending']], Platform12DailyMissionSet::IDS)),
            'runtime' => ['version_vector' => $vector, 'version_vector_hash' => app(SeoRegistryHasher::class)->hash($vector)],
            'permissions' => array_fill_keys(['model_calls', 'tool_broker', 'cms_writes', 'publish_writes', 'canonical_writes', 'robots_writes', 'url_truth_writes', 'search_submission', 'business_writes'], false),
            'measurement' => ['day_28_started' => false, 'efficiency_claim_allowed' => false]];
    }

    private function write(array $manifest): void
    {
        $bytes = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->directory.'/activation.json', $bytes);
        file_put_contents($this->directory.'/activation.json.sha256', hash('sha256', $bytes)."\n");
    }
}
