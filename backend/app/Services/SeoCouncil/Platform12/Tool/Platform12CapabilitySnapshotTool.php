<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Tool;

use App\Services\SeoCouncil\Governance\RuntimeCapabilitySnapshotBuilder;
use InvalidArgumentException;

final class Platform12CapabilitySnapshotTool implements Platform12ReadOnlyTool
{
    public function __construct(private readonly RuntimeCapabilitySnapshotBuilder $snapshots) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function invoke(array $input): array
    {
        if ($input !== []) {
            throw new InvalidArgumentException('TOOL_INPUT_SCHEMA_INVALID');
        }
        $snapshot = $this->snapshots->snapshot();

        return [
            'snapshot_hash' => $snapshot['snapshot_hash'],
            'version_vector_hash' => $snapshot['version_vector_hash'],
            'read_only_runtime_state' => $snapshot['read_only_runtime_state'],
            'changed_dimensions' => $snapshot['changed_dimensions'],
        ];
    }
}
