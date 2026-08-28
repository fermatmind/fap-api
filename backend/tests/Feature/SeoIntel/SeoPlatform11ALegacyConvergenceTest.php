<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SeoPlatform11ALegacyConvergenceTest extends TestCase
{
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
        $inventory = $this->jsonFile(base_path('docs/seo/generated/seo-platform-11a-inventory.v2.json'));
        $summary = $inventory['summary'];

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
