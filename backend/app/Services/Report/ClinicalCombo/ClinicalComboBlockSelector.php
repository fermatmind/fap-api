<?php

declare(strict_types=1);

namespace App\Services\Report\ClinicalCombo;

final class ClinicalComboBlockSelector
{
    /**
     * @param list<array<string,mixed>> $allBlocks
     * @param list<string> $allowedAccessLevels
     * @param array<string,mixed> $context
     * @param array<string,mixed> $policy
     * @return list<array<string,mixed>>
     */
    public function select(
        array $allBlocks,
        string $sectionKey,
        string $locale,
        array $allowedAccessLevels,
        int $minBlocks,
        int $maxBlocks,
        array $context,
        array $policy
    ): array {
        if ($maxBlocks <= 0 || $allowedAccessLevels === []) {
            return [];
        }

        $allowedAccessSet = [];
        foreach ($allowedAccessLevels as $level) {
            $normalized = strtolower(trim((string) $level));
            if ($normalized === '') {
                continue;
            }
            $allowedAccessSet[$normalized] = true;
        }
        if ($allowedAccessSet === []) {
            return [];
        }

        $selected = [];
        foreach ($this->localeCandidates($locale) as $candidateLocale) {
            $rows = [];
            foreach ($allBlocks as $row) {
                if (!is_array($row)) {
                    continue;
                }

                if (strtolower(trim((string) ($row['section'] ?? ''))) !== strtolower(trim($sectionKey))) {
                    continue;
                }

                $rowLocale = $this->normalizeLocale((string) ($row['locale'] ?? 'zh-CN'));
                if ($rowLocale !== $candidateLocale) {
                    continue;
                }

                $accessLevel = strtolower(trim((string) ($row['access_level'] ?? 'free')));
                if (!isset($allowedAccessSet[$accessLevel])) {
                    continue;
                }

                if (!$this->matchesConditions($row, $context, $policy)) {
                    continue;
                }

                $rows[] = $row;
            }

            if ($rows !== []) {
                usort($rows, fn (array $a, array $b): int => $this->comparePriority($a, $b));
                $selected = $this->enforceExclusiveGroup($rows);
                break;
            }
        }

        if ($selected === []) {
            return [];
        }

        if ($maxBlocks > 0 && count($selected) > $maxBlocks) {
            $selected = array_slice($selected, 0, $maxBlocks);
        }

        if ($minBlocks > 0 && count($selected) < $minBlocks) {
            return $selected;
        }

        return $selected;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $context
     * @param array<string,mixed> $policy
     */
    private function matchesConditions(array $row, array $context, array $policy): bool
    {
        $conditions = is_array($row['conditions'] ?? null) ? $row['conditions'] : [];
        if ($conditions === []) {
            return true;
        }

        $contentRules = is_array($policy['content_condition_rules'] ?? null) ? $policy['content_condition_rules'] : [];
        $logic = strtolower(trim((string) ($contentRules['logic_default'] ?? 'all')));
        if (!in_array($logic, ['all', 'any'], true)) {
            $logic = 'all';
        }

        $results = [];
        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                $results[] = false;
                continue;
            }
            $results[] = $this->matchesCondition($condition, $context, $policy);
        }

        if ($results === []) {
            return true;
        }

        if ($logic === 'any') {
            return in_array(true, $results, true);
        }

