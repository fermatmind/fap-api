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

    public function test_exact_full_evidence_allows_activation_and_forged_or_daily_evidence_is_rejected(): void
    {
        $manifest = $this->manifest($this->sha, $this->sha);
        $this->write($manifest);
        $runtime = app(Platform12RuntimeControl::class);
        $this->assertSame('READY', $runtime->prerequisite());
        $this->assertSame('ACTIVE_READ_ONLY', $runtime->change(false)['state']);

        data_set($manifest, 'validation.nightly.check_scope', 'daily_operations');
        $this->write($manifest);
        $this->assertSame('FULL_NIGHTLY_EVIDENCE_HOLD', $runtime->prerequisite());

        $manifest = $this->manifest($this->sha, $this->sha);
        data_set($manifest, 'validation.nightly.artifact_digest', str_repeat('b', 64));
        $this->write($manifest);
        $this->assertSame('FULL_NIGHTLY_EVIDENCE_HOLD', $runtime->prerequisite());

        $manifest = $this->manifest($this->sha, $this->sha);
        data_set($manifest, 'runtime.version_vector.policy', str_repeat('0', 64));
        $this->write($manifest);
        $this->assertSame('FULL_NIGHTLY_EVIDENCE_HOLD', $runtime->prerequisite());
    }

    public function test_compatible_descendant_rebinds_release_without_rewriting_nightly_and_preserves_pause(): void
    {
        $runtime = app(Platform12RuntimeControl::class);
        $this->write($this->manifest($this->sha, $this->sha));
        $active = $runtime->change(false);
        $this->assertSame('PAUSED', $runtime->change(true)['state']);

        $next = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        file_put_contents($this->directory.'/REVISION', $next."\n");
        $this->write($this->manifest($this->sha, $next));
        $status = $runtime->status();
        $this->assertSame('PAUSED', $status['state']);
        $this->assertSame('PAUSED', $status['pause_intent']);
        $this->assertSame($active['activated_at'], $status['activated_at']);
        $this->assertSame($this->sha, $status['activation_source_sha']);
        $this->assertSame($next, $status['activation_bound_sha']);
    }

    /** @return array<string,mixed> */
    private function manifest(string $nightlySha, string $productionSha): array
    {
        $vector = app(RuntimeCapabilitySnapshotBuilder::class)->snapshot()['version_vector'];
        $artifact = 'sha256:'.str_repeat('c', 64);
        $domains = [];
        foreach (['authority_contract', 'full_phpunit', 'dependency_audit', 'workflow_contracts', 'security_scan'] as $domain) {
            $domains[$domain] = ['required' => true, 'result' => 'success'];
        }

        return [
            'schema_version' => 'seo.platform12_a08_activation.v1',
            'repository' => 'fermatmind/fap-api',
            'bound_production_sha' => $productionSha,
            'validation' => [
                'nightly' => ['repository' => 'fermatmind/fap-api', 'workflow_name' => 'Nightly',
                    'workflow_path' => '.github/workflows/nightly.yml', 'head_branch' => 'main', 'event' => 'schedule',
                    'run_id' => 10, 'run_attempt' => 1, 'sha' => $nightlySha, 'artifact_digest' => $artifact,
                    'receipt_digest' => str_repeat('d', 64), 'check_scope' => 'weekly_full_checks',
                    'status' => 'pass', 'domains' => $domains],
                'ci' => ['repository' => 'fermatmind/fap-api', 'workflow_name' => 'CI',
                    'workflow_path' => '.github/workflows/ci.yml', 'head_branch' => 'main', 'event' => 'push',
                    'sha' => $productionSha, 'status' => 'success',
                    'run_id' => 11, 'run_attempt' => 1, 'artifact_digest' => $artifact],
                'deploy' => ['repository' => 'fermatmind/fap-api', 'workflow_name' => 'Deploy',
                    'workflow_path' => '.github/workflows/deploy.yml', 'head_branch' => 'main', 'event' => 'workflow_run',
                    'sha' => $productionSha, 'status' => 'success',
                    'run_id' => 12, 'run_attempt' => 1, 'artifact_digest' => $artifact],
                'staging_acceptance' => ['sha' => $nightlySha, 'status' => 'pass', 'mission_count' => 3,
                    'deploy_run_id' => 12, 'deploy_run_attempt' => 1, 'artifact_digest' => $artifact],
            ],
            'compatibility' => ['mode' => $nightlySha === $productionSha ? 'exact_sha' : 'compatible_descendant',
                'source_sha' => $nightlySha, 'bound_sha' => $productionSha,
                'fingerprint' => ['scope_version' => 'seo-council-a08-runtime.v1',
                    'sha256' => str_repeat('e', 64), 'file_count' => 100]],
            'runtime' => ['version_vector' => $vector,
                'version_vector_hash' => app(SeoRegistryHasher::class)->hash($vector)],
            'permissions' => ['model_calls' => false, 'tool_broker' => false, 'cms_writes' => false,
                'publish_writes' => false, 'canonical_writes' => false, 'robots_writes' => false,
                'url_truth_writes' => false, 'search_submission' => false, 'business_writes' => false],
            'measurement' => ['day_28_started' => false, 'efficiency_claim_allowed' => false],
        ];
    }

    private function write(array $manifest): void
    {
        $bytes = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->directory.'/activation.json', $bytes);
        file_put_contents($this->directory.'/activation.json.sha256', hash('sha256', $bytes)."\n");
    }
}
