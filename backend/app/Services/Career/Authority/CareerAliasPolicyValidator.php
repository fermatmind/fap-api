<?php

declare(strict_types=1);

namespace App\Services\Career\Authority;

final class CareerAliasPolicyValidator
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{
     *   ambiguous_aliases:list<array{normalized_alias:string,slugs:list<string>}>,
     *   blocked_alias_materialized:list<array{slug:string,alias:string}>,
     *   canonical_slug_issues:list<array{slug:string,reason:string}>,
     *   policy_ok:bool
     * }
     */
    public function validateCatalog(array $rows): array
    {
        $aliasesByNormalized = [];
        $blockedMaterialized = [];
        $canonicalIssues = [];

        foreach ($rows as $row) {
            $slug = $this->slug($row['canonical_slug'] ?? $row['slug'] ?? null);
            if ($slug === '') {
                $canonicalIssues[] = ['slug' => '', 'reason' => 'missing_canonical_slug'];

                continue;
            }

            if ($slug !== $this->slug($slug)) {
                $canonicalIssues[] = ['slug' => $slug, 'reason' => 'canonical_slug_not_normalized'];
            }

            foreach ($this->aliases($row['aliases'] ?? []) as $alias) {
                $aliasesByNormalized[$alias][$slug] = true;
            }

            foreach ($this->aliases($row['blocked_aliases'] ?? []) as $blockedAlias) {
                if (isset($aliasesByNormalized[$blockedAlias][$slug])) {
                    $blockedMaterialized[] = ['slug' => $slug, 'alias' => $blockedAlias];
                }
            }
        }

        $ambiguous = [];
        foreach ($aliasesByNormalized as $alias => $slugs) {
            $slugList = array_keys($slugs);
            sort($slugList);
            if (count($slugList) > 1) {
                $ambiguous[] = [
                    'normalized_alias' => $alias,
                    'slugs' => $slugList,
                ];
            }
        }

        return [
            'ambiguous_aliases' => $ambiguous,
            'blocked_alias_materialized' => $blockedMaterialized,
            'canonical_slug_issues' => $canonicalIssues,
            'policy_ok' => $ambiguous === [] && $blockedMaterialized === [] && $canonicalIssues === [],
        ];
    }

    /**
     * @return list<string>
     */
    private function aliases(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $aliases = [];
        foreach ($value as $item) {
            $alias = is_array($item) ? ($item['normalized'] ?? $item['alias'] ?? null) : $item;
            $normalized = $this->slug($alias);
            if ($normalized !== '') {
                $aliases[] = $normalized;
            }
        }

        return array_values(array_unique($aliases));
    }

    private function slug(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? '';

        return trim($normalized, '-');
    }
}
