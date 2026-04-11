<?php

declare(strict_types=1);

namespace App\Domain\Career\Import;

use App\Models\CareerImportRun;
use App\Models\OccupationFamily;
use RuntimeException;

final class FirstWaveFamilyAliasPolicy
{
    /**
     * @var array<string, array{
     *   family_slug:string,
     *   approved_family_alias_rows:list<array<string,mixed>>,
     *   blocked_aliases:list<string>
     * }>|null
     */
    private ?array $catalog = null;

    public function defaultPath(): string
    {
        return base_path('docs/career/first_wave_family_aliases.json');
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
            throw new RuntimeException(sprintf('First-wave family alias catalog not found at [%s].', $resolved));
        }

        $decoded = json_decode((string) file_get_contents($resolved), true);
        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('First-wave family alias catalog is not valid JSON: [%s].', $resolved));
        }

        foreach (['version', 'wave_name', 'scope_source', 'count_expected', 'count_actual', 'policy', 'items'] as $field) {
            if (! array_key_exists($field, $decoded)) {
                throw new RuntimeException(sprintf('First-wave family alias catalog missing field [%s].', $field));
            }
        }

        if (! is_array($decoded['policy']) || ! is_array($decoded['items'])) {
            throw new RuntimeException('First-wave family alias catalog policy and items must be arrays.');
        }

        $expected = (int) $decoded['count_expected'];
        $actual = (int) $decoded['count_actual'];
        if ($expected !== $actual || count($decoded['items']) !== $actual) {
            throw new RuntimeException('First-wave family alias catalog count metadata must match items length.');
        }

        $seenFamilySlugs = [];
        foreach ($decoded['items'] as $index => $item) {
            if (! is_array($item)) {
                throw new RuntimeException(sprintf('First-wave family alias catalog item at index [%d] is invalid.', $index));
            }

            foreach (['family_slug', 'approved_family_alias_rows', 'blocked_aliases'] as $field) {
                if (! array_key_exists($field, $item)) {
                    throw new RuntimeException(sprintf('First-wave family alias catalog item [%d] missing [%s].', $index, $field));
                }
            }

            $familySlug = trim((string) $item['family_slug']);
            if ($familySlug === '') {
                throw new RuntimeException(sprintf('First-wave family alias catalog item [%d] contains blank family_slug.', $index));
            }
            if (isset($seenFamilySlugs[$familySlug])) {
                throw new RuntimeException(sprintf('First-wave family alias catalog contains duplicate family slug [%s].', $familySlug));
            }
            $seenFamilySlugs[$familySlug] = true;

            if (! is_array($item['approved_family_alias_rows']) || ! is_array($item['blocked_aliases'])) {
                throw new RuntimeException(sprintf('First-wave family alias catalog item [%s] must contain alias arrays.', $familySlug));
            }

            foreach ($item['approved_family_alias_rows'] as $aliasIndex => $aliasRow) {
                if (! is_array($aliasRow)) {
                    throw new RuntimeException(sprintf('First-wave family alias row [%s:%d] is invalid.', $familySlug, $aliasIndex));
                }

                foreach (['alias', 'normalized', 'lang', 'register', 'precision', 'confidence'] as $field) {
                    if (! array_key_exists($field, $aliasRow)) {
                        throw new RuntimeException(sprintf('First-wave family alias row [%s:%d] missing [%s].', $familySlug, $aliasIndex, $field));
                    }
                }

                if (trim((string) $aliasRow['alias']) === '' || trim((string) $aliasRow['normalized']) === '') {
                    throw new RuntimeException(sprintf('First-wave family alias row [%s:%d] contains blank alias fields.', $familySlug, $aliasIndex));
                }
            }

            foreach ($item['blocked_aliases'] as $blockedIndex => $blockedAlias) {
                if (trim((string) $blockedAlias) === '') {
                    throw new RuntimeException(sprintf('First-wave family blocked alias [%s:%d] must not be blank.', $familySlug, $blockedIndex));
                }
            }
        }

        return $decoded;
    }

    /**
     * @return array<string, array{
     *   family_slug:string,
     *   approved_family_alias_rows:list<array<string,mixed>>,
     *   blocked_aliases:list<string>
     * }>
     */
    public function byFamilySlug(?string $path = null): array
    {
        $catalog = $this->read($path);

        $indexed = [];
        foreach ($catalog['items'] as $item) {
            $indexed[(string) $item['family_slug']] = $item;
        }

        return $indexed;
    }

    /**
     * @return array{
     *   in_scope:bool,
     *   blocked_aliases:list<string>,
     *   alias_payloads:list<array<string,mixed>>
     * }
     */
    public function resolveFamilyAliasPayloads(
        OccupationFamily $family,
        CareerImportRun $importRun,
    ): array {
        $entry = $this->catalog()[$family->canonical_slug] ?? null;
        if (! is_array($entry)) {
            return [
                'in_scope' => false,
                'blocked_aliases' => [],
                'alias_payloads' => [],
            ];
        }

        $blockedAliases = array_values(array_filter(array_map(
            fn (mixed $alias): string => $this->normalizeText($alias),
            $entry['blocked_aliases'] ?? [],
        )));
        $blockedSet = array_fill_keys($blockedAliases, true);

        $payloads = [];
        $seen = [];

        foreach ($entry['approved_family_alias_rows'] as $row) {
            $alias = trim((string) ($row['alias'] ?? ''));
            $normalized = trim((string) ($row['normalized'] ?? ''));
            $lang = trim((string) ($row['lang'] ?? ''));

            if ($alias === '' || $normalized === '' || $lang === '') {
                continue;
            }

            $aliasKey = $this->normalizeText($alias);
            if ($aliasKey === '' || isset($blockedSet[$aliasKey])) {
                continue;
            }

            $dedupeKey = sprintf('%s|%s', strtolower($lang), $this->normalizeText($normalized));
            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $payloads[] = [
                'occupation_id' => null,
                'family_id' => $family->id,
                'alias' => $alias,
                'normalized' => $this->normalizeText($normalized),
                'lang' => $lang,
                'register' => (string) ($row['register'] ?? 'family_alias'),
                'intent_scope' => 'exact',
                'target_kind' => 'family',
                'precision_score' => (float) ($row['precision'] ?? 1.0),
                'confidence_score' => (float) ($row['confidence'] ?? 1.0),
                'seniority_hint' => null,
                'function_hint' => null,
                'import_run_id' => $importRun->id,
                'row_fingerprint' => null,
            ];

            $seen[$dedupeKey] = true;
        }

        return [
            'in_scope' => true,
            'blocked_aliases' => $blockedAliases,
            'alias_payloads' => $payloads,
        ];
    }

    /**
     * @return array<string, array{
     *   family_slug:string,
     *   approved_family_alias_rows:list<array<string,mixed>>,
     *   blocked_aliases:list<string>
     * }>
     */
    private function catalog(): array
    {
        if ($this->catalog === null) {
            $this->catalog = $this->byFamilySlug();
        }

        return $this->catalog;
    }

    private function normalizeText(mixed $value): string
    {
        return mb_strtolower(trim((string) $value), 'UTF-8');
    }
}
