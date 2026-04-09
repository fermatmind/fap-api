<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use RuntimeException;

final class FirstWaveBlockedRegistryReader
{
    public function __construct(
        private readonly FirstWaveManifestReader $manifestReader,
    ) {}

    public function defaultPath(): string
    {
        return base_path('docs/career/first_wave_blocked_registry.json');
    }

    /**
     * @return array{
     *   version:string,
     *   wave_name:string,
     *   scope_source:string,
     *   count_expected:int,
     *   count_actual:int,
     *   items:list<array<string,mixed>>
     * }
     */
    public function read(?string $path = null): array
    {
        $resolved = $path === null || trim($path) === ''
            ? $this->defaultPath()
            : (str_starts_with($path, '/') ? $path : base_path($path));

        if (! is_file($resolved)) {
            throw new RuntimeException(sprintf('First-wave blocked registry not found at [%s].', $resolved));
        }

        $decoded = json_decode((string) file_get_contents($resolved), true);
        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('First-wave blocked registry is not valid JSON: [%s].', $resolved));
        }

        foreach (['version', 'wave_name', 'scope_source', 'count_expected', 'count_actual', 'items'] as $field) {
            if (! array_key_exists($field, $decoded)) {
                throw new RuntimeException(sprintf('First-wave blocked registry missing field [%s].', $field));
            }
        }

        if (! is_array($decoded['items'])) {
            throw new RuntimeException('First-wave blocked registry items must be an array.');
        }

        $manifest = $this->manifestReader->read();
        $manifestBySlug = [];
        foreach ($manifest['occupations'] as $occupation) {
            $manifestBySlug[(string) $occupation['canonical_slug']] = (string) $occupation['occupation_uuid'];
        }

        $expected = (int) $decoded['count_expected'];
        $actual = (int) $decoded['count_actual'];
        if ($expected !== $actual || $actual !== count($decoded['items'])) {
            throw new RuntimeException('First-wave blocked registry count metadata must match items length.');
        }

        $seenSlugs = [];
        foreach ($decoded['items'] as $index => $item) {
            if (! is_array($item)) {
                throw new RuntimeException(sprintf('First-wave blocked registry item at index [%d] is invalid.', $index));
            }

            foreach (['canonical_slug', 'occupation_uuid', 'blocker_type', 'override_eligible', 'remediation_class', 'notes'] as $field) {
                if (! array_key_exists($field, $item)) {
                    throw new RuntimeException(sprintf('First-wave blocked registry item [%d] missing [%s].', $index, $field));
                }
            }

            $slug = trim((string) $item['canonical_slug']);
            if ($slug === '' || ! isset($manifestBySlug[$slug])) {
                throw new RuntimeException(sprintf('First-wave blocked registry item [%d] contains out-of-scope slug [%s].', $index, $slug));
            }
            if (isset($seenSlugs[$slug])) {
                throw new RuntimeException(sprintf('First-wave blocked registry contains duplicate slug [%s].', $slug));
            }

            if ((string) $item['occupation_uuid'] !== $manifestBySlug[$slug]) {
                throw new RuntimeException(sprintf('First-wave blocked registry occupation UUID mismatch for slug [%s].', $slug));
            }

            $blockerType = (string) $item['blocker_type'];
            if (! in_array($blockerType, ['missing_crosswalk_source_code', 'source_row_missing'], true)) {
                throw new RuntimeException(sprintf('First-wave blocked registry blocker type [%s] is not supported.', $blockerType));
            }

            $remediationClass = (string) $item['remediation_class'];
            if (! in_array($remediationClass, ['authority_override_possible', 'not_safely_remediable'], true)) {
                throw new RuntimeException(sprintf('First-wave blocked registry remediation class [%s] is not supported.', $remediationClass));
            }

            if (! is_bool($item['override_eligible'])) {
                throw new RuntimeException(sprintf('First-wave blocked registry override_eligible must be boolean for slug [%s].', $slug));
            }

            if (! is_array($item['notes'])) {
                throw new RuntimeException(sprintf('First-wave blocked registry notes must be an array for slug [%s].', $slug));
            }

            $seenSlugs[$slug] = true;
        }

        return $decoded;
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    public function bySlug(?string $path = null): array
    {
        $registry = $this->read($path);

        $indexed = [];
        foreach ($registry['items'] as $item) {
            $indexed[(string) $item['canonical_slug']] = $item;
        }

        return $indexed;
    }
}
