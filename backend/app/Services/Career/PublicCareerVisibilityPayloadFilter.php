<?php

declare(strict_types=1);

namespace App\Services\Career;

final class PublicCareerVisibilityPayloadFilter
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function launchTier(array $payload): array
    {
        $payload['occupations'] = array_map(
            fn (mixed $row): array => $this->publicLaunchTierRow(is_array($row) ? $row : []),
            array_values(array_filter((array) ($payload['occupations'] ?? []), 'is_array')),
        );

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function lifecycle(array $payload): array
    {
        $payload['occupations'] = array_map(
            fn (mixed $row): array => $this->publicLifecycleRow(is_array($row) ? $row : []),
            array_values(array_filter((array) ($payload['occupations'] ?? []), 'is_array')),
        );

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function readiness(array $payload): array
    {
        $readyRows = array_values(array_filter(
            (array) ($payload['occupations'] ?? []),
            static fn (mixed $row): bool => is_array($row)
                && ($row['status'] ?? null) === 'publish_ready'
                && ($row['index_eligible'] ?? false) === true,
        ));

        $payload['counts'] = [
            'total' => (int) data_get($payload, 'counts.total', 0),
            'publish_ready' => (int) data_get($payload, 'counts.publish_ready', count($readyRows)),
            'not_public' => max(0, (int) data_get($payload, 'counts.total', 0) - (int) data_get($payload, 'counts.publish_ready', count($readyRows))),
        ];
        $payload['occupations'] = array_map(
            fn (mixed $row): array => $this->publicReadinessRow(is_array($row) ? $row : []),
            $readyRows,
        );

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function launchGovernanceClosure(array $payload): array
    {
        return [
            'governance_kind' => $payload['governance_kind'] ?? null,
            'governance_version' => $payload['governance_version'] ?? null,
            'scope' => $payload['scope'] ?? null,
            'counts' => $payload['counts'] ?? [],
            'public_statement' => $payload['public_statement'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function lifecycleOperationalSummary(array $payload): array
    {
        return [
            'summary_kind' => $payload['summary_kind'] ?? null,
            'summary_version' => $payload['summary_version'] ?? null,
            'scope' => $payload['scope'] ?? null,
            'counts' => $payload['counts'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function publicLaunchTierRow(array $row): array
    {
        return [
            'occupation_uuid' => (string) ($row['occupation_uuid'] ?? ''),
            'canonical_slug' => (string) ($row['canonical_slug'] ?? ''),
            'canonical_title_en' => (string) ($row['canonical_title_en'] ?? ''),
            'launch_tier' => (string) ($row['launch_tier'] ?? ''),
            'public_index_state' => (string) ($row['public_index_state'] ?? 'noindex'),
            'index_eligible' => (bool) ($row['index_eligible'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function publicLifecycleRow(array $row): array
    {
        return [
            'occupation_uuid' => (string) ($row['occupation_uuid'] ?? ''),
            'canonical_slug' => (string) ($row['canonical_slug'] ?? ''),
            'canonical_title_en' => (string) ($row['canonical_title_en'] ?? ''),
            'public_index_state' => (string) ($row['public_index_state'] ?? 'noindex'),
            'index_eligible' => (bool) ($row['index_eligible'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function publicReadinessRow(array $row): array
    {
        return [
            'occupation_uuid' => (string) ($row['occupation_uuid'] ?? ''),
            'canonical_slug' => (string) ($row['canonical_slug'] ?? ''),
            'canonical_title_en' => (string) ($row['canonical_title_en'] ?? ''),
            'status' => 'publish_ready',
            'index_state' => (string) ($row['index_state'] ?? 'noindex'),
            'index_eligible' => true,
        ];
    }
}
