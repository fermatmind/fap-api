<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SeoPlatform11ALegacyConvergenceTest extends TestCase
{
    private const V2_FILE_SHA256 = '34c0f0df4e541a23c6de0a1758b190af53cd0ab0e0e8e5396b1a1678c39cf3d3';

    private const REGISTRY_HASH = 'b02b6edd816b75b42582468e5bc3aa2c9cd0060149825d1fdc6131cf71d73791';

    public function test_legacy_cli_scheduler_and_workflow_entrypoints_are_absent(): void
    {
        $artisanList = $this->runProcess(['php', 'artisan', 'list', '--raw', '--no-ansi']);
        $scheduleList = $this->runProcess(['php', 'artisan', 'schedule:list', '--json', '--no-ansi']);

        $this->assertStringNotContainsString('seo-agent:', $artisanList);
        $this->assertStringNotContainsString('seo-agent:', $scheduleList);

        foreach (glob(base_path('../.github/workflows/*.yml')) ?: [] as $workflow) {
            $this->assertStringNotContainsString('seo-agent:', (string) file_get_contents($workflow), $workflow);
        }
    }

    public function test_all_legacy_wrappers_are_retired_and_have_dispositions(): void
    {
        $files = glob(app_path('Console/Commands/SeoAgent*.php')) ?: [];
        sort($files);
        $this->assertCount(35, $files);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $this->assertStringContainsString('extends RetiredSeoAgentCommand', $source, $file);
        }

        $manifest = $this->jsonFile(base_path('docs/seo/generated/seo-platform-11a-authority-supersession.v1.json'));
        $this->assertSame(app(SeoRegistryHasher::class)->hashWithout($manifest, 'manifest_hash'), $manifest['manifest_hash']);
        $this->assertSame(35, $manifest['legacy_seo_agent_cli_count']);
        $this->assertCount(35, $manifest['legacy_seo_agent_cli_dispositions']);
        $this->assertFalse($manifest['runtime_created']);
        $this->assertSame(0, $manifest['model_calls_performed']);
        $this->assertSame(0, $manifest['cms_writes']);
        $this->assertSame(0, $manifest['seo_data_writes']);
        $this->assertSame(0, $manifest['search_submissions']);
        $this->assertSame(0, $manifest['production_data_writes']);

        $runtimeFiles = array_merge(
            $this->phpFiles(app_path()),
            $this->phpFiles(base_path('routes')),
        );
        foreach ($runtimeFiles as $runtimeFile) {
            if (preg_match('#/Console/Commands/(?:SeoAgent[^/]+|RetiredSeoAgentCommand)\.php$#', $runtimeFile) === 1) {
                continue;
            }
            $this->assertStringNotContainsString('seo-agent:', (string) file_get_contents($runtimeFile), $runtimeFile);
        }
    }

    public function test_inventory_v2_has_full_classification_and_required_cross_repository_counts(): void
    {
        $path = base_path('docs/seo/generated/seo-platform-11a-inventory.v2.json');
        $inventory = $this->jsonFile($path);
        $summary = $inventory['summary'];

        $this->assertSame(self::V2_FILE_SHA256, hash_file('sha256', $path));
        $this->assertSame('frozen', $inventory['status']);
        $this->assertSame(app(SeoRegistryHasher::class)->hashWithout($inventory, 'inventory_hash'), $inventory['inventory_hash']);
        $this->assertSame(100, $summary['inventory_coverage_percent']);
        $this->assertSame(0, $summary['unclassified_count']);
        $this->assertSame(0, $summary['missing_disposition_count']);
        $this->assertSame(0, $summary['duplicate_asset_id_count']);
        $this->assertSame(0, $summary['unknown_authority_critical_count']);
        $this->assertSame([
            'api_skills' => 18,
            'api_profiles' => 2,
            'api_seo_agent_commands' => 35,
            'api_seo_agent_services' => 5,
            'api_workflows' => 4,
            'web_skills' => 13,
            'web_profiles' => 1,
            'web_seo_scripts' => 138,
            'web_workflows' => 4,
        ], $inventory['baseline_counts']);
        $this->assertSame(count($inventory['records']), $summary['inventory_record_count']);
        $this->assertSame(count($inventory['paths_manifest']), $summary['path_manifest_count']);

        $allowedClassifications = ['active_agent', 'bounded_capability', 'deterministic_tool', 'review_mode', 'contract_only', 'product_domain_out_of_seo_scope', 'historical_superseded', 'retire_candidate'];
        $allowedEvidenceStates = ['verified', 'observed', 'inferred', 'unknown', 'blocked'];
        $requiredFields = ['asset_id', 'repository', 'path', 'path_glob', 'members', 'members_hash', 'asset_type', 'entrypoints', 'callers', 'input', 'output', 'model_invocation', 'database_writes', 'filesystem_writes', 'external_egress', 'scheduler', 'workflow', 'authority_source', 'classification', 'disposition', 'replacement', 'evidence_state', 'evidence_refs'];

        foreach ($inventory['records'] as $record) {
            $this->assertSame([], array_diff($requiredFields, array_keys($record)), $record['asset_id']);
            $this->assertContains($record['classification'], $allowedClassifications);
            $this->assertContains($record['evidence_state'], $allowedEvidenceStates);
        }

        $recordsById = collect($inventory['records'])->keyBy('asset_id');
        foreach (['mbti_result_page', 'big_five_result_page', 'riasec_result_page', 'iq_raven_result_page', 'eq60_result_page', 'enneagram_result_page'] as $roleId) {
            $record = $recordsById['fap-web.legacy-agent-os-role.'.$roleId];
            $this->assertSame('product_domain_out_of_seo_scope', $record['classification']);
        }
    }

    public function test_inventory_v3_recomputes_the_current_frozen_reconciliation_without_hardcoded_totals(): void
    {
        $hasher = app(SeoRegistryHasher::class);
        $inventory = $this->jsonFile(base_path('docs/seo/generated/seo-platform-11a-inventory.v3.json'));
        $summary = $inventory['summary'];

        $this->assertSame('seo-platform-11a-inventory.v3', $inventory['schema_version']);
        $this->assertSame('3.0.0', $inventory['inventory_version']);
        $this->assertSame('frozen', $inventory['status']);
        $this->assertSame($hasher->hashWithout($inventory, 'inventory_self_hash'), $inventory['inventory_self_hash']);
        $this->assertSame(self::V2_FILE_SHA256, $inventory['historical_baseline']['file_sha256']);
        $this->assertSame('seo-platform-11a-inventory.v2', $inventory['historical_baseline']['schema_version']);
        $this->assertSame('historical_snapshot_generator', $inventory['historical_baseline']['generator']['classification']);

        $this->assertSame(count($inventory['records']), $summary['inventory_record_count']);
        $this->assertSame(count($inventory['paths_manifest']), $summary['path_manifest_count']);
        $this->assertGreaterThan(652, $summary['inventory_record_count']);
        $this->assertGreaterThan(831, $summary['path_manifest_count']);
        $this->assertSame(0, $summary['unclassified_count']);
        $this->assertSame(0, $summary['missing_disposition_count']);
        $this->assertSame(0, $summary['duplicate_asset_id_count']);
        $this->assertSame(0, $summary['missing_paths']);
        $this->assertSame(0, $summary['unexpected_paths']);
        $this->assertSame(0, $summary['hash_drift']);
        $this->assertSame(139, $inventory['observed_counts']['web_seo_scripts']);

        foreach (['fap-api', 'fap-web'] as $repository) {
            $paths = array_values(array_map(
                static fn (array $row): string => $row['path'],
                array_filter($inventory['paths_manifest'], static fn (array $row): bool => $row['repository'] === $repository),
            ));
            sort($paths, SORT_STRING);
            $this->assertSame(count($paths), count(array_unique($paths)));
            $this->assertSame($hasher->hash($paths), $inventory['path_set_hashes'][$repository]);
        }

        $assetIds = array_column($inventory['records'], 'asset_id');
        $this->assertSame(count($assetIds), count(array_unique($assetIds)));
        foreach ($inventory['records'] as $record) {
            $this->assertNotSame('', $record['disposition']);
        }

        $requiredSix = array_column($inventory['reconciliation']['required_six_omitted_paths'], 'path');
        $this->assertSame([
            '.agents/skills/public-profile-seo-asset-factory/authority-supersession.v1.json',
            'docs/result-page-agents/seo-authority-supersession.v1.json',
            'docs/seo/SEO_CODE_CHANGE_ARTIFACT.md',
            'docs/seo/seo-platform-11a-authority-supersession.v1.json',
            'scripts/seo/generate-seo-code-change-artifact.mjs',
            'tests/contracts/seo-platform-11a-authority-convergence.contract.test.ts',
        ], $requiredSix);
        $this->assertCount(9, $inventory['reconciliation']['required_nine_refreshed_paths']);
        $this->assertGreaterThanOrEqual(9, $inventory['reconciliation']['refreshed_v2_path_count']);

        $this->assertSame(self::REGISTRY_HASH, $inventory['registry_freeze']['registry_hash']);
        $this->assertSame(9, $inventory['registry_freeze']['role_count']);
        $this->assertSame(20, $inventory['registry_freeze']['capability_count']);
        $this->assertSame(1, $inventory['registry_freeze']['unique_orchestrator_count']);
        $this->assertSame(1, $inventory['registry_freeze']['unique_career_agent_count']);

        $this->assertFalse($inventory['fixed_boundaries']['runtime_created']);
        $this->assertFalse($inventory['fixed_boundaries']['runtime_model_invocation_enabled']);
        foreach (['model_calls_performed', 'cms_writes', 'seo_data_writes', 'search_submissions', 'production_data_writes', 'delegated_executions'] as $field) {
            $this->assertSame(0, $inventory['fixed_boundaries'][$field]);
        }
        $this->assertSame('dormant_not_authorized', $inventory['fixed_boundaries']['l4_state']);
    }

    public function test_no_agent_framework_dependency_was_added(): void
    {
        $composer = $this->jsonFile(base_path('composer.lock'));
        $names = array_column(array_merge($composer['packages'], $composer['packages-dev']), 'name');
        $this->assertSame([], array_values(array_filter($names, static fn (string $name): bool => preg_match('/(?:crewai|langchain|autogen)/i', $name) === 1)));
    }

    private function runProcess(array $command): string
    {
        $process = new Process($command, base_path(), ['APP_ENV' => 'local']);
        $process->setTimeout(30);
        $process->mustRun();

        return $process->getOutput().$process->getErrorOutput();
    }

    /** @return list<string> */
    private function phpFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** @return array<string, mixed> */
    private function jsonFile(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
