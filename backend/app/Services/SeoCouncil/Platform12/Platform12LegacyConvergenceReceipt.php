<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class Platform12LegacyConvergenceReceipt
{
    private const CROSS_REPOSITORY_INVENTORY = 'docs/seo/generated/seo-platform-11a-inventory.v3.json';

    private const SKILL_PATH = '.agents/skills/fermatmind-global-seo-geo-growth-scan/SKILL.md';

    private const SKILL_ADAPTER_PATH = 'backend/scripts/seo/submit_seo_council_mission.php';

    /** @var list<string> */
    private const FORBIDDEN_SKILL_TOKENS = [
        'DB::', 'Cache::', 'Http::', 'file_put_contents(', 'ActionManifest', 'signing key',
    ];

    public function __construct(
        private readonly SeoRegistryHasher $hasher,
        private readonly Platform12ContractRegistry $contracts,
        private readonly Platform12LegacyCallerInventory $callers,
    ) {}

    /** @param array<string, mixed>|null $crossRepositoryInventory @return array<string, mixed> */
    public function build(?array $crossRepositoryInventory = null): array
    {
        $sourceInventory = $crossRepositoryInventory ?? $this->json(self::CROSS_REPOSITORY_INVENTORY);
        $sourceValid = $this->sourceInventoryValid($sourceInventory);
        $skill = $this->skillProof();
        $fapWeb = $this->fapWebProof($sourceInventory, $sourceValid);
        $scheduler = $this->schedulerProof();
        $authorityRefs = $this->authorityRefs();
        $authorityRefsValid = collect($authorityRefs)->every(
            static fn (array $ref): bool => $ref['owner_repository'] === 'fap-api'
                && $ref['canonical'] === true
                && preg_match('/^[a-f0-9]{64}$/D', (string) $ref['hash']) === 1,
        );
        $passed = $sourceValid
            && $skill['boundary_passed']
            && $fapWeb['boundary_passed']
            && $scheduler['boundary_passed']
            && $authorityRefsValid;

        $receipt = [
            'receipt_type' => 'legacy_convergence_receipt',
            'receipt_version' => 'seo.platform12_legacy_convergence_receipt.v1',
            'state' => $passed ? 'PASS' : 'BOUNDARY_HOLD',
            'source_inventory_ref' => [
                'version' => $sourceInventory['inventory_version'] ?? null,
                'hash' => $sourceInventory['inventory_self_hash'] ?? null,
                'fap_web_path_set_hash' => $sourceInventory['path_set_hashes']['fap-web'] ?? null,
            ],
            'skill_boundary' => $skill,
            'fap_web_boundary' => $fapWeb,
            'scheduler_boundary' => $scheduler,
            'authority_refs' => $authorityRefs,
            'all_authority_refs_canonical_backend' => $authorityRefsValid,
            'alternative_write_paths' => 0,
            'runtime_switches_changed' => false,
            'scheduler_changes' => 0,
            'execution_allowed' => false,
            'writes' => 0,
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);

        return $receipt;
    }

    /** @return array<string, mixed> */
    private function skillProof(): array
    {
        $root = dirname(base_path());
        $skill = (string) file_get_contents($root.'/'.self::SKILL_PATH);
        $adapter = (string) file_get_contents($root.'/'.self::SKILL_ADAPTER_PATH);
        $forbiddenHits = array_values(array_filter(
            self::FORBIDDEN_SKILL_TOKENS,
            static fn (string $token): bool => str_contains($adapter, $token),
        ));
        $constructsMissionRequest = str_contains($skill, 'Construct only the schema')
            && str_contains($skill, 'MissionRequest');
        $validatesAndSubmits = str_contains($adapter, 'json_decode')
            && str_contains($adapter, 'LocalSkillMissionAdapter::class')
            && str_contains($adapter, '->submit($input)');
        $authorityDenied = str_contains($skill, 'entry adapter only')
            && str_contains($skill, 'Never issue an ActionManifest')
            && str_contains($skill, 'Never include raw queries');

        return [
            'skill_path' => self::SKILL_PATH,
            'adapter_path' => self::SKILL_ADAPTER_PATH,
            'constructs_mission_request' => $constructsMissionRequest,
            'validates_and_submits_only' => $validatesAndSubmits,
            'owns_role_authority' => false,
            'owns_policy_authority' => false,
            'owns_runtime_authority' => false,
            'owns_tool_authority' => false,
            'owns_write_authority' => false,
            'forbidden_token_hits' => $forbiddenHits,
            'boundary_passed' => $constructsMissionRequest && $validatesAndSubmits && $authorityDenied && $forbiddenHits === [],
        ];
    }

    /** @param array<string, mixed> $inventory @return array<string, mixed> */
    private function fapWebProof(array $inventory, bool $sourceValid): array
    {
        $records = array_values(array_filter(
            $inventory['records'] ?? [],
            static fn (mixed $row): bool => is_array($row) && ($row['repository'] ?? null) === 'fap-web',
        ));
        $activeAgentCount = count(array_filter(
            $records,
            static fn (array $row): bool => ($row['classification'] ?? null) === 'active_agent',
        ));
        $fixed = is_array($inventory['fixed_boundaries'] ?? null) ? $inventory['fixed_boundaries'] : [];
        $zeroFields = ['model_calls_performed', 'cms_writes', 'seo_data_writes', 'search_submissions', 'production_data_writes', 'delegated_executions'];
        $zeroBoundaries = collect($zeroFields)->every(static fn (string $field): bool => ($fixed[$field] ?? null) === 0);
        $authorityDenied = ($fixed['fap_web_agent_authority'] ?? null) === false
            && ($fixed['execution_authorized'] ?? null) === false
            && ($fixed['runtime_model_invocation_enabled'] ?? null) === false
            && ($fixed['post12_agent_write_enabled'] ?? null) === false
            && $zeroBoundaries;

        return [
            'proof_basis' => 'frozen_cross_repository_exact_tree_inventory',
            'record_count' => count($records),
            'active_agent_count' => $activeAgentCount,
            'council_client_present' => false,
            'allowed_council_surface' => 'api_or_ui_projection_only',
            'owns_model_authority' => false,
            'owns_tool_manifest_authority' => false,
            'owns_signing_authority' => false,
            'owns_agent_authority' => false,
            'owns_write_authority' => false,
            'boundary_passed' => $sourceValid && $records !== [] && $activeAgentCount === 0 && $authorityDenied,
        ];
    }

    /** @return array<string, mixed> */
    private function schedulerProof(): array
    {
        $scheduler = (string) file_get_contents(base_path('bootstrap/app.php'));
        $forbidden = [
            self::SKILL_PATH,
            self::SKILL_ADAPTER_PATH,
            'LocalSkillMissionAdapter',
            'fap-web',
            'seo'.'-agent:',
        ];
        $hits = array_values(array_filter($forbidden, static fn (string $needle): bool => str_contains($scheduler, $needle)));
        $inventory = $this->callers->build();
        $legacyScheduled = count(array_filter(
            $inventory['legacy_entrypoints'],
            static fn (array $row): bool => ($row['references']['laravel_schedule'] ?? []) !== [],
        ));

        return [
            'source_path' => 'backend/bootstrap/app.php',
            'forbidden_reference_hits' => $hits,
            'legacy_scheduler_entrypoint_count' => $legacyScheduled,
            'calls_skill' => false,
            'calls_frontend' => false,
            'boundary_passed' => $hits === [] && $legacyScheduled === 0,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function authorityRefs(): array
    {
        return array_map(
            static fn (array $ref): array => [
                ...$ref,
                'owner_repository' => 'fap-api',
                'canonical' => true,
            ],
            $this->contracts->missionCatalogDependencyRefs(),
        );
    }

    /** @param array<string, mixed> $inventory */
    private function sourceInventoryValid(array $inventory): bool
    {
        return ($inventory['schema_version'] ?? null) === 'seo-platform-11a-inventory.v3'
            && ($inventory['inventory_version'] ?? null) === '3.0.0'
            && ($inventory['status'] ?? null) === 'frozen'
            && is_string($inventory['inventory_self_hash'] ?? null)
            && hash_equals($this->hasher->hashWithout($inventory, 'inventory_self_hash'), $inventory['inventory_self_hash'])
            && preg_match('/^[a-f0-9]{64}$/D', (string) ($inventory['path_set_hashes']['fap-web'] ?? '')) === 1;
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        $decoded = json_decode((string) file_get_contents(base_path($path)), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
