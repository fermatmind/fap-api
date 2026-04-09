<?php

declare(strict_types=1);

namespace App\Domain\Career\Import;

use App\Domain\Career\Publish\FirstWaveManifestReader;
use RuntimeException;

final class FirstWaveAliasCatalogReader
{
    public function __construct(
        private readonly FirstWaveManifestReader $manifestReader,
    ) {}

    public function defaultPath(): string
    {
        return base_path('docs/career/first_wave_aliases.json');
    }

    /**
     * @return array{
     *   version:string,
     *   wave_name:string,
     *   scope_source:string,
     *   count_expected:int,
     *   count_actual:int,
     *   policy:array<string,mixed>,
     *   items:list<array<string,mixed>>
     * }
     */
    public function read(?string $path = null): array
    {
        $resolved = $path === null || trim($path) === ''
            ? $this->defaultPath()
            : (str_starts_with($path, '/') ? $path : base_path($path));

        if (! is_file($resolved)) {
            throw new RuntimeException(sprintf('First-wave alias catalog not found at [%s].', $resolved));
        }

        $decoded = json_decode((string) file_get_contents($resolved), true);
        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('First-wave alias catalog is not valid JSON: [%s].', $resolved));
        }

        foreach (['version', 'wave_name', 'scope_source', 'count_expected', 'count_actual', 'policy', 'items'] as $field) {
            if (! array_key_exists($field, $decoded)) {
                throw new RuntimeException(sprintf('First-wave alias catalog missing field [%s].', $field));
            }
        }

        if (! is_array($decoded['policy']) || ! is_array($decoded['items'])) {
            throw new RuntimeException('First-wave alias catalog policy and items must be arrays.');
        }

        $manifest = $this->manifestReader->read();
        $manifestSlugs = array_values(array_map(
            static fn (array $occupation): string => (string) $occupation['canonical_slug'],
            $manifest['occupations']
        ));
        sort($manifestSlugs);

        $expected = (int) $decoded['count_expected'];
        $actual = (int) $decoded['count_actual'];
        if ($expected !== 10 || $actual !== 10 || count($decoded['items']) !== 10) {
            throw new RuntimeException('First-wave alias catalog must contain exactly 10 items.');
        }

        $seenSlugs = [];
        $catalogSlugs = [];

        foreach ($decoded['items'] as $index => $item) {
            if (! is_array($item)) {
                throw new RuntimeException(sprintf('First-wave alias catalog item at index [%d] is invalid.', $index));
            }

            foreach (['canonical_slug', 'approved_alias_rows', 'blocked_aliases'] as $field) {
                if (! array_key_exists($field, $item)) {
                    throw new RuntimeException(sprintf('First-wave alias catalog item [%d] missing [%s].', $index, $field));
                }
            }

            $slug = trim((string) $item['canonical_slug']);
            if ($slug === '') {
                throw new RuntimeException(sprintf('First-wave alias catalog item [%d] contains blank slug.', $index));
            }
            if (isset($seenSlugs[$slug])) {
                throw new RuntimeException(sprintf('First-wave alias catalog contains duplicate slug [%s].', $slug));
            }

            if (! is_array($item['approved_alias_rows']) || ! is_array($item['blocked_aliases'])) {
                throw new RuntimeException(sprintf('First-wave alias catalog item [%s] must contain alias arrays.', $slug));
            }

            $catalogSlugs[] = $slug;
            $seenSlugs[$slug] = true;

            foreach ($item['approved_alias_rows'] as $aliasIndex => $aliasRow) {
                if (! is_array($aliasRow)) {
                    throw new RuntimeException(sprintf('First-wave alias row [%s:%d] is invalid.', $slug, $aliasIndex));
                }

                foreach (['alias', 'normalized', 'lang', 'register', 'precision', 'confidence'] as $field) {
                    if (! array_key_exists($field, $aliasRow)) {
                        throw new RuntimeException(sprintf('First-wave alias row [%s:%d] missing [%s].', $slug, $aliasIndex, $field));
                    }
                }

                if (trim((string) $aliasRow['alias']) === '' || trim((string) $aliasRow['normalized']) === '') {
                    throw new RuntimeException(sprintf('First-wave alias row [%s:%d] contains blank alias fields.', $slug, $aliasIndex));
                }
            }

            foreach ($item['blocked_aliases'] as $blockedIndex => $blockedAlias) {
                if (trim((string) $blockedAlias) === '') {
                    throw new RuntimeException(sprintf('First-wave blocked alias [%s:%d] must not be blank.', $slug, $blockedIndex));
                }
            }
        }

        sort($catalogSlugs);
        if ($catalogSlugs !== $manifestSlugs) {
            throw new RuntimeException('First-wave alias catalog slugs must exactly match the first-wave manifest scope.');
        }

        return $decoded;
    }

    /**
     * @return array<string, array{canonical_slug:string,approved_alias_rows:list<array<string,mixed>>,blocked_aliases:list<string>}>
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
