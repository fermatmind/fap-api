<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Tool;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class Platform12ToolManifest
{
    public const VERSION = '1.0.0';

    public function __construct(private readonly SeoRegistryHasher $hasher) {}

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        $manifest = [
            'schema_version' => 'seo.platform12_tool_manifest.v1',
            'manifest_id' => 'fermatmind.seo.platform12.readonly_tools',
            'manifest_version' => self::VERSION,
            'runtime_state' => 'FOUNDATION_DISABLED',
            'external_content_policy' => 'EXISTING_GATEWAY_ONLY_NO_MISSION_URL',
            'tools' => [
                $this->definition(
                    'seo.platform12.contract_verify',
                    Platform12ContractVerificationTool::class,
                    ['catalog_ref' => 'closed_reference'],
                    ['catalog_ref_matches' => 'boolean', 'generated_contracts_valid' => 'boolean', 'catalog_ref' => 'closed_reference'],
                ),
                $this->definition(
                    'seo.platform12.capability_snapshot_read',
                    Platform12CapabilitySnapshotTool::class,
                    [],
                    [
                        'snapshot_hash' => 'sha256',
                        'version_vector_hash' => 'sha256',
                        'read_only_runtime_state' => 'lifecycle_enum',
                        'changed_dimensions' => 'version_dimension_list',
                    ],
                ),
            ],
            'prohibited_capabilities' => [
                'shell', 'arbitrary_http', 'cms_write', 'deploy', 'url_truth_write',
                'search_submission', 'peer_delegation', 'all_team_invocation',
            ],
            'production_enabled' => false,
        ];
        $manifest['manifest_hash'] = $this->hasher->hash($manifest);

        return $manifest;
    }

    /** @return array{id:string,version:string,hash:string} */
    public function reference(): array
    {
        $manifest = $this->manifest();

        return [
            'id' => $manifest['manifest_id'],
            'version' => $manifest['manifest_version'],
            'hash' => $manifest['manifest_hash'],
        ];
    }

    /** @return array<string, mixed>|null */
    public function tool(string $toolId, ?string $version = null): ?array
    {
        foreach ($this->manifest()['tools'] as $tool) {
            if ($tool['tool_id'] === $toolId && ($version === null || $tool['tool_version'] === $version)) {
                return $tool;
            }
        }

        return null;
    }

    /** @return array<string, array<string, mixed>> */
    public function artifacts(): array
    {
        return [
            'resources/seo-agent/council/platform12/tools/seo.platform12_tool_manifest.v1.json' => $this->manifest(),
        ];
    }

    /**
     * @param  class-string<Platform12ReadOnlyTool>  $handler
     * @param  array<string, string>  $inputSchema
     * @param  array<string, string>  $outputSchema
     * @return array<string, mixed>
     */
    private function definition(string $toolId, string $handler, array $inputSchema, array $outputSchema): array
    {
        return [
            'tool_id' => $toolId,
            'tool_version' => self::VERSION,
            'handler_class' => $handler,
            'input_schema_hash' => $this->hasher->hash($inputSchema),
            'output_schema_hash' => $this->hasher->hash($outputSchema),
            'timeout_ms' => 250,
            'read_only' => true,
            'internal_only' => true,
            'external_egress' => false,
            'model_invocation' => false,
            'write_permissions' => [],
            'delegation_allowed' => false,
            'all_team_invocation' => false,
        ];
    }
}
