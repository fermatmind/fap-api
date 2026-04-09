<?php

declare(strict_types=1);

namespace App\Domain\Career\Import;

use App\Domain\Career\Publish\FirstWaveManifestReader;
use RuntimeException;

final class FirstWaveAuthorityOverrideReader
{
    public function __construct(
        private readonly FirstWaveManifestReader $manifestReader,
    ) {}

    public function defaultPath(): string
    {
        return base_path('docs/career/first_wave_authority_overrides.json');
    }

    /**
     * @return array{
     *   version:string,
     *   wave_name:string,
     *   scope_source:string,
     *   count_expected:int,
     *   count_actual:int,
     *   policy:array{supported_fields:list<string>},
     *   items:list<array<string,mixed>>
     * }
     */
    public function read(?string $path = null): array
    {
        $resolved = $path === null || trim($path) === ''
            ? $this->defaultPath()
            : (str_starts_with($path, '/') ? $path : base_path($path));

        if (! is_file($resolved)) {
            throw new RuntimeException(sprintf('First-wave authority overrides not found at [%s].', $resolved));
        }

        $decoded = json_decode((string) file_get_contents($resolved), true);
        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('First-wave authority overrides are not valid JSON: [%s].', $resolved));
        }

        foreach (['version', 'wave_name', 'scope_source', 'count_expected', 'count_actual', 'policy', 'items'] as $field) {
            if (! array_key_exists($field, $decoded)) {
                throw new RuntimeException(sprintf('First-wave authority overrides missing field [%s].', $field));
            }
        }

        if (! is_array($decoded['policy']) || ! is_array($decoded['items'])) {
            throw new RuntimeException('First-wave authority overrides policy and items must be arrays.');
        }

        $supportedFields = $decoded['policy']['supported_fields'] ?? null;
        if (! is_array($supportedFields) || $supportedFields === []) {
            throw new RuntimeException('First-wave authority overrides must declare supported_fields.');
        }

        $supportedFields = array_values(array_unique(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $supportedFields
        )));

        if ($supportedFields !== ['crosswalk_source_code']) {
            throw new RuntimeException('First-wave authority overrides may support only [crosswalk_source_code].');
        }

        $manifest = $this->manifestReader->read();
        $manifestBySlug = [];
        foreach ($manifest['occupations'] as $occupation) {
            $manifestBySlug[(string) $occupation['canonical_slug']] = (string) $occupation['occupation_uuid'];
        }

        $expected = (int) $decoded['count_expected'];
        $actual = (int) $decoded['count_actual'];
        if ($expected !== $actual || $actual !== count($decoded['items'])) {
            throw new RuntimeException('First-wave authority override count metadata must match items length.');
        }

        $seenSlugs = [];
        foreach ($decoded['items'] as $index => $item) {
            if (! is_array($item)) {
                throw new RuntimeException(sprintf('First-wave authority override item at index [%d] is invalid.', $index));
            }

            foreach (['canonical_slug', 'occupation_uuid', 'overrides'] as $field) {
                if (! array_key_exists($field, $item)) {
                    throw new RuntimeException(sprintf('First-wave authority override item [%d] missing [%s].', $index, $field));
                }
            }

            $slug = trim((string) $item['canonical_slug']);
            if ($slug === '' || ! isset($manifestBySlug[$slug])) {
                throw new RuntimeException(sprintf('First-wave authority override item [%d] contains out-of-scope slug [%s].', $index, $slug));
            }
            if (isset($seenSlugs[$slug])) {
                throw new RuntimeException(sprintf('First-wave authority overrides contain duplicate slug [%s].', $slug));
            }
            if ((string) $item['occupation_uuid'] !== $manifestBySlug[$slug]) {
                throw new RuntimeException(sprintf('First-wave authority override occupation UUID mismatch for slug [%s].', $slug));
            }

            if (! is_array($item['overrides'])) {
                throw new RuntimeException(sprintf('First-wave authority override item [%s] must contain an overrides object.', $slug));
            }

            $overrideKeys = array_keys($item['overrides']);
            foreach ($overrideKeys as $overrideKey) {
                if (! in_array((string) $overrideKey, $supportedFields, true)) {
                    throw new RuntimeException(sprintf('First-wave authority override field [%s] is not supported for slug [%s].', $overrideKey, $slug));
                }
            }

            foreach ($item['overrides'] as $overrideKey => $overrideValue) {
                if ($overrideValue !== null && trim((string) $overrideValue) === '') {
                    throw new RuntimeException(sprintf('First-wave authority override field [%s] must not be blank for slug [%s].', $overrideKey, $slug));
                }
            }

            $seenSlugs[$slug] = true;
        }

        $decoded['policy']['supported_fields'] = $supportedFields;

        return $decoded;
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    public function bySlug(?string $path = null): array
    {
        $catalog = $this->read($path);

        $indexed = [];
        foreach ($catalog['items'] as $item) {
            $indexed[(string) $item['canonical_slug']] = $item;
        }

        return $indexed;
    }
}
