<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class Platform12ContractRegistry
{
    public function __construct(private readonly SeoRegistryHasher $hasher) {}

    /** @return array<string, mixed> */
    public function startReceiptSchema(): array
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'schema_id' => 'seo.platform12_start_receipt.v1',
            'schema_version' => '1.0.0',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'receipt_version', 'production_sha', 'foundation_state', 'foundation_build_allowed',
                'runtime_activation_allowed', 'runtime_activation_state', 'nightly_state',
                'measurement_baseline_state', 'dependency_refs', 'write_guards',
                'SEO-PLATFORM-11', 'SEO-PLATFORM-12', 'receipt_hash',
            ],
        ];
        $schema['schema_hash'] = $this->hasher->hash($schema);

        return $schema;
    }

    /** @return array<string, array<string, mixed>> */
    public function artifacts(): array
    {
        return [
            'resources/seo-agent/council/platform12/schemas/seo.platform12_start_receipt.v1.schema.json' => $this->startReceiptSchema(),
        ];
    }
}