        return !in_array(false, $results, true);
    }

    /**
     * @param array<string,mixed> $condition
     * @param array<string,mixed> $context
     * @param array<string,mixed> $policy
     */
    private function matchesCondition(array $condition, array $context, array $policy): bool
    {
        $path = trim((string) ($condition['path'] ?? ''));
        $op = strtolower(trim((string) ($condition['op'] ?? 'eq')));
        $expected = $condition['value'] ?? null;

        if ($path === '' || $op === '') {
            return false;
        }

        $contentRules = is_array($policy['content_condition_rules'] ?? null) ? $policy['content_condition_rules'] : [];
        $allowedOps = array_values(array_unique(array_map(static fn ($v): string => strtolower(trim((string) $v)), (array) ($contentRules['allowed_ops'] ?? []))));
        if ($allowedOps !== [] && !in_array($op, $allowedOps, true)) {
            return false;
        }

        $allowedPaths = array_values(array_unique(array_map(static fn ($v): string => trim((string) $v), (array) ($contentRules['allowed_paths'] ?? []))));
        if ($allowedPaths !== [] && !in_array($path, $allowedPaths, true)) {
            return false;
        }

        $actual = data_get($context, $path);

        return match ($op) {
            'eq' => $this->opEq($actual, $expected, $path, $policy),
            'in' => $this->opIn($actual, $expected, $path, $policy),
            'contains' => $this->opContains($actual, $expected),
            'gte' => $this->opGte($actual, $expected),
            'lte' => $this->opLte($actual, $expected),
            default => false,
        };
    }

    private function opEq(mixed $actual, mixed $expected, string $path, array $policy): bool
    {
        if (is_string($expected)) {
            $expanded = $this->expandAliasValues($path, [$expected], $policy);
            $actualStr = is_scalar($actual) ? (string) $actual : null;
            if ($actualStr === null) {
                return false;
            }

            foreach ($expanded as $candidate) {
                if ((string) $candidate === $actualStr) {
                    return true;
                }
            }

            return false;
        }

        if (is_bool($expected)) {
            return (bool) $actual === $expected;
        }

        if (is_numeric($expected) && is_numeric($actual)) {
            return (float) $actual === (float) $expected;
        }

        return $actual === $expected;
    }

    private function opIn(mixed $actual, mixed $expected, string $path, array $policy): bool
    {
        if (!is_array($expected)) {
            return false;
        }

        $expanded = $this->expandAliasValues($path, $expected, $policy);
        if ($expanded === []) {
            return false;
        }

        if (is_array($actual)) {
            foreach ($actual as $actualItem) {
                foreach ($expanded as $candidate) {
                    if ((string) $actualItem === (string) $candidate) {
                        return true;
                    }
                }
            }

            return false;
        }

        foreach ($expanded as $candidate) {
            if ((string) $actual === (string) $candidate) {
                return true;
            }
        }

        return false;
    }

    private function opContains(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            foreach ($actual as $item) {
                if ((string) $item === (string) $expected) {
                    return true;
                }
            }

            return false;
        }

        if (is_string($actual)) {
            $needle = (string) $expected;
            if ($needle === '') {
                return false;
            }

            return str_contains($actual, $needle);
        }

        return false;
    }

    private function opGte(mixed $actual, mixed $expected): bool
    {
        if (!is_numeric($actual) || !is_numeric($expected)) {
            return false;
        }

        return (float) $actual >= (float) $expected;
    }

    private function opLte(mixed $actual, mixed $expected): bool
    {
        if (!is_numeric($actual) || !is_numeric($expected)) {
            return false;
        }

        return (float) $actual <= (float) $expected;
    }

    /**
     * @param list<mixed> $values
     * @param array<string,mixed> $policy
     * @return list<string>
     */
    private function expandAliasValues(string $path, array $values, array $policy): array
    {
        $aliases = is_array(data_get($policy, 'content_aliases.levels')) ? data_get($policy, 'content_aliases.levels') : [];

        $dimension = null;
        if (preg_match('/^scores\.([a-z_]+)\.level$/i', $path, $matches) === 1) {
            $dimension = strtolower(trim((string) ($matches[1] ?? '')));
        }

        $dimAliases = is_array($aliases[$dimension] ?? null) ? $aliases[$dimension] : [];
        $expanded = [];
        foreach ($values as $value) {
            $valueStr = trim((string) $value);
            if ($valueStr === '') {
                continue;
            }
            if (is_array($dimAliases[$valueStr] ?? null)) {
                foreach ((array) $dimAliases[$valueStr] as $aliasValue) {
                    $aliasStr = trim((string) $aliasValue);
                    if ($aliasStr !== '') {
                        $expanded[] = $aliasStr;
                    }
                }
                continue;
            }
            $expanded[] = $valueStr;
        }

        return array_values(array_unique($expanded));
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function enforceExclusiveGroup(array $rows): array
    {
        $out = [];
        $seen = [];

        foreach ($rows as $row) {
            $group = trim((string) ($row['exclusive_group'] ?? ''));
            $key = $group !== '' ? $group : '__' . (string) ($row['block_id'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    private function comparePriority(array $a, array $b): int
    {
        $priorityCmp = ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0));
        if ($priorityCmp !== 0) {
            return $priorityCmp;
        }

        return strcmp((string) ($a['block_id'] ?? ''), (string) ($b['block_id'] ?? ''));
    }

    /**
     * @return list<string>
     */
    private function localeCandidates(string $locale): array
    {
        $resolved = $this->normalizeLocale($locale);
        if ($resolved === 'zh-CN') {
            return ['zh-CN'];
        }

        return [$resolved, 'zh-CN'];
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = strtolower(trim($locale));
        if (str_starts_with($locale, 'zh')) {
            return 'zh-CN';
        }
        if (str_starts_with($locale, 'en')) {
            return 'en';
        }

        return 'zh-CN';
    }
}
